<?php
/**
 * S3-compatible object storage client (Signature V4).
 *
 * @package AFSRReloaded
 * @since   5.4.0
 */

namespace AFSRReloaded;

use WP_Error;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal AWS Signature V4 client for S3-compatible services.
 *
 * @since 5.4.0
 */
class S3_Client {

	/**
	 * Service endpoint URL (scheme + host, no trailing slash).
	 *
	 * @var string
	 */
	protected $endpoint;

	/**
	 * AWS region.
	 *
	 * @var string
	 */
	protected $region;

	/**
	 * Bucket name.
	 *
	 * @var string
	 */
	protected $bucket;

	/**
	 * Access key ID.
	 *
	 * @var string
	 */
	protected $access_key;

	/**
	 * Secret access key.
	 *
	 * @var string
	 */
	protected $secret_key;

	/**
	 * Use path-style URLs (endpoint/bucket/key).
	 *
	 * @var bool
	 */
	protected $path_style;

	/**
	 * Constructor.
	 *
	 * @since 5.4.0
	 *
	 * @param string $endpoint   Endpoint URL.
	 * @param string $region     Region.
	 * @param string $bucket     Bucket.
	 * @param string $access_key Access key.
	 * @param string $secret_key Secret key.
	 * @param bool   $path_style Path-style addressing.
	 */
	public function __construct( $endpoint, $region, $bucket, $access_key, $secret_key, $path_style = false ) {
		$this->endpoint   = untrailingslashit( esc_url_raw( (string) $endpoint ) );
		$this->region     = sanitize_text_field( (string) $region );
		$this->bucket     = sanitize_text_field( (string) $bucket );
		$this->access_key = sanitize_text_field( (string) $access_key );
		$this->secret_key = (string) $secret_key;
		$this->path_style = (bool) $path_style;
	}

	/**
	 * List objects under a prefix.
	 *
	 * @since 5.4.0
	 *
	 * @param string $prefix Object key prefix.
	 * @param int    $max    Max keys (1-1000).
	 * @return array|WP_Error List of objects with key, size, type.
	 */
	public function list_objects( $prefix = '', $max = 100 ) {
		$prefix = $this->sanitize_key_prefix( $prefix );
		$max    = max( 1, min( 1000, absint( $max ) ) );

		$query = array(
			'list-type' => '2',
			'max-keys'  => (string) $max,
		);

		if ( '' !== $prefix ) {
			$query['prefix'] = $prefix;
		}

		$response = $this->request( 'GET', '', $query );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body   = wp_remote_retrieve_body( $response );
		$code   = wp_remote_retrieve_response_code( $response );
		$parsed = $this->parse_xml( $body );

		if ( 200 !== $code || ! $parsed ) {
			return new WP_Error(
				's3_list_failed',
				__( 'Could not list objects from storage.', 'add-from-server-reloaded' )
			);
		}

		$entries = array();
		foreach ( $this->xml_list( $parsed['CommonPrefixes'] ?? null ) as $cp ) {
			$key = isset( $cp['Prefix'] ) ? (string) $cp['Prefix'] : '';
			if ( '' === $key ) {
				continue;
			}
			$name = rtrim( basename( rtrim( $key, '/' ) ), '/' );
			if ( '' === $name ) {
				$name = rtrim( $key, '/' );
			}
			$entries[] = array(
				'name' => $name,
				'type' => 'dir',
				'path' => $key,
			);
		}

		foreach ( $this->xml_list( $parsed['Contents'] ?? null ) as $obj ) {
			$key = isset( $obj['Key'] ) ? (string) $obj['Key'] : '';
			if ( '' === $key || str_ends_with( $key, '/' ) ) {
				continue;
			}
			$entries[] = array(
				'name' => basename( $key ),
				'type' => 'file',
				'path' => $key,
				'size' => isset( $obj['Size'] ) ? (int) $obj['Size'] : 0,
			);
		}

		return $entries;
	}

