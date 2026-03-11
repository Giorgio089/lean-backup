<?php
namespace LeanFamily\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight S3 Signature V4 Handler
 * No SDK required.
 */
class S3_Signer {

	private $access_key;
	private $secret_key;
	private $region;
	private $bucket;
	private $endpoint;

	public function __construct( $settings ) {
		$this->access_key = $settings['access_key'] ?? '';
		$this->secret_key = $settings['secret_key'] ?? '';
		$this->region     = $settings['region'] ?? 'us-east-1';
		$this->bucket     = $settings['bucket'] ?? '';
		$this->endpoint   = $settings['endpoint'] ?? 's3.amazonaws.com';
	}

	/**
	 * Generate headers for S3 requests (PUT, GET, DELETE)
	 */
	public function get_auth_headers( $file_name, $method = 'PUT', $file_path = null ) {
		$service   = 's3';
		$host      = $this->bucket . '.' . $this->endpoint;
		$uri       = '/' . $file_name;
		$timestamp = gmdate( 'Ymd\THis\Z' );
		$date      = gmdate( 'Ymd' );

		// For PUT we use UNSIGNED-PAYLOAD to avoid hashing large files (permitted over HTTPS)
		// For GET/DELETE we hash an empty string
		$content_hash = ( $method === 'PUT' ) ? 'UNSIGNED-PAYLOAD' : hash( 'sha256', '' );

		$headers = [
			'host'                 => $host,
			'x-amz-content-sha256' => $content_hash,
			'x-amz-date'           => $timestamp,
		];

		// Sort headers alphabetically
		ksort( $headers );

		$canonical_headers = "";
		$signed_headers    = "";
		foreach ( $headers as $k => $v ) {
			$canonical_headers .= $k . ":" . $v . "\n";
			$signed_headers    .= $k . ";";
		}
		$signed_headers = rtrim( $signed_headers, ';' );

		$canonical_request = "$method\n$uri\n\n$canonical_headers\n$signed_headers\n$content_hash";
		
		$credential_scope = "$date/$this->region/$service/aws4_request";
		$string_to_sign   = "AWS4-HMAC-SHA256\n$timestamp\n$credential_scope\n" . hash( 'sha256', $canonical_request );

		$signing_key = $this->get_signing_key( $date, $this->region, $service );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$headers['Authorization'] = "AWS4-HMAC-SHA256 Credential=$this->access_key/$credential_scope, SignedHeaders=$signed_headers, Signature=$signature";

		return $headers;
	}

	private function get_signing_key( $date, $region, $service ) {
		$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		return $k_signing;
	}
}
