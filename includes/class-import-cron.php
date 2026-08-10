<?php
/**
 * Background import processing via WP-Cron.
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
 * Cron runner for import jobs.
 *
 * @since 5.3.0
 */
class Import_Cron {

	/**
	 * Cron hook name.
	 *
	 * @since 5.3.0
	 * @var string
	 */
	const HOOK = 'afsrreloaded_process_import_jobs';

	/**
	 * Single-event hook for near-term processing.
	 *
	 * @since 5.3.0
	 * @var string
	 */
	const HOOK_SOON = 'afsrreloaded_process_import_jobs_soon';

	/**
	 * Processor.
	 *
	 * @since 5.3.0
	 * @var Import_Processor
	 */
	protected $processor;

	/**
	 * Constructor.
	 *
	 * @since 5.3.0
	 *
	 * @param Import_Processor $processor Processor.
	 */
	public function __construct( Import_Processor $processor ) {
		$this->processor = $processor;

		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::HOOK_SOON, array( $this, 'run' ) );
	}

	/**
	 * Register a one-minute cron interval.
	 *
	 * @since 5.3.0
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_schedule( $schedules ) {
		if ( ! isset( $schedules['afsrreloaded_every_minute'] ) ) {
			$schedules['afsrreloaded_every_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				// Untranslated: this filter can run before the init hook.
				'display'  => 'Every Minute (Add From Server Reloaded)',
			);
		}

		return $schedules;
	}

	/**
	 * Ensure recurring cron is scheduled.
	 *
	 * @since 5.3.0
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'afsrreloaded_every_minute', self::HOOK );
		}
	}

	/**
	 * Schedule a near-term single run.
	 *
	 * @since 5.3.0
	 */
	public static function schedule_soon() {
		if ( ! wp_next_scheduled( self::HOOK_SOON ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK_SOON );
		}

		// Nudge WP-Cron on hosts where visits are sparse.
		spawn_cron();
	}

	/**
	 * Process active jobs.
	 *
	 * @since 5.3.0
	 */
	public function run() {
		$lock_key = 'afsrreloaded_cron_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}

		set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

		try {
			$jobs = Import_Job_Repository::get_runnable_jobs( 2 );

			foreach ( $jobs as $job ) {
				// Prefer background-mode jobs; also continue ajax jobs that stalled.
				$stale_seconds = (int) apply_filters( 'afsrreloaded_stale_job_seconds', 90 );
				$updated_ts    = strtotime( $job->updated_at . ' UTC' );
				$is_stale      = $updated_ts && ( time() - $updated_ts ) >= $stale_seconds;

				if ( 'background' !== $job->mode && ! $is_stale ) {
					continue;
				}

				if ( ! empty( $job->scan_queue ) || 'scanning' === $job->status ) {
					$this->processor->scan_chunk( $job->id );
					$job = Import_Job_Repository::get_job( $job->id );
				}

				if ( $job && empty( $job->scan_queue ) && in_array( $job->status, array( 'pending', 'running' ), true ) ) {
					// Multiple chunks per cron tick for better throughput.
					$max_chunks = (int) apply_filters( 'afsrreloaded_cron_chunks_per_run', 3, $job );
					for ( $i = 0; $i < $max_chunks; $i++ ) {
						$result = $this->processor->process_chunk( $job->id );
						if ( is_wp_error( $result ) || ! empty( $result['is_complete'] ) || 'paused' === $result['status'] ) {
							break;
						}
					}
				}
			}

			// Process deferred attachment metadata.
			$this->process_deferred_metadata();
		} finally {
			delete_transient( $lock_key );
		}

		// Keep recurring schedule alive while work remains.
		$remaining = Import_Job_Repository::get_runnable_jobs( 1 );
		if ( ! empty( $remaining ) ) {
			self::schedule_soon();
		}
	}

	/**
	 * Generate deferred attachment metadata in small batches.
	 *
	 * @since 5.3.0
	 */
	protected function process_deferred_metadata() {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'meta_key'       => '_afsrreloaded_needs_metadata', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);

		if ( empty( $query->posts ) ) {
			return;
		}

		foreach ( $query->posts as $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( ! $file || ! file_exists( $file ) ) {
				delete_post_meta( $attachment_id, '_afsrreloaded_needs_metadata' );
				continue;
			}

			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$data = wp_generate_attachment_metadata( $attachment_id, $file );
			if ( ! empty( $data ) ) {
				wp_update_attachment_metadata( $attachment_id, $data );
			}
			delete_post_meta( $attachment_id, '_afsrreloaded_needs_metadata' );
		}
	}
}
