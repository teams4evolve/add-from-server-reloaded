<?php
/**
 * Plugin installer / database schema.
 *
 * @package AFSRReloaded
 * @since   5.3.0
 */

namespace AFSRReloaded;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation, upgrades, and DB schema.
 *
 * @since 5.3.0
 */
class Installer {

	/**
	 * Database schema version.
	 *
	 * @since 5.3.0
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option key for stored DB version.
	 *
	 * @since 5.3.0
	 * @var string
	 */
	const DB_VERSION_OPTION = 'afsrreloaded_db_version';

	/**
	 * Run on plugin activation.
	 *
	 * @since 5.3.0
	 */
	public static function activate() {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		// Recurring cron is only needed when Pro background imports are available.
		if ( class_exists( __NAMESPACE__ . '\\Features' ) && Features::enabled( 'background' ) ) {
			if ( ! wp_next_scheduled( Import_Cron::HOOK ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'afsrreloaded_every_minute', Import_Cron::HOOK );
			}
		}
	}

	/**
	 * Ensure schema is current (safe to call on admin_init).
	 *
	 * @since 5.3.0
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::DB_VERSION_OPTION, '' );

		if ( self::DB_VERSION === $installed ) {
			return;
		}

		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Create or update custom tables.
	 *
	 * @since 5.3.0
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$jobs_table      = Import_Job_Repository::jobs_table();
		$items_table     = Import_Job_Repository::items_table();

		$sql_jobs = "CREATE TABLE {$jobs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			mode varchar(20) NOT NULL DEFAULT 'ajax',
			total_files bigint(20) unsigned NOT NULL DEFAULT 0,
			processed_files bigint(20) unsigned NOT NULL DEFAULT 0,
			imported bigint(20) unsigned NOT NULL DEFAULT 0,
			duplicates bigint(20) unsigned NOT NULL DEFAULT 0,
			errors bigint(20) unsigned NOT NULL DEFAULT 0,
			skipped bigint(20) unsigned NOT NULL DEFAULT 0,
			options longtext NULL,
			scan_queue longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			completed_at datetime NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY user_id (user_id),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		$sql_items = "CREATE TABLE {$items_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			file_path text NOT NULL,
			path_hash char(32) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			attachment_id bigint(20) unsigned NULL,
			message text NULL,
			processed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_path (job_id, path_hash),
			KEY job_status (job_id, status)
		) {$charset_collate};";

		dbDelta( $sql_jobs );
		dbDelta( $sql_items );
	}

	/**
	 * Drop custom tables (uninstall).
	 *
	 * @since 5.3.0
	 */
	public static function drop_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Import_Job_Repository::items_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Import_Job_Repository::jobs_table() );

		delete_option( self::DB_VERSION_OPTION );
	}
}
