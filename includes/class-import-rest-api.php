<?php
/**
 * REST API for remote import operations.
 *
 * @package AFSRReloaded
 * @since   5.4.0
 */

namespace AFSRReloaded;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Pro REST routes under add-from-server/v1.
 *
 * @since 5.4.0
 */
class Import_Rest_Api {

	const REST_NAMESPACE = 'add-from-server/v1';

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Import processor.
	 *
	 * @var Import_Processor
	 */
	protected $processor;

	/**
	 * Optional scheduler.
	 *
	 * @var Import_Scheduler|null
	 */
	protected $scheduler;

	/**
	 * Constructor.
	 *
	 * @since 5.4.0
	 *
	 * @param Plugin                $plugin    Plugin.
	 * @param Import_Processor      $processor Processor.
	 * @param Import_Scheduler|null $scheduler Scheduler.
	 */
	public function __construct( Plugin $plugin, Import_Processor $processor, ?Import_Scheduler $scheduler = null ) {
		$this->plugin    = $plugin;
		$this->processor = $processor;
		$this->scheduler = $scheduler;

		// Always hook; Pro unlocks Features after plugins_loaded:20.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @since 5.4.0
	 */
	public function register_routes() {
		if ( ! Features::enabled( 'rest_api' ) ) {
			return;
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/files',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_files' ),
				'permission_callback' => array( $this, 'permission_can_browse' ),
				'args'                => array(
					'path' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_import' ),
				'permission_callback' => array( $this, 'permission_can_import' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_job_status' ),
				'permission_callback' => array( $this, 'permission_can_import' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
				),
			)
		);

		if ( $this->scheduler && Features::enabled( 'scheduled_imports' ) ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/schedules',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_schedules' ),
					'permission_callback' => array( $this, 'permission_can_manage_schedules' ),
				)
			);

