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
		add_action( 'admin_init', array( __CLASS__, 'maybe_nudge_from_admin' ), 40 );
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
		// Always (re)schedule a near-term tick so leaving the page does not leave
		// jobs waiting on a missed single event.
		$next = wp_next_scheduled( self::HOOK_SOON );
		if ( $next && $next > time() + 30 ) {
			wp_unschedule_event( $next, self::HOOK_SOON );
			$next = false;
		}
		if ( ! $next ) {
			wp_schedule_single_event( time() + 1, self::HOOK_SOON );
		}

		self::ensure_scheduled();
		spawn_cron( time() );
	}

	/**
	 * On admin page loads, keep background jobs moving without a manual "Continue" click.
	 *
	 * @since 5.4.2
	 */
	public static function maybe_nudge_from_admin() {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( class_exists( __NAMESPACE__ . '\\Features' ) && ! Features::enabled( 'background' ) ) {
			return;
		}
		if ( ! class_exists( __NAMESPACE__ . '\\Import_Job_Repository' ) ) {
			return;
		}

		$jobs = Import_Job_Repository::get_runnable_jobs( 1 );
		if ( empty( $jobs ) ) {
			return;
		}

		self::ensure_scheduled();
		self::schedule_soon();

		// Process one tick after the response is sent so admin pages stay snappy.
		add_action(
			'shutdown',
			static function () {
				$plugin = Plugin::instance();
				$proc   = $plugin->get_import_processor();
				if ( $proc ) {
					self::process_now( $proc );
				}
			},
			5
		);
	}

	/**
	 * Process active jobs.
	 *
	 * @since 5.3.0
	 */
	public function run() {
		self::process_now( $this->processor );
	}

	/**
	 * Process runnable jobs immediately (used by cron + AJAX kick).
	 *
	 * @since 5.4.1
	 *
	 * @param Import_Processor $processor Processor.
	 */
	public static function process_now( Import_Processor $processor ) {
		// Background processing is a Pro-gated capability.
		if ( class_exists( __NAMESPACE__ . '\\Features' ) && ! Features::enabled( 'background' ) ) {
			return;
		}

		$lock_key = 'afsrreloaded_cron_lock';
		if ( get_transient( $lock_key ) ) {
			// Another worker is busy — ensure a follow-up tick is scheduled.
			self::schedule_soon();
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
					$processor->scan_chunk( $job->id );
					$job = Import_Job_Repository::get_job( $job->id );
				}

				if ( $job && empty( $job->scan_queue ) && in_array( $job->status, array( 'pending', 'running' ), true ) ) {
					// Multiple chunks per cron tick for better throughput.
					$max_chunks = (int) apply_filters( 'afsrreloaded_cron_chunks_per_run', 3, $job );
					for ( $i = 0; $i < $max_chunks; $i++ ) {
						$result = $processor->process_chunk( $job->id );
						if ( is_wp_error( $result ) || ! empty( $result['is_complete'] ) || 'paused' === $result['status'] ) {
							break;
						}
					}
				}
			}

			// Process deferred attachment metadata.
			self::process_deferred_metadata_batch();
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
		self::process_deferred_metadata_batch();
	}

	/**
	 * Generate deferred attachment metadata in small batches (static).
	 *
	 * @since 5.4.1
	 */
	protected static function process_deferred_metadata_batch() {
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