	/**
	 * Normalize a SimpleXML→JSON list node to a numeric list of item arrays.
	 *
	 * A single child element becomes an associative array (not a list of one),
	 * which must be wrapped so callers can foreach safely.
	 *
	 * @since 5.4.3
	 *
	 * @param mixed $node Parsed XML node (null, assoc item, or list of items).
	 * @return array<int, array>
	 */
	protected function xml_list( $node ) {
		if ( null === $node || '' === $node || array() === $node ) {
			return array();
		}

		if ( ! is_array( $node ) ) {
			return array();
		}

		// Single item: keys like Key/Prefix, not a numeric list.
		if ( array_key_exists( 'Key', $node ) || array_key_exists( 'Prefix', $node ) ) {
			return array( $node );
		}

		$items = array();
		foreach ( $node as $item ) {
			if ( is_array( $item ) ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Download an object to a local file.
	 *
	 * @since 5.4.0
	 *
	 * @param string $key        Object key.
	 * @param string $local_path Local destination.
	 * @return true|WP_Error
	 */
	public function download_object( $key, $local_path ) {
		$key        = $this->sanitize_key_prefix( $key );
		$local_path = wp_normalize_path( (string) $local_path );

		if ( ! wp_mkdir_p( dirname( $local_path ) ) ) {
			return new WP_Error(
				'local_dir_failed',
				__( 'Could not create local directory for download.', 'add-from-server-reloaded' )
			);
		}

		$response = $this->request( 'GET', $key );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			$detail = '';
			$parsed = $this->parse_xml( $body );
			if ( is_array( $parsed ) && ! empty( $parsed['Message'] ) ) {
				$detail = ' ' . sanitize_text_field( (string) $parsed['Message'] );
			}
			return new WP_Error(
				's3_download_failed',
				sprintf(
					/* translators: 1: HTTP status code, 2: optional server message */
					__( 'Object download failed (HTTP %1$d).%2$s', 'add-from-server-reloaded' ),
					(int) $code,
					$detail
				)
			);
		}

		$written = file_put_contents( $local_path, $body );
		if ( false === $written ) {
			return new WP_Error(
				'local_write_failed',
				__( 'Could not write downloaded file.', 'add-from-server-reloaded' )
			);
		}

		return true;
	}

	/**
	 * Test bucket connectivity.
	 *
	 * @since 5.4.0
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$response = $this->request(
			'GET',
			'',
			array(
				'max-keys' => '1',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		return new WP_Error(
			's3_connection_failed',
			__( 'Could not connect to object storage.', 'add-from-server-reloaded' )
		);
	}

	/**
	 * Perform signed HTTP request.
	 *
	 * @since 5.4.0
	 *
	 * @param string $method HTTP method.
	 * @param string $key    Object key (empty for bucket root).
	 * @param array  $query  Query parameters.
	 * @return array|WP_Error wp_remote_request response.
	 */
	protected function request( $method, $key = '', $query = array() ) {
		if ( '' === $this->endpoint || '' === $this->bucket || '' === $this->access_key || '' === $this->secret_key ) {
			return new WP_Error(
				's3_config_incomplete',
				__( 'Object storage credentials are incomplete.', 'add-from-server-reloaded' )
			);
		}

		$key        = ltrim( $this->sanitize_key_prefix( $key ), '/' );
		$url        = $this->build_url( $key, $query );
		$parsed     = wp_parse_url( $url );
		$host       = $this->signing_host( $parsed );
		$path       = isset( $parsed['path'] ) ? $parsed['path'] : '/';
		$amz_date   = gmdate( 'Ymd\THis\Z' );
		$date_stamp = gmdate( 'Ymd' );

		$canonical_query = $this->canonical_query_string( $query );
		$payload_hash    = hash( 'sha256', '' );

		$canonical_headers = 'host:' . strtolower( $host ) . "\n" . 'x-amz-content-sha256:' . $payload_hash . "\n" . 'x-amz-date:' . $amz_date . "\n";
		$signed_headers    = 'host;x-amz-content-sha256;x-amz-date';

		$canonical_request = implode(
			"\n",
			array(
				strtoupper( $method ),
				$this->canonical_uri( $path ),
				$canonical_query,
				$canonical_headers,
				$signed_headers,
				$payload_hash,
			)
		);

		$scope          = $date_stamp . '/' . $this->region . '/s3/aws4_request';
		$string_to_sign = implode(
			"\n",
			array(
				'AWS4-HMAC-SHA256',
				$amz_date,
				$scope,
				hash( 'sha256', $canonical_request ),
			)
		);

		$signature = $this->sign( $string_to_sign, $date_stamp );
		$auth      = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$this->access_key,
			$scope,
			$signed_headers,
			$signature
		);

		$headers = array(
			'Host'                 => $host,
			'x-amz-date'           => $amz_date,
			'x-amz-content-sha256' => $payload_hash,
			'Authorization'        => $auth,
		);

		return wp_remote_request(
			$url,
			array(
				'method'  => strtoupper( $method ),
				'timeout' => 60,
				'headers' => $headers,
			)
		);
	}

	/**
	 * Build request URL.
	 *
	 * @since 5.4.0
	 *
	 * @param string $key   Object key.
	 * @param array  $query Query args.
	 * @return string
	 */
	protected function build_url( $key, $query ) {
		$key = ltrim( $key, '/' );
		if ( $this->path_style ) {
			$path = '/' . $this->bucket;
			if ( $key ) {
				$path .= '/' . $this->encode_key_path( $key );
			}
			$url = untrailingslashit( $this->endpoint ) . $path;
		} else {
			$parsed = wp_parse_url( $this->endpoint );
			$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
			$host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
			$port   = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
			$base   = $scheme . '://' . $this->bucket . '.' . $host . $port;
			$url    = $key ? untrailingslashit( $base ) . '/' . $this->encode_key_path( $key ) : trailingslashit( $base );
		}

		if ( ! empty( $query ) ) {
			$url .= '?' . $this->canonical_query_string( $query );
		}

		return $url;
	}

	/**
	 * Host value for SigV4 (includes non-default port).
	 *
	 * @since 5.4.2
	 *
	 * @param array $parsed wp_parse_url() result.
	 * @return string
	 */
	protected function signing_host( array $parsed ) {
		$host = isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		if ( '' === $host ) {
			return '';
		}

		$scheme = isset( $parsed['scheme'] ) ? strtolower( (string) $parsed['scheme'] ) : 'https';
		$port   = isset( $parsed['port'] ) ? (int) $parsed['port'] : 0;
		if ( $port > 0 ) {
			$default = ( 'https' === $scheme ) ? 443 : 80;
			if ( $port !== $default ) {
				$host .= ':' . $port;
			}
		}

		return $host;
	}

	/**
	 * Encode object key for URL path.
	 *
	 * @since 5.4.0
	 *
	 * @param string $key Key.
	 * @return string
	 */
	protected function encode_key_path( $key ) {
		$parts = explode( '/', $key );
		$out   = array();
		foreach ( $parts as $part ) {
			$out[] = $this->aws_encode( $part );
		}
		return implode( '/', $out );
	}

	/**
	 * AWS SigV4 path-segment encoding.
	 *
	 * @since 5.4.2
	 *
	 * @param string $value Raw segment.
	 * @return string
	 */
	protected function aws_encode( $value ) {
		return str_replace( '%7E', '~', rawurlencode( (string) $value ) );
	}

	/**
	 * Build canonical query string.
	 *
	 * @since 5.4.0
	 *
	 * @param array $query Query.
	 * @return string
	 */
	protected function canonical_query_string( $query ) {
		if ( empty( $query ) ) {
			return '';
		}

		ksort( $query );
		$pairs = array();
		foreach ( $query as $k => $v ) {
			$pairs[] = $this->aws_encode( (string) $k ) . '=' . $this->aws_encode( (string) $v );
		}
		return implode( '&', $pairs );
	}

	/**
	 * Build canonical URI path for SigV4.
	 *
	 * Segments from wp_parse_url() may already be percent-encoded (from build_url).
	 * Decode then encode once so signatures match keys with spaces/punctuation.
	 *
	 * @since 5.4.0
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	protected function canonical_uri( $path ) {
		$path = '/' . ltrim( (string) $path, '/' );
		if ( '/' === $path ) {
			return '/';
		}

		$segments = explode( '/', trim( $path, '/' ) );
		$encoded  = array();
		foreach ( $segments as $segment ) {
			$encoded[] = $this->aws_encode( rawurldecode( $segment ) );
		}

		return '/' . implode( '/', $encoded );
	}

	/**
	 * Calculate AWS4 signature.
	 *
	 * @since 5.4.0
	 *
	 * @param string $string_to_sign String to sign.
	 * @param string $date_stamp     Date stamp Ymd.
	 * @return string
	 */
	protected function sign( $string_to_sign, $date_stamp ) {
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $this->secret_key, true );
		$k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
		$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		return hash_hmac( 'sha256', $string_to_sign, $k_signing );
	}

	/**
	 * Parse simple XML response into array.
	 *
	 * @since 5.4.0
	 *
	 * @param string $xml XML body.
	 * @return array|null
	 */
	protected function parse_xml( $xml ) {
		if ( ! is_string( $xml ) || '' === $xml ) {
			return null;
		}

		libxml_use_internal_errors( true );
		$element = simplexml_load_string( $xml );
		if ( false === $element ) {
			return null;
		}

		$json = wp_json_encode( $element );
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Sanitize object key prefix.
	 *
	 * @since 5.4.0
	 *
	 * @param string $prefix Prefix.
	 * @return string
	 */
	protected function sanitize_key_prefix( $prefix ) {
		$prefix = wp_normalize_path( (string) $prefix );
		$prefix = str_replace( '..', '', $prefix );
		return ltrim( $prefix, '/' );
	}
}