			register_rest_route(
				self::REST_NAMESPACE,
				'/schedules/(?P<id>[a-zA-Z0-9_-]+)/run',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_schedule' ),
					'permission_callback' => array( $this, 'permission_can_import' ),
					'args'                => array(
						'id' => array(
							'sanitize_callback' => 'sanitize_key',
						),
					),
				)
			);
		}
	}

	/**
	 * Browse permission.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public function permission_can_browse() {
		return Capabilities::can_browse();
	}

	/**
	 * Import permission.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public function permission_can_import() {
		return Capabilities::can_import();
	}

	/**
	 * Schedule management permission.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public function permission_can_manage_schedules() {
		return Capabilities::can_manage_schedules();
	}

	/**
	 * List files and directories under root.
	 *
	 * @since 5.4.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_files( WP_REST_Request $request ) {
		$relative = (string) $request->get_param( 'path' );
		$resolved = $this->resolve_under_root( $relative );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if ( ! is_dir( $resolved['absolute'] ) ) {
			return new WP_Error(
				'not_a_directory',
				__( 'The requested path is not a directory.', 'add-from-server-reloaded' ),
				array( 'status' => 400 )
			);
		}

		$entries = array();
		$nodes   = glob( trailingslashit( $resolved['absolute'] ) . '*' );

		if ( $nodes ) {
			sort( $nodes, SORT_STRING );
			foreach ( $nodes as $node ) {
				$name = basename( $node );
				if ( '' === $name || '.' === $name[0] ) {
					continue;
				}

				$entry_rel = ltrim( str_replace( $resolved['root'], '', wp_normalize_path( $node ) ), '/' );
				$is_dir    = is_dir( $node );

				if ( ! $is_dir && ( ! is_file( $node ) || $this->plugin->is_restricted_file( $node ) ) ) {
					continue;
				}

				$item = array(
					'name' => $name,
					'type' => $is_dir ? 'dir' : 'file',
					'path' => $entry_rel,
				);

				if ( ! $is_dir ) {
					$item['size'] = (int) filesize( $node );
				}

				$entries[] = $item;
			}
		}

		$parent = '';
		if ( '' !== $resolved['relative'] ) {
			$parent = dirname( $resolved['relative'] );
			if ( '.' === $parent ) {
				$parent = '';
			}
		}

		return rest_ensure_response(
			array(
				'path'    => $resolved['relative'],
				'parent'  => $parent,
				'root'    => $resolved['root'],
				'entries' => $entries,
			)
		);
	}

	/**
	 * Create an import job.
	 *
	 * @since 5.4.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_import( WP_REST_Request $request ) {
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : array();
		$files   = isset( $params['files'] ) ? (array) $params['files'] : array();
		$folders = isset( $params['folders'] ) ? (array) $params['folders'] : array();
		$options = isset( $params['options'] ) && is_array( $params['options'] ) ? $params['options'] : array();

		$job_options = array(
			'background'        => ! empty( $options['background'] ) && Features::enabled( 'background' ),
			'generate_metadata' => ! isset( $options['generate_metadata'] ) || ! empty( $options['generate_metadata'] ),
		);

		if ( Features::enabled( 'folder_preserve' ) && ! empty( $options['preserve_structure'] ) ) {
			$job_options['preserve_structure'] = true;
		}

		if ( Features::enabled( 'advanced_duplicates' ) && ! empty( $options['duplicate_action'] ) ) {
			$allowed = array( 'skip', 'replace', 'rename' );
			$action  = sanitize_key( $options['duplicate_action'] );
			if ( in_array( $action, $allowed, true ) ) {
				$job_options['duplicate_action'] = $action;
			}
		}

		$result = $this->processor->create_job( $files, $folders, $job_options );
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $this->processor->status_payload( $result ) );
	}

	/**
	 * Get job status.
	 *
	 * @since 5.4.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job_status( WP_REST_Request $request ) {
		$job_id = absint( $request->get_param( 'id' ) );
		$job    = Import_Job_Repository::get_job( $job_id );

		if ( ! $job ) {
			return new WP_Error(
				'invalid_job',
				__( 'Import job not found.', 'add-from-server-reloaded' ),
				array( 'status' => 404 )
			);
		}

		$access = $this->assert_job_access( $job );
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		return rest_ensure_response( $this->processor->status_payload( $job ) );
	}

	/**
	 * List scheduled imports.
	 *
	 * @since 5.4.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_schedules() {
		if ( ! $this->scheduler ) {
			return new WP_Error(
				'no_scheduler',
				__( 'Scheduled imports are not available.', 'add-from-server-reloaded' ),
				array( 'status' => 501 )
			);
		}

		$schedules = $this->scheduler->get_schedules();
		$list      = array();

		foreach ( $schedules as $id => $schedule ) {
			$list[] = array(
				'id'          => $id,
				'name'        => isset( $schedule['name'] ) ? (string) $schedule['name'] : $id,
				'folder'      => isset( $schedule['folder'] ) ? (string) $schedule['folder'] : '',
				'frequency'   => isset( $schedule['frequency'] ) ? (string) $schedule['frequency'] : 'daily',
				'active'      => ! empty( $schedule['active'] ),
				'last_run'    => isset( $schedule['last_run'] ) ? (int) $schedule['last_run'] : 0,
				'last_status' => isset( $schedule['last_status'] ) ? (string) $schedule['last_status'] : '',
				'last_job_id' => isset( $schedule['last_job_id'] ) ? (int) $schedule['last_job_id'] : 0,
			);
		}

		return rest_ensure_response( array( 'schedules' => $list ) );
	}

	/**
	 * Manually run a schedule.
	 *
	 * @since 5.4.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_schedule( WP_REST_Request $request ) {
		if ( ! $this->scheduler ) {
			return new WP_Error(
				'no_scheduler',
				__( 'Scheduled imports are not available.', 'add-from-server-reloaded' ),
				array( 'status' => 501 )
			);
		}

		$id     = sanitize_key( (string) $request->get_param( 'id' ) );
		$result = $this->scheduler->run_schedule( $id, true );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $id,
				'message' => __( 'Schedule started.', 'add-from-server-reloaded' ),
			)
		);
	}

	/**
	 * Resolve a relative path under plugin root.
	 *
	 * @since 5.4.0
	 *
	 * @param string $relative Relative path.
	 * @return array|WP_Error Keys: root, relative, absolute.
	 */
	protected function resolve_under_root( $relative ) {
		$root = $this->plugin->get_root();
		if ( ! $root ) {
			return new WP_Error(
				'no_root',
				__( 'Unable to determine root directory.', 'add-from-server-reloaded' ),
				array( 'status' => 500 )
			);
		}

		$root_real = realpath( $root );
		if ( ! $root_real ) {
			return new WP_Error(
				'no_root',
				__( 'Root directory is not accessible.', 'add-from-server-reloaded' ),
				array( 'status' => 500 )
			);
		}

		$root_real = wp_normalize_path( $root_real );
		$relative  = ltrim( wp_normalize_path( (string) $relative ), '/' );

		if ( str_contains( $relative, '..' ) ) {
			return new WP_Error(
				'security_path',
				__( 'Security error: file is outside the allowed directory.', 'add-from-server-reloaded' ),
				array( 'status' => 403 )
			);
		}

		$candidate = $relative ? trailingslashit( $root_real ) . $relative : $root_real;
		$realpath  = realpath( $candidate );

		if ( ! $realpath || ! Path_Guard::path_has_root_boundary( $realpath, $root_real ) ) {
			return new WP_Error(
				'security_path',
				__( 'Security error: file is outside the allowed directory.', 'add-from-server-reloaded' ),
				array( 'status' => 403 )
			);
		}

		return array(
			'root'     => $root_real,
			'relative' => $relative,
			'absolute' => wp_normalize_path( $realpath ),
		);
	}

	/**
	 * Ensure current user can access the job.
	 *
	 * @since 5.4.0
	 *
	 * @param object $job Job row.
	 * @return true|WP_Error
	 */
	protected function assert_job_access( $job ) {
		if ( get_current_user_id() !== (int) $job->user_id && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You cannot manage this import job.', 'add-from-server-reloaded' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
