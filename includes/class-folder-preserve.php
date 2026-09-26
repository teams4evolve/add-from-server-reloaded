<?php
/**
 * Preserve source relative folder structure under uploads.
 *
 * @package AFSRReloaded
 * @since   5.4.0
 */

namespace AFSRReloaded;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks upload subdir when Pro folder_preserve is enabled.
 *
 * @since 5.4.0
 */
class Folder_Preserve {

	/**
	 * Constructor.
	 *
	 * @since 5.4.0
	 */
	public function __construct() {
		add_filter( 'afsrreloaded_upload_subdir', array( $this, 'filter_subdir' ), 10, 2 );
	}

	/**
	 * Build a uploads subdirectory from the source relative path.
	 *
	 * @since 5.4.0
	 *
	 * @param string $subdir  Current subdir (empty = WP year/month).
	 * @param array  $context Import context (source_relative, preserve_structure).
	 * @return string
	 */
	public function filter_subdir( $subdir, $context = array() ) {
		if ( ! Features::enabled( 'folder_preserve' ) ) {
			return $subdir;
		}

		$context = is_array( $context ) ? $context : array();
		if ( empty( $context['preserve_structure'] ) ) {
			return $subdir;
		}

		$relative = isset( $context['source_relative'] ) ? (string) $context['source_relative'] : '';
		$relative = wp_normalize_path( ltrim( $relative, '/' ) );
		if ( '' === $relative ) {
			return $subdir;
		}

		$dir = dirname( $relative );
		if ( '.' === $dir || '/' === $dir || '\\' === $dir ) {
			/**
			 * Prefix used when preserving structure for root-level files.
			 *
			 * @since 5.4.0
			 *
			 * @param string $prefix Prefix under uploads basedir.
			 */
			$prefix = apply_filters( 'afsrreloaded_preserve_root_prefix', 'afsr-imports' );
			return trim( (string) $prefix, '/' );
		}

		// Disallow path traversal segments.
		$parts = array_filter(
			explode( '/', $dir ),
			static function ( $part ) {
				return '' !== $part && '.' !== $part && '..' !== $part;
			}
		);

		if ( empty( $parts ) ) {
			return $subdir;
		}

		$built = File_Filters::preserve_subdir_from_relative( $relative, 'afsr-imports' );

		/**
		 * Filters the preserved folder path under uploads.
		 *
		 * @since 5.4.0
		 *
		 * @param string $built    Relative path under uploads basedir.
		 * @param string $relative Source relative file path.
		 * @param array  $context  Import context.
		 */
		return (string) apply_filters( 'afsrreloaded_preserve_subdir', $built, $relative, $context );
	}
}
