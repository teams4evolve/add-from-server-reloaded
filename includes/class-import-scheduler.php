<?php
/**
 * Scheduled folder imports via WP-Cron.
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
 * CRUD + cron runner for Pro scheduled imports.
 *
 * @since 5.4.0
 */
class Import_Scheduler {

	const OPTION      = 'afsrreloaded_scheduled_imports';
	const LOGS_OPTION = 'afsrreloaded_schedule_logs';
	const HOOK        = 'afsrreloaded_run_schedule';
	const PAGE        = 'add-from-server-reloaded-scheduler';

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Processor.
	 *
	 * @var Import_Processor
	 */
	protected $processor;

	/**
	 * Form error message.
	 *
	 * @var string
	 */
	protected $form_error = '';

	/**
	 * Sticky form values after a validation error.
	 *
	 * @var array<string,mixed>
	 */
	protected $form_values = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin           $plugin    Plugin.
	 * @param Import_Processor $processor Processor.
	 */
	public function __construct( Plugin $plugin, Import_Processor $processor ) {
		$this->plugin    = $plugin;
		$this->processor = $processor;

		add_filter( 'cron_schedules', array( $this, 'register_schedules' ) );
		add_action( self::HOOK, array( $this, 'cron_run' ), 10, 1 );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 35 );
		add_action( 'admin_init', array( $this, 'ensure_crons' ), 30 );
	}

	/**
	 * Whether scheduler is available.
	 *
	 * @return bool
	 */
	protected function is_enabled() {
		return Features::enabled( 'scheduled_imports' );
	}

	/**
	 * Register custom intervals.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function register_schedules( $schedules ) {
		$custom = array(
			'afsrreloaded_every_5_minutes'  => array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'Every 5 Minutes (Add From Server Reloaded)',
			),
			'afsrreloaded_every_15_minutes' => array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => 'Every 15 Minutes (Add From Server Reloaded)',
			),
			'afsrreloaded_every_30_minutes' => array(
				'interval' => 30 * MINUTE_IN_SECONDS,
				'display'  => 'Every 30 Minutes (Add From Server Reloaded)',
			),
		);

		foreach ( $custom as $key => $def ) {
			if ( ! isset( $schedules[ $key ] ) ) {
				$schedules[ $key ] = $def;
			}
		}

		return $schedules;
	}

	/**
	 * Allowed frequency keys.
	 *
	 * @return string[]
	 */
	public function allowed_frequencies() {
		return array(
			'afsrreloaded_every_5_minutes',
			'afsrreloaded_every_15_minutes',
			'afsrreloaded_every_30_minutes',
			'hourly',
			'twicedaily',
			'daily',
			'weekly',
		);
	}

	/**
	 * Human-readable labels for frequency keys.
	 *
	 * @since 5.4.1
	 *
	 * @return array<string,string>
	 */
	public function frequency_labels() {
		return array(
			'afsrreloaded_every_5_minutes'  => __( 'Every 5 minutes', 'add-from-server-reloaded' ),
			'afsrreloaded_every_15_minutes' => __( 'Every 15 minutes', 'add-from-server-reloaded' ),
			'afsrreloaded_every_30_minutes' => __( 'Every 30 minutes', 'add-from-server-reloaded' ),
			'hourly'                        => __( 'Hourly', 'add-from-server-reloaded' ),
			'twicedaily'                    => __( 'Twice daily', 'add-from-server-reloaded' ),
			'daily'                         => __( 'Daily', 'add-from-server-reloaded' ),
			'weekly'                        => __( 'Weekly', 'add-from-server-reloaded' ),
		);
	}

	/**
	 * Admin menu.
	 */
	public function register_menu() {
		if ( ! Capabilities::can_manage_schedules() && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$hook = add_submenu_page(
			'add-from-server-reloaded',
			__( 'Scheduled Imports', 'add-from-server-reloaded' ),
			__( 'Scheduled Imports', 'add-from-server-reloaded' ),
			Capabilities::rbac_enabled() ? Capabilities::CAP_SCHEDULES : 'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		// Handle POST/GET actions before any admin HTML is printed (avoids "headers already sent").
		if ( $hook && $this->is_enabled() ) {
			add_action( 'load-' . $hook, array( $this, 'handle_actions' ) );
		}
		if ( $hook ) {
			add_action(
				'load-' . $hook,
				static function () {
					Pro_Teaser::enqueue_locked_ui_assets();
				}
			);
		}
	}

	/**
	 * Keep cron events in sync with stored schedules.
	 *
	 * Important: do not unschedule+reschedule on every admin load. That raced
	 * WP-Cron (spawned on init, then wiped on admin_init) so “every 5 minutes”
	 * never actually fired unless the user clicked Run now.
	 */
	public function ensure_crons() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		foreach ( $this->get_schedules() as $id => $schedule ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id ) {
				continue;
			}

			if ( empty( $schedule['active'] ) ) {
				$this->unschedule_cron( $id );
				continue;
			}

			$frequency = sanitize_key( $schedule['frequency'] ?? 'daily' );
			if ( ! in_array( $frequency, $this->allowed_frequencies(), true ) ) {
				$frequency = 'daily';
			}

			$next = wp_next_scheduled( self::HOOK, array( $id ) );
			if ( false === $next ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, $frequency, self::HOOK, array( $id ) );
				continue;
			}

			// Only reschedule when the recurrence key actually changed.
			$current = $this->scheduled_recurrence_for( $id, (int) $next );
			if ( $current !== $frequency ) {
				$this->schedule_cron( $id, $frequency );
			}
		}
	}

	/**
	 * Recurrence key for an existing cron event, if any.
	 *
	 * @param string $id   Schedule ID.
	 * @param int    $next Timestamp from wp_next_scheduled().
	 * @return string|null
	 */
	protected function scheduled_recurrence_for( $id, $next ) {
		$cron = _get_cron_array();
		if ( empty( $cron[ $next ][ self::HOOK ] ) || ! is_array( $cron[ $next ][ self::HOOK ] ) ) {
			return null;
		}

		foreach ( $cron[ $next ][ self::HOOK ] as $event ) {
			if ( ! is_array( $event ) || ! isset( $event['args'] ) || array( $id ) !== $event['args'] ) {
				continue;
			}
			return isset( $event['schedule'] ) ? (string) $event['schedule'] : null;
		}

		return null;
	}

	/**
	 * Get schedules.
	 *
	 * @return array
	 */
	public function get_schedules() {
		$schedules = get_option( self::OPTION, array() );
		return is_array( $schedules ) ? $schedules : array();
	}

	/**
	 * Persist schedules.
	 *
	 * @param array $schedules Schedules.
	 */
	public function save_schedules( array $schedules ) {
		update_option( self::OPTION, $schedules, false );
	}

	/**
	 * Cron callback.
	 *
	 * @param string $schedule_id Schedule ID.
	 */
	public function cron_run( $schedule_id = '' ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->run_schedule( (string) $schedule_id, false );
	}

	/**
	 * Schedule WP-Cron event.
	 *
	 * @param string $id        Schedule ID.
	 * @param string $frequency Frequency key.
	 */
	public function schedule_cron( $id, $frequency ) {
		$id        = sanitize_key( $id );
		$frequency = sanitize_key( $frequency );
		if ( ! in_array( $frequency, $this->allowed_frequencies(), true ) ) {
			$frequency = 'daily';
		}

		$this->unschedule_cron( $id );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $frequency, self::HOOK, array( $id ) );
	}

	/**
	 * Clear cron for a schedule.
	 *
	 * @param string $id Schedule ID.
	 */
	public function unschedule_cron( $id ) {
		$id = sanitize_key( $id );
		$ts = wp_next_scheduled( self::HOOK, array( $id ) );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK, array( $id ) );
			$ts = wp_next_scheduled( self::HOOK, array( $id ) );
		}
	}

	/**
	 * Append log entry.
	 *
	 * @param array $entry Entry.
	 */
	public function log_execution( array $entry ) {
		$logs = get_option( self::LOGS_OPTION, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		$entry['timestamp'] = time();
		$logs[]             = $entry;
		if ( count( $logs ) > 100 ) {
			$logs = array_slice( $logs, -100 );
		}

		update_option( self::LOGS_OPTION, $logs, false );
	}

	/**
	 * Execute a schedule: create background job for folder.
	 *
	 * @param string $schedule_id Schedule ID.
	 * @param bool   $manual      Manual trigger.
	 * @return true|WP_Error
	 */
	public function run_schedule( $schedule_id, $manual = false ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'pro_required', __( 'Scheduled imports require an active Pro license.', 'add-from-server-reloaded' ) );
		}

		$schedule_id = sanitize_key( $schedule_id );
		$schedules   = $this->get_schedules();
		if ( ! isset( $schedules[ $schedule_id ] ) ) {
			return new WP_Error( 'missing_schedule', __( 'Schedule not found.', 'add-from-server-reloaded' ) );
		}

		$schedule = $schedules[ $schedule_id ];
		if ( ! $manual && empty( $schedule['active'] ) ) {
			return new WP_Error( 'inactive', __( 'Schedule is inactive.', 'add-from-server-reloaded' ) );
		}

		$lock = 'afsrreloaded_sched_lock_' . $schedule_id;
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'locked', __( 'Schedule is already running.', 'add-from-server-reloaded' ) );
		}
		set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );

		try {
			$folder = isset( $schedule['folder'] ) ? ltrim( rtrim( (string) $schedule['folder'], '/' ), '/' ) : '';
			$root   = $this->plugin->get_root();
			if ( ! $root ) {
				return new WP_Error( 'no_root', __( 'Root directory is not configured.', 'add-from-server-reloaded' ) );
			}

			$absolute = wp_normalize_path( trailingslashit( $root ) . $folder );
			if ( ! is_dir( $absolute ) ) {
				return new WP_Error(
					'missing_folder',
					sprintf(
						/* translators: 1: relative folder, 2: root path */
						__( 'Scheduled folder “%1$s” does not exist under root %2$s.', 'add-from-server-reloaded' ),
						$folder ? $folder : '/',
						$root
					)
				);
			}

			if ( ! Path_Guard::is_under_root( $root, $absolute, true ) ) {
				return new WP_Error( 'invalid_path', __( 'Scheduled folder is outside the allowed root.', 'add-from-server-reloaded' ) );
			}

			$options = array(
				'mode'               => Features::enabled( 'background' ) ? 'background' : 'ajax',
				'background'         => Features::enabled( 'background' ),
				'generate_metadata'  => ! empty( $schedule['generate_metadata'] ),
				'preserve_structure' => Features::enabled( 'folder_preserve' ) && ! empty( $schedule['preserve_structure'] ),
				'duplicate_action'   => Features::enabled( 'advanced_duplicates' ) ? sanitize_key( $schedule['duplicate_action'] ?? 'skip' ) : 'skip',
				'chunk_size'         => max( 1, min( 25, absint( $schedule['chunk_size'] ?? Import_Processor::DEFAULT_CHUNK_SIZE ) ) ),
				'schedule_id'        => $schedule_id,
				'file_types'         => sanitize_key( $schedule['file_types'] ?? 'all' ),
				'allowed_exts'       => sanitize_text_field( $schedule['allowed_exts'] ?? '' ),
				'max_file_size_mb'   => (float) ( $schedule['max_file_size_mb'] ?? 0 ),
				'min_mtime'          => absint( $schedule['min_mtime'] ?? 0 ),
			);

			$folders = array( $folder ? $folder : '/' );
			if ( '/' === $folders[0] || '' === $folder ) {
				// Import from root: scan root as folder "".
				$folders = array( '' );
			}

			$result = $this->processor->create_job( array(), $folders, $options );
			if ( is_wp_error( $result ) ) {
				$schedules[ $schedule_id ]['last_run']     = time();
				$schedules[ $schedule_id ]['last_status']  = 'error';
				$schedules[ $schedule_id ]['last_message'] = $result->get_error_message();
				$this->save_schedules( $schedules );
				$this->log_execution(
					array(
						'schedule_id' => $schedule_id,
						'status'      => 'error',
						'message'     => $result->get_error_message(),
						'manual'      => (bool) $manual,
					)
				);
				return $result;
			}

			$schedules[ $schedule_id ]['last_run']     = time();
			$schedules[ $schedule_id ]['last_status']  = 'started';
			$schedules[ $schedule_id ]['last_message'] = sprintf(
				/* translators: %d: job id */
				__( 'Started job #%d', 'add-from-server-reloaded' ),
				(int) $result->id
			);
			$schedules[ $schedule_id ]['last_job_id'] = (int) $result->id;
			$this->save_schedules( $schedules );

			$this->log_execution(
				array(
					'schedule_id' => $schedule_id,
					'status'      => 'started',
					'job_id'      => (int) $result->id,
					'manual'      => (bool) $manual,
				)
			);

			if ( Features::enabled( 'background' ) ) {
				Import_Cron::ensure_scheduled();
				Import_Cron::schedule_soon();
				// Kick first scan immediately.
				$this->processor->scan_chunk( $result->id );
			}

			return true;
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * Render admin UI and handle actions.
	 */
	public function render_page() {
		if ( ! Capabilities::can_manage_schedules() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'add-from-server-reloaded' ) );
		}

		if ( ! $this->is_enabled() ) {
			Pro_Locked_Screens::schedules();
			return;
		}

		// Actions are handled on load-$hook (before headers) via handle_actions().
		$schedules = $this->get_schedules();
		$logs      = get_option( self::LOGS_OPTION, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		$logs    = array_reverse( $logs );
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing = ( $edit_id && isset( $schedules[ $edit_id ] ) ) ? $schedules[ $edit_id ] : null;
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$root    = $this->plugin->get_root();

		$notices = array(
			'added'        => __( 'Schedule saved.', 'add-from-server-reloaded' ),
			'updated'      => __( 'Schedule updated.', 'add-from-server-reloaded' ),
			'deleted'      => __( 'Schedule deleted.', 'add-from-server-reloaded' ),
			'activated'    => __( 'Schedule activated.', 'add-from-server-reloaded' ),
			'deactivated'  => __( 'Schedule deactivated.', 'add-from-server-reloaded' ),
			'run_success'  => __( 'Schedule started.', 'add-from-server-reloaded' ),
			'logs_cleared' => __( 'Logs cleared.', 'add-from-server-reloaded' ),
		);

		$view                        = compact( 'schedules', 'logs', 'editing', 'edit_id', 'message', 'root', 'notices' );
		$view['form_error']          = $this->form_error;
		$view['page_slug']           = self::PAGE;
		$view['allowed_frequencies'] = $this->allowed_frequencies();
		$view['frequency_labels']    = $this->frequency_labels();
		$view['run_error_message']   = ( 'run_error' === $message && isset( $_GET['error_msg'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? rawurldecode( sanitize_text_field( wp_unslash( $_GET['error_msg'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		// Keep submitted values visible after a validation error.
		if ( $this->form_error && $this->form_values ) {
			$editing = is_array( $editing ) ? $editing : array();
			$editing = array_merge( $editing, $this->form_values );
			$view['editing'] = $editing;
		}

		include AFSRRELOADED_PLUGIN_DIR_PATH . 'admin/partials/scheduler-page.php';
	}

	/**
	 * Handle form / link actions (runs on load-$hook before output).
	 *
	 * @since 5.4.0
	 */
	public function handle_actions() {
		if ( ! Capabilities::can_manage_schedules() ) {
			return;
		}

		$redirect  = admin_url( 'admin.php?page=' . self::PAGE );
		$schedules = $this->get_schedules();

		if ( isset( $_POST['afsrreloaded_clear_logs'] ) ) {
			check_admin_referer( 'afsrreloaded_clear_logs' );
			delete_option( self::LOGS_OPTION );
			wp_safe_redirect( add_query_arg( 'message', 'logs_cleared', $redirect ) );
			exit;
		}

		if ( isset( $_POST['afsrreloaded_save_schedule'] ) ) {
			check_admin_referer( 'afsrreloaded_save_schedule' );

			$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			$folder  = isset( $_POST['folder'] ) ? sanitize_text_field( wp_unslash( $_POST['folder'] ) ) : '';
			$freq    = isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'daily';
			$edit_id = isset( $_POST['edit_id'] ) ? sanitize_key( wp_unslash( $_POST['edit_id'] ) ) : '';

			$this->form_values = array(
				'name'               => $name,
				'folder'             => $folder,
				'frequency'          => $freq,
				'active'             => ! empty( $_POST['active'] ),
				'generate_metadata'  => ! empty( $_POST['generate_metadata'] ),
				'preserve_structure' => ! empty( $_POST['preserve_structure'] ),
				'file_types'         => isset( $_POST['file_types'] ) ? sanitize_key( wp_unslash( $_POST['file_types'] ) ) : 'all',
				'allowed_exts'       => isset( $_POST['allowed_exts'] ) ? sanitize_text_field( wp_unslash( $_POST['allowed_exts'] ) ) : '',
				'max_file_size_mb'   => isset( $_POST['max_file_size_mb'] ) ? (float) wp_unslash( $_POST['max_file_size_mb'] ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'duplicate_action'   => isset( $_POST['duplicate_action'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_action'] ) ) : 'skip',
			);

			if ( ! in_array( $freq, $this->allowed_frequencies(), true ) ) {
				$freq = 'daily';
			}

			$root = $this->plugin->get_root();
			if ( ! $root ) {
				$this->form_error = __( 'Root directory is not configured.', 'add-from-server-reloaded' );
				return;
			}

			$normalized = $this->normalize_folder_input( $folder, $root );
			if ( is_wp_error( $normalized ) ) {
				$this->form_error = $normalized->get_error_message();
				return;
			}
			$folder = $normalized;
			$this->form_values['folder'] = $folder;

			$id = ( $edit_id && isset( $schedules[ $edit_id ] ) ) ? $edit_id : 'sched_' . strtolower( wp_generate_password( 8, false, false ) );

			$schedules[ $id ] = array(
				'id'                 => $id,
				'name'               => $name ? $name : sprintf( /* translators: %s: folder */ __( 'Schedule for /%s', 'add-from-server-reloaded' ), $folder ),
				'folder'             => $folder,
				'frequency'          => $freq,
				'active'             => ! empty( $_POST['active'] ),
				'generate_metadata'  => ! empty( $_POST['generate_metadata'] ),
				'preserve_structure' => ! empty( $_POST['preserve_structure'] ),
				'file_types'         => isset( $_POST['file_types'] ) ? sanitize_key( wp_unslash( $_POST['file_types'] ) ) : 'all',
				'allowed_exts'       => isset( $_POST['allowed_exts'] ) ? sanitize_text_field( wp_unslash( $_POST['allowed_exts'] ) ) : '',
				'max_file_size_mb'   => isset( $_POST['max_file_size_mb'] ) ? (float) wp_unslash( $_POST['max_file_size_mb'] ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'duplicate_action'   => isset( $_POST['duplicate_action'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_action'] ) ) : 'skip',
				'chunk_size'         => Import_Processor::DEFAULT_CHUNK_SIZE,
				'last_run'           => $schedules[ $id ]['last_run'] ?? 0,
				'last_status'        => $schedules[ $id ]['last_status'] ?? '',
				'last_message'       => $schedules[ $id ]['last_message'] ?? '',
				'last_job_id'        => $schedules[ $id ]['last_job_id'] ?? 0,
			);

			$this->save_schedules( $schedules );
			if ( ! empty( $schedules[ $id ]['active'] ) ) {
				$this->schedule_cron( $id, $freq );
			} else {
				$this->unschedule_cron( $id );
			}

			wp_safe_redirect( add_query_arg( 'message', $edit_id ? 'updated' : 'added', $redirect ) );
			exit;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $action || ! $id || ! isset( $schedules[ $id ] ) ) {
			return;
		}

		if ( 'delete' === $action ) {
			check_admin_referer( 'delete-schedule-' . $id );
			$this->unschedule_cron( $id );
			unset( $schedules[ $id ] );
			$this->save_schedules( $schedules );
			wp_safe_redirect( add_query_arg( 'message', 'deleted', $redirect ) );
			exit;
		}

		if ( 'toggle' === $action ) {
			check_admin_referer( 'toggle-schedule-' . $id );
			$schedules[ $id ]['active'] = empty( $schedules[ $id ]['active'] );
			$this->save_schedules( $schedules );
			if ( ! empty( $schedules[ $id ]['active'] ) ) {
				$this->schedule_cron( $id, $schedules[ $id ]['frequency'] ?? 'daily' );
				$msg = 'activated';
			} else {
				$this->unschedule_cron( $id );
				$msg = 'deactivated';
			}
			wp_safe_redirect( add_query_arg( 'message', $msg, $redirect ) );
			exit;
		}

		if ( 'run' === $action ) {
			check_admin_referer( 'run-schedule-' . $id );
			$result = $this->run_schedule( $id, true );
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'message'   => 'run_error',
							'error_msg' => rawurlencode( $result->get_error_message() ),
						),
						$redirect
					)
				);
			} else {
				wp_safe_redirect( add_query_arg( 'message', 'run_success', $redirect ) );
			}
			exit;
		}
	}

	/**
	 * Normalize schedule folder input to a path relative to the plugin root.
	 *
	 * Accepts relative paths, absolute paths under the root, host paths that
	 * only differ by mount prefix (e.g. DDEV), and wp-content paths.
	 *
	 * @since 5.4.2
	 *
	 * @param string $input Raw folder from the form.
	 * @param string $root  Absolute root directory.
	 * @return string|\WP_Error Relative path, or WP_Error on failure.
	 */
	protected function normalize_folder_input( $input, $root ) {
		$input = $this->sanitize_folder_input( $input );
		if ( '' === $input ) {
			return new WP_Error(
				'empty_folder',
				__( 'Please enter a folder path.', 'add-from-server-reloaded' )
			);
		}

		$root = wp_normalize_path( (string) $root );

		$candidates = $this->folder_path_candidates( $input, $root );
		$absolute   = null;

		foreach ( $candidates as $candidate ) {
			if ( ! is_dir( $candidate ) ) {
				continue;
			}
			if ( ! Path_Guard::is_under_root( $root, $candidate, true ) ) {
				continue;
			}
			$absolute = $candidate;
			break;
		}

		if ( null === $absolute ) {
			return new WP_Error(
				'missing_folder',
				sprintf(
					/* translators: 1: typed path, 2: root path */
					__( 'Folder “%1$s” was not found under %2$s. Use a path that exists on this server (relative like html/wordpress/wp-content/uploads, or the full path under that root).', 'add-from-server-reloaded' ),
					$input,
					$root
				)
			);
		}

		$real_root = realpath( $root );
		$real_abs  = realpath( $absolute );
		if ( ! $real_root || ! $real_abs ) {
			return new WP_Error(
				'invalid_path',
				__( 'Could not resolve the folder path.', 'add-from-server-reloaded' )
			);
		}

		$real_root = untrailingslashit( wp_normalize_path( $real_root ) );
		$real_abs  = untrailingslashit( wp_normalize_path( $real_abs ) );

		if ( $real_abs === $real_root ) {
			return '';
		}

		$prefix = $real_root . '/';
		if ( ! str_starts_with( $real_abs, $prefix ) ) {
			return new WP_Error(
				'invalid_path',
				sprintf(
					/* translators: %s: root path */
					__( 'Folder is outside the allowed root (%s).', 'add-from-server-reloaded' ),
					$root
				)
			);
		}

		return ltrim( substr( $real_abs, strlen( $prefix ) ), '/' );
	}

	/**
	 * Clean raw folder form input before resolving.
	 *
	 * @param string $input Raw input.
	 * @return string
	 */
	protected function sanitize_folder_input( $input ) {
		$input = trim( (string) $input );
		$input = trim( $input, "\"'" );
		if ( str_starts_with( strtolower( $input ), 'file:' ) ) {
			$input = preg_replace( '#^file:(//)?#i', '', $input );
		}
		$input = str_replace( '\\', '/', $input );
		$input = wp_normalize_path( $input );
		return untrailingslashit( $input );
	}

	/**
	 * Build candidate absolute paths for schedule folder input.
	 *
	 * @param string $input Normalized input path.
	 * @param string $root  Normalized root path.
	 * @return string[]
	 */
	protected function folder_path_candidates( $input, $root ) {
		$candidates = array();
		$root       = untrailingslashit( $root );
		$abspath    = defined( 'ABSPATH' ) ? untrailingslashit( wp_normalize_path( ABSPATH ) ) : '';

		$is_absolute = ( isset( $input[0] ) && '/' === $input[0] )
			|| (bool) preg_match( '#^[A-Za-z]:/#', $input );

		if ( $is_absolute ) {
			$candidates[] = $input;
			// Leading-slash path that is actually root-relative (e.g. /html/...).
			$candidates[] = $root . '/' . ltrim( $input, '/' );
		} else {
			$candidates[] = $root . '/' . ltrim( $input, '/' );
			if ( $abspath ) {
				$candidates[] = $abspath . '/' . ltrim( $input, '/' );
			}
		}

		// Any path suffix that exists directly under the configured root.
		$parts = array_values( array_filter( explode( '/', trim( $input, '/' ) ), 'strlen' ) );
		$count = count( $parts );
		for ( $i = 0; $i < $count; $i++ ) {
			$suffix         = implode( '/', array_slice( $parts, $i ) );
			$candidates[]   = $root . '/' . $suffix;
			if ( $abspath ) {
				$candidates[] = $abspath . '/' . $suffix;
			}
		}

		// Host/container path mismatch: keep from wp-content/ onward under this WP install.
		if ( $abspath && preg_match( '#/(wp-content/.+)$#', $input, $m ) ) {
			$candidates[] = $abspath . '/' . $m[1];
		}

		$unique = array();
		foreach ( $candidates as $path ) {
			$path = untrailingslashit( wp_normalize_path( (string) $path ) );
			if ( '' !== $path ) {
				$unique[ $path ] = $path;
			}
		}

		return array_values( $unique );
	}
}
