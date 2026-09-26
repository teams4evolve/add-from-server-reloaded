<?php
/**
 * PHPUnit bootstrap for unit tests (no full WordPress install required).
 *
 * @package AFSRReloaded
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Minimal WordPress stubs used by File_Filters / Features in unit context.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/afsr-wp-stub/' );
}
if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1024 * 1024 );
}

$GLOBALS['afsr_test_filters'] = array();

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stub sanitize_key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * Stub sanitize_file_name.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	function sanitize_file_name( $filename ) {
		return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $filename );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Stub absint.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
	/**
	 * Stub wp_check_filetype for unit tests.
	 *
	 * @param string $filename Filename.
	 * @return array
	 */
	function wp_check_filetype( $filename ) {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'mp3'  => 'audio/mpeg',
			'mp4'  => 'video/mp4',
			'pdf'  => 'application/pdf',
			'txt'  => 'text/plain',
		);
		$type = isset( $map[ $ext ] ) ? $map[ $ext ] : false;
		return array(
			'ext'  => $ext ? $ext : false,
			'type' => $type,
		);
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Minimal add_filter.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 */
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$GLOBALS['afsr_test_filters'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Minimal remove_filter.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	function remove_filter( $hook, $callback, $priority = 10 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( empty( $GLOBALS['afsr_test_filters'][ $hook ] ) ) {
			return;
		}
		$GLOBALS['afsr_test_filters'][ $hook ] = array_values(
			array_filter(
				$GLOBALS['afsr_test_filters'][ $hook ],
				static function ( $stored ) use ( $callback ) {
					return $stored !== $callback;
				}
			)
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Minimal apply_filters.
	 *
	 * @param string $hook  Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		$args = func_get_args();
		array_shift( $args );
		if ( empty( $GLOBALS['afsr_test_filters'][ $hook ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['afsr_test_filters'][ $hook ] as $callback ) {
			$value = call_user_func_array( $callback, $args );
			$args[0] = $value;
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * Stub wp_normalize_path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '#/+#', '/', $path );
		return $path;
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Stub untrailingslashit.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-path-guard.php';
require_once dirname( __DIR__ ) . '/includes/class-file-filters.php';
require_once dirname( __DIR__ ) . '/includes/class-features.php';
