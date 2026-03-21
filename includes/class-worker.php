<?php
namespace LeanFamily\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The core backup engine.
 */
class Worker {

	private $settings;
	private $temp_dir;

	public function __construct() {
		$this->settings = get_option( 'lean_backup_s3_settings', [] );

		// Override with constants if defined for better security.
		if ( defined( 'LEAN_BACKUP_S3_KEY' ) ) {
			$this->settings['access_key'] = LEAN_BACKUP_S3_KEY;
		}
		if ( defined( 'LEAN_BACKUP_S3_SECRET' ) ) {
			$this->settings['secret_key'] = LEAN_BACKUP_S3_SECRET;
		}

		// Use the OS temp dir — NOT the uploads folder which is web-accessible.
		// Each WP install gets its own subdirectory to avoid collisions.
		$this->temp_dir = rtrim( sys_get_temp_dir(), '/' ) . '/lean-backup-' . substr( md5( ABSPATH ), 0, 8 );

		if ( ! file_exists( $this->temp_dir ) ) {
			wp_mkdir_p( $this->temp_dir );
		}
	}

	public function run() {
		if ( empty( $this->settings['access_key'] ) ) {
			error_log( 'Lean Backup: S3 Settings missing.' );
			return;
		}

		$db_file  = null;
		$zip_file = null;

		try {
			$db_file  = $this->dump_database();
			$zip_file = $this->create_zip( $db_file );

			if ( $zip_file && $this->upload_to_s3( $zip_file ) ) {
				$this->cleanup_old_backups();
				update_option( 'lean_backup_last_success', time() );
			}
		} finally {
			// Always remove temp files — even on exception or partial failure.
			if ( $db_file && file_exists( $db_file ) ) {
				@unlink( $db_file );
			}
			if ( $zip_file && file_exists( $zip_file ) ) {
				@unlink( $zip_file );
			}
		}
	}

	private function dump_database() {
		global $wpdb;
		$file_path = $this->temp_dir . '/db-' . gmdate( 'Y-m-d-His' ) . '.sql';
		$handle    = fopen( $file_path, 'w' );

		$tables = $wpdb->get_col( 'SHOW TABLES' );

		foreach ( $tables as $table ) {
			// Backtick-escape table name to handle reserved words.
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_A );
			fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" . $create['Create Table'] . ";\n\n" );

			$count      = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			$batch_size = 500;

			for ( $i = 0; $i < $count; $i += $batch_size ) {
				$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$i}, {$batch_size}", ARRAY_A );
				foreach ( $rows as $row ) {
					// Handle NULL values correctly; wrap everything else in escaped single quotes.
					$values = array_map( function( $val ) use ( $wpdb ) {
						if ( $val === null ) {
							return 'NULL';
						}
						return "'" . $wpdb->_escape( $val ) . "'";
					}, array_values( $row ) );

					fwrite( $handle, "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n" );
				}
			}
			fwrite( $handle, "\n\n" );
		}

		fclose( $handle );
		return $file_path;
	}

	private function create_zip( $db_file ) {
		$zip_path = $this->temp_dir . '/backup-' . gmdate( 'Y-m-d-His' ) . '.zip';
		$zip      = new \ZipArchive();

		if ( $zip->open( $zip_path, \ZipArchive::CREATE ) !== true ) {
			return false;
		}

		$zip->addFile( $db_file, 'database.sql' );

		$content_path = WP_CONTENT_DIR;
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $content_path, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $files as $file ) {
			if ( ! $file->isDir() ) {
				$filePath = $file->getRealPath();
				$relativePath = substr( $filePath, strlen( $content_path ) + 1 );

				if ( strpos( $relativePath, 'cache/' ) === 0 || strpos( $relativePath, 'lean-backup-tmp' ) !== false ) {
					continue;
				}

				$zip->addFile( $filePath, 'wp-content/' . $relativePath );
			}
		}

		$zip->close();
		return $zip_path;
	}

	private function upload_to_s3( $file_path ) {
		$signer    = new S3_Signer( $this->settings );
		$file_name = basename( $file_path );
		$headers   = $signer->get_auth_headers( $file_name, 'PUT', $file_path );
		$endpoint  = "https://{$this->settings['bucket']}.{$this->settings['endpoint']}/$file_name";

		// Use cURL directly for true streaming — wp_remote_put() buffers the
		// entire file body in memory which can exhaust PHP's memory limit.
		$fp         = fopen( $file_path, 'r' );
		$file_size  = filesize( $file_path );

		$curl_headers = [];
		foreach ( $headers as $k => $v ) {
			$curl_headers[] = "$k: $v";
		}

		$ch = curl_init( $endpoint );
		curl_setopt_array( $ch, [
			CURLOPT_PUT            => true,
			CURLOPT_INFILE         => $fp,
			CURLOPT_INFILESIZE     => $file_size,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 600,
			CURLOPT_HTTPHEADER     => $curl_headers,
			CURLOPT_SSL_VERIFYPEER => true,
		] );

		curl_exec( $ch );
		$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		fclose( $fp );

		return $http_code === 200;
	}

	/**
	 * Delete old backups on S3
	 */
	private function cleanup_old_backups() {
		$limit = (int) ($this->settings['retention'] ?? 5);
		if ( $limit <= 0 ) return;

		$signer   = new S3_Signer( $this->settings );
		$headers  = $signer->get_auth_headers( '', 'GET' );
		$endpoint = "https://{$this->settings['bucket']}.{$this->settings['endpoint']}/";

		$response = wp_remote_get( $endpoint, [ 'headers' => $headers ] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return;
		}

		$xml = simplexml_load_string( wp_remote_retrieve_body( $response ) );
		if ( ! $xml ) return;

		$backups = [];
		foreach ( $xml->Contents as $content ) {
			$key = (string) $content->Key;
			if ( strpos( $key, 'backup-' ) === 0 ) {
				$backups[] = $key;
			}
		}

		// Sort: Newest first
		rsort( $backups );

		// Identify files to delete
		if ( count( $backups ) > $limit ) {
			$to_delete = array_slice( $backups, $limit );
			foreach ( $to_delete as $file_key ) {
				$del_headers = $signer->get_auth_headers( $file_key, 'DELETE' );
				wp_remote_request( $endpoint . $file_key, [
					'method'  => 'DELETE',
					'headers' => $del_headers,
				]);
			}
		}
	}
}
