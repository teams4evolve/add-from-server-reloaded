<?php
/**
 * Plugin Name:       Add From Server Reloaded
 * Plugin URI:        https://wordpress.org/plugins/add-from-server-reloaded/
 * Description:       Bypass WordPress upload limit. Import large files from your server to Media Library with reliable chunked imports. Pair with Add From Server Reloaded Pro for background jobs and advanced queue tools.
 * Version:           5.3.0
 * Author:            eLearning evolve
 * Author URI:        https://elearningevolve.com/about/
 * License:           GPL-3.0+
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       add-from-server-reloaded
 * Requires PHP:      7.4
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      7.0
 *
 * @since             4.0.0
 * @package           Add From Server Reloaded
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

defined( 'AFSRRELOADED_MIN_WP' ) || define( 'AFSRRELOADED_MIN_WP', '6.0' );
defined( 'AFSRRELOADED_MIN_PHP' ) || define( 'AFSRRELOADED_MIN_PHP', '7.4' );
defined( 'AFSRRELOADED_VERSION' ) || define( 'AFSRRELOADED_VERSION', '5.3.0' );
defined( 'AFSRRELOADED_PLUGIN_FILE' ) || define( 'AFSRRELOADED_PLUGIN_FILE', __FILE__ );
defined( 'AFSRRELOADED_PLUGIN_DIR_PATH' ) || define( 'AFSRRELOADED_PLUGIN_DIR_PATH', plugin_dir_path( __FILE__ ) );
defined( 'AFSRRELOADED_PLUGIN_DIR_URL' ) || define( 'AFSRRELOADED_PLUGIN_DIR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload plugin class files.
 *
 * @since 5.3.0
 *
 * @param string $class Class name.
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'AFSRReloaded\\' ) ) {
			return;
		}

		$relative = strtolower( str_replace( array( 'AFSRReloaded\\', '_' ), array( '', '-' ), $class ) );
		$map      = array(
			'plugin'                => 'class.add-from-server.php',
			'features'              => 'includes/class-features.php',
			'installer'             => 'includes/class-installer.php',
			'import-job-repository' => 'includes/class-import-job-repository.php',
			'import-processor'      => 'includes/class-import-processor.php',
			'import-ajax'           => 'includes/class-import-ajax.php',
			'import-cron'           => 'includes/class-import-cron.php',
			'import-history'        => 'includes/class-import-history.php',
		);

		if ( ! isset( $map[ $relative ] ) ) {
			return;
		}

		$file = AFSRRELOADED_PLUGIN_DIR_PATH . $map[ $relative ];
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// Load PHP8 compat functions.
require __DIR__ . '/compat.php';

// Old versions of WordPress or PHP.
if (
	version_compare( $GLOBALS['wp_version'], AFSRRELOADED_MIN_WP, '<' )
	||
	version_compare( phpversion(), AFSRRELOADED_MIN_PHP, '<' )
) {
	require __DIR__ . '/old-versions.php';
	AFSRReloaded\Plugin::instance();
	return;
}

register_activation_hook( __FILE__, array( 'AFSRReloaded\\Installer', 'activate' ) );

/**
 * Bootstrap the plugin.
 *
 * Loads in admin, AJAX, cron, and WP-CLI contexts so background imports can run.
 *
 * @since 5.3.0
 */
function afsrreloaded_bootstrap() {
	$should_load = is_admin()
		|| wp_doing_cron()
		|| ( defined( 'WP_CLI' ) && WP_CLI );

	if ( ! $should_load ) {
		return;
	}

	AFSRReloaded\Plugin::instance();
}
add_action( 'plugins_loaded', 'afsrreloaded_bootstrap' );
