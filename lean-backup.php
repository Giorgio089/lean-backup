<?php
/**
 * Plugin Name: Lean Backup
 * Plugin URI:  https://example.com/lean-backup
 * Description: Zero-bloat, high-performance backup for WordPress. Streams backups directly to S3 without local ZIP clutter.
 * Version:     0.1.0
 * Author:      Lean-Family
 * Text Domain: lean-backup
 * License:     GPL-2.0+
 */

namespace LeanFamily\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Lean Backup Class
 */
class Core {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		require_once __DIR__ . '/includes/class-s3-signer.php';
		require_once __DIR__ . '/includes/class-worker.php';
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_lean_backup_run', [ $this, 'handle_manual_backup' ] );
		add_action( 'lean_backup_async_run', [ $this, 'run_backup' ] );
	}

	/**
	 * Handle manual backup trigger from admin
	 */
	public function handle_manual_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'lean-backup' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'lean_backup_run_action', 'lean_backup_run_nonce' );

		// Schedule background task to avoid timeout
		wp_schedule_single_event( time(), 'lean_backup_async_run' );

		wp_redirect( admin_url( 'tools.php?page=lean-backup&status=started' ) );
		exit;
	}

	/**
	 * The actual backup execution (called via cron)
	 */
	public function run_backup() {
		$worker = new Worker();
		$worker->run();
	}

	/**
	 * Register the minimalist menu under "Tools"
	 */
	public function register_menu() {
		add_management_page(
			__( 'Lean Backup', 'lean-backup' ),
			__( 'Lean Backup', 'lean-backup' ),
			'manage_options',
			'lean-backup',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register settings using native WP Settings API
	 */
	public function register_settings() {
		register_setting( 'lean_backup_options', 'lean_backup_s3_settings', [
			'sanitize_callback' => [ $this, 'sanitize_s3_settings' ],
		] );

		add_settings_section(
			'lean_backup_s3_section',
			__( 'S3 Storage Settings', 'lean-backup' ),
			null,
			'lean-backup'
		);

		$fields = [
			'endpoint'   => __( 'Endpoint (e.g. s3.amazonaws.com)', 'lean-backup' ),
			'region'     => __( 'Region', 'lean-backup' ),
			'bucket'     => __( 'Bucket Name', 'lean-backup' ),
			'access_key' => __( 'Access Key ID', 'lean-backup' ),
			'secret_key' => __( 'Secret Access Key', 'lean-backup' ),
			'retention'  => __( 'Backups to Keep', 'lean-backup' ),
			'interval'   => __( 'Backup Interval', 'lean-backup' ),
		];

		foreach ( $fields as $id => $title ) {
			add_settings_field(
				"lean_backup_s3_{$id}",
				$title,
				[ $this, 'render_field' ],
				'lean-backup',
				'lean_backup_s3_section',
				[ 
					'label_for' => $id, 
					'type'      => ( strpos( $id, 'secret' ) !== false ) ? 'password' : ( $id === 'interval' ? 'select' : 'text' ) 
				]
			);
		}
	}

	/**
	 * Logic to handle Cron Scheduling on settings save
	 */
	public function sanitize_s3_settings( $input ) {
		$sanitized = [];

		foreach ( $input as $key => $val ) {
			// Secret/access keys may contain +, /, = — sanitize_text_field() is safe
			// for these chars but trim + unslash is more explicit for credential fields.
			if ( in_array( $key, [ 'access_key', 'secret_key' ], true ) ) {
				$sanitized[ $key ] = trim( wp_unslash( (string) $val ) );
			} else {
				$sanitized[ $key ] = sanitize_text_field( $val );
			}
		}

		// Whitelist interval to prevent arbitrary cron hook names.
		$allowed_intervals        = [ 'none', 'daily', 'weekly' ];
		$sanitized['interval']    = in_array( $sanitized['interval'] ?? '', $allowed_intervals, true )
			? $sanitized['interval']
			: 'none';

		// Handle Cron Schedule
		$old_settings = get_option( 'lean_backup_s3_settings' );
		$new_interval = $sanitized['interval'];
		$old_interval = $old_settings['interval'] ?? 'none';

		if ( $new_interval !== $old_interval ) {
			wp_clear_scheduled_hook( 'lean_backup_async_run' );
			if ( $new_interval !== 'none' ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, $new_interval, 'lean_backup_async_run' );
			}
		}

		return $sanitized;
	}

	public function render_field( $args ) {
		$options = get_option( 'lean_backup_s3_settings' );
		$id      = $args['label_for'];
		$value   = isset( $options[ $id ] ) ? $options[ $id ] : '';
		$type    = $args['type'];

		// Check if credentials are overridden by wp-config.php constants
		$is_disabled = false;
		if ( 'access_key' === $id && defined( 'LEAN_BACKUP_S3_KEY' ) ) {
			$value       = '******** (Defined in wp-config.php)';
			$is_disabled = true;
			$type        = 'text';
		} elseif ( 'secret_key' === $id && defined( 'LEAN_BACKUP_S3_SECRET' ) ) {
			$value       = '******** (Defined in wp-config.php)';
			$is_disabled = true;
			$type        = 'text';
		}

		if ( 'select' === $type ) {
			echo '<select name="lean_backup_s3_settings[' . esc_attr( $id ) . ']" id="' . esc_attr( $id ) . '">';
			echo '<option value="none" ' . selected( $value, 'none', false ) . '>' . __( 'Disabled (Manual only)', 'lean-backup' ) . '</option>';
			echo '<option value="daily" ' . selected( $value, 'daily', false ) . '>' . __( 'Daily', 'lean-backup' ) . '</option>';
			echo '<option value="weekly" ' . selected( $value, 'weekly', false ) . '>' . __( 'Weekly', 'lean-backup' ) . '</option>';
			echo '</select>';
		} else {
			echo sprintf(
				'<input type="%s" name="%s" id="%s" value="%s" class="regular-text" %s>',
				esc_attr( $type ),
				$is_disabled ? '' : 'lean_backup_s3_settings[' . esc_attr( $id ) . ']',
				esc_attr( $id ),
				esc_attr( $value ),
				$is_disabled ? 'disabled' : ''
			);
		}
	}

	public function render_settings_page() {
		$status       = $_GET['status'] ?? '';
		$last_success = get_option( 'lean_backup_last_success' );
		
		// System Checks
		$requirements = [
			'ZipArchive' => class_exists( 'ZipArchive' ),
			'SimpleXML'  => function_exists( 'simplexml_load_string' ),
			'Hash'       => function_exists( 'hash_hmac' ),
		];
		$missing = array_keys( array_filter( $requirements, function($v) { return !$v; } ) );

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php _e( 'High-performance, zero-bloat backups for your WordPress site.', 'lean-backup' ); ?></p>
			
			<?php if ( ! empty( $missing ) ) : ?>
				<div class="notice notice-error"><p><strong><?php _e( 'Warning:', 'lean-backup' ); ?></strong> <?php printf( __( 'The following PHP requirements are missing: %s. The plugin will not function correctly.', 'lean-backup' ), implode( ', ', $missing ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'started' === $status ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php _e( 'Backup process initiated in the background.', 'lean-backup' ); ?></p></div>
			<?php endif; ?>

			<div class="card">
				<h2><?php _e( 'Backup Status', 'lean-backup' ); ?></h2>
				<p>
					<strong><?php _e( 'Last Successful Backup:', 'lean-backup' ); ?></strong> 
					<?php echo $last_success ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_success ) : __( 'Never', 'lean-backup' ); ?>
				</p>
				<form action="<?php echo admin_url( 'admin-post.php' ); ?>" method="post">
					<input type="hidden" name="action" value="lean_backup_run">
					<?php wp_nonce_field( 'lean_backup_run_action', 'lean_backup_run_nonce' ); ?>
					<?php submit_button( __( 'Run Backup Now', 'lean-backup' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<hr>

			<div class="card">
				<h2><?php _e( 'S3 Storage Settings', 'lean-backup' ); ?></h2>
				<p class="description"><?php _e( 'For better security, it is highly recommended to define your credentials in <code>wp-config.php</code> using the following constants: <code>LEAN_BACKUP_S3_KEY</code> and <code>LEAN_BACKUP_S3_SECRET</code>.', 'lean-backup' ); ?></p>
				<form action="options.php" method="post">
					<?php
					settings_fields( 'lean_backup_options' );
					do_settings_sections( 'lean-backup' );
					submit_button();
					?>
				</form>
			</div>
		</div>
		<?php
	}
}

// Kick it off
Core::instance();

// Clear scheduled cron job on deactivation to prevent orphaned events.
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'lean_backup_async_run' );
} );
