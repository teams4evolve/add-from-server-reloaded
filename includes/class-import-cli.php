<?php
/**
 * WP-CLI commands for Add From Server Lite.
 *
 * @package AFSRReloaded
 * @since   5.4.0
 */

namespace AFSRReloaded;

use WP_CLI;
use WP_Error;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers wp afsrreloaded commands when WP-CLI and Pro feature are enabled.
 *
 * @since 5.4.0
 */
class Import_Cli {

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
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$this->plugin    = $plugin;
		$this->processor = $processor;
		$this->scheduler = $scheduler;

		// Register always; each command checks Features::enabled( 'wp_cli' ) at runtime
		// because Pro feature filters register after Free bootstraps.
		WP_CLI::add_command( 'afsrreloaded import', array( $this, 'cmd_import' ) );
		WP_CLI::add_command( 'afsrreloaded list-files', array( $this, 'cmd_list_files' ) );
		WP_CLI::add_command( 'afsrreloaded schedules', array( $this, 'cmd_schedules' ) );
	}

	/**
	 * Ensure WP-CLI Pro feature is unlocked.
	 *
	 * @since 5.4.0
	 */
	protected function require_cli_feature() {
		if ( Features::enabled( 'wp_cli' ) ) {
			return;
		}

		WP_CLI::error( 'WP-CLI support requires Add From Server Pro with a valid license.' );
	}

	/**
	 * Create and optionally run an import job.
	 *
	 * ## OPTIONS
	 *
	 * [--files=<csv>]
	 * : Comma-separated relative file paths under root.
	 *
	 * [--folders=<csv>]
	 * : Comma-separated relative folder paths under root.
	 *
	 * [--background]
	 * : Queue background processing via WP-Cron (Pro).
	 *
	 * [--preserve]
	 * : Preserve folder structure in uploads (Pro).
	 *
	 * ## EXAMPLES
	 *
	 *     wp afsrreloaded import --folders=incoming/media
	 *     wp afsrreloaded import --files=photo.jpg,video.mp4
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function cmd_import( $args, $assoc_args ) {
		$this->require_cli_feature();

		$files   = $this->csv_arg( $assoc_args, 'files' );
		$folders = $this->csv_arg( $assoc_args, 'folders' );

		if ( empty( $files ) && empty( $folders ) ) {
			WP_CLI::error( __( 'Provide --files and/or --folders.', 'add-from-server-reloaded' ) );
		}

		$options = array(
			'background' => ! empty( $assoc_args['background'] ) && Features::enabled( 'background' ),
		);

		if ( ! empty( $assoc_args['preserve'] ) && Features::enabled( 'folder_preserve' ) ) {
			$options['preserve_structure'] = true;
		}

		$job = $this->processor->create_job( $files, $folders, $options );
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		WP_CLI::log(
			sprintf(
				/* translators: %d: job id */
				__( 'Created import job #%d.', 'add-from-server-reloaded' ),
				(int) $job->id
			)
		);

		if ( $options['background'] ) {
			Import_Cron::ensure_scheduled();
			Import_Cron::schedule_soon();
			WP_CLI::success( __( 'Background import scheduled.', 'add-from-server-reloaded' ) );
			return;
		}

		$this->run_job_to_completion( (int) $job->id );
	}

	/**
	 * List files under the plugin root.
	 *
	 * ## OPTIONS
	 *
	 * [--path=<rel>]
	 * : Relative directory under root (default: root).
	 *
	 * ## EXAMPLES
	 *
	 *     wp afsrreloaded list-files
	 *     wp afsrreloaded list-files --path=incoming
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function cmd_list_files( $args, $assoc_args ) {
		$this->require_cli_feature();

		$relative = isset( $assoc_args['path'] ) ? (string) $assoc_args['path'] : '';
		$resolved = $this->resolve_under_root( $relative );

		if ( is_wp_error( $resolved ) ) {
			WP_CLI::error( $resolved->get_error_message() );
		}

		if ( ! is_dir( $resolved['absolute'] ) ) {
			WP_CLI::error( __( 'The requested path is not a directory.', 'add-from-server-reloaded' ) );
		}

		$globbed = glob( trailingslashit( $resolved['absolute'] ) . '*' );
		$nodes   = is_array( $globbed ) ? $globbed : array();
		sort( $nodes, SORT_STRING );

		$table = array();
		foreach ( $nodes as $node ) {
			$name = basename( $node );
			if ( '' === $name || '.' === $name[0] ) {
				continue;
			}

			$is_dir = is_dir( $node );
			if ( ! $is_dir && ( ! is_file( $node ) || $this->plugin->is_restricted_file( $node ) ) ) {
				continue;
			}

			$rel     = ltrim( str_replace( $resolved['root'], '', wp_normalize_path( $node ) ), '/' );
			$table[] = array(
				'type' => $is_dir ? 'dir' : 'file',
				'path' => $rel,
				'size' => $is_dir ? '' : (string) filesize( $node ),
			);
		}

		if ( empty( $table ) ) {
			WP_CLI::log( __( 'No entries found.', 'add-from-server-reloaded' ) );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $table, array( 'type', 'path', 'size' ) );
	}

	/**
	 * Manage scheduled imports.
	 *
	 * ## SUBCOMMANDS
	 *
	 * list
	 * : List all schedules.
	 *
	 * run <id>
	 * : Manually run a schedule by ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp afsrreloaded schedules list
	 *     wp afsrreloaded schedules run sched_abc123
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function cmd_schedules( $args, $assoc_args ) {
		$this->require_cli_feature();

		if ( ! $this->scheduler || ! Features::enabled( 'scheduled_imports' ) ) {
			WP_CLI::error( __( 'Scheduled imports are not available.', 'add-from-server-reloaded' ) );
		}

		$sub = isset( $args[0] ) ? sanitize_key( $args[0] ) : 'list';

		if ( 'list' === $sub ) {
			$schedules = $this->scheduler->get_schedules();
			if ( empty( $schedules ) ) {
				WP_CLI::log( __( 'No schedules found.', 'add-from-server-reloaded' ) );
				return;
			}

			$table = array();
			foreach ( $schedules as $id => $schedule ) {
				$table[] = array(
					'id'        => $id,
					'name'      => $schedule['name'] ?? $id,
					'folder'    => $schedule['folder'] ?? '',
					'frequency' => $schedule['frequency'] ?? 'daily',
					'active'    => ! empty( $schedule['active'] ) ? 'yes' : 'no',
				);
			}

			WP_CLI\Utils\format_items( 'table', $table, array( 'id', 'name', 'folder', 'frequency', 'active' ) );
			return;
		}

		if ( 'run' === $sub ) {
			$id = isset( $args[1] ) ? sanitize_key( $args[1] ) : '';
			if ( ! $id ) {
				WP_CLI::error( __( 'Schedule ID is required.', 'add-from-server-reloaded' ) );
			}

			$result = $this->scheduler->run_schedule( $id, true );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			WP_CLI::success(
				sprintf(
					/* translators: %s: schedule id */
					__( 'Schedule %s started.', 'add-from-server-reloaded' ),
					$id
				)
			);
			return;
		}

		WP_CLI::error( __( 'Unknown subcommand. Use list or run.', 'add-from-server-reloaded' ) );
	}

	/**
	 * Process scan + import chunks until the job completes.
	 *
	 * @since 5.4.0
	 *
	 * @param int $job_id Job ID.
	 */
	protected function run_job_to_completion( $job_id ) {
		$max_loops = (int) apply_filters( 'afsrreloaded_cli_max_loops', 10000 );
		$loops     = 0;

		while ( $loops < $max_loops ) {
			++$loops;
			$job = Import_Job_Repository::get_job( $job_id );
			if ( ! $job ) {
				WP_CLI::error( __( 'Import job not found.', 'add-from-server-reloaded' ) );
			}

			if ( in_array( $job->status, array( 'completed', 'cancelled', 'failed', 'paused' ), true ) ) {
				break;
			}

			if ( ! empty( $job->scan_queue ) || 'scanning' === $job->status ) {
				$result = $this->processor->scan_chunk( $job_id );
			} else {
				$result = $this->processor->process_chunk( $job_id );
			}

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			if ( ! empty( $result['is_complete'] ) ) {
				break;
			}

			WP_CLI::log(
				sprintf(
					/* translators: 1: percent, 2: status */
					__( 'Progress: %1$s%% (%2$s)', 'add-from-server-reloaded' ),
					(string) ( $result['percent'] ?? 0 ),
					(string) ( $result['status'] ?? '' )
				)
			);
		}

		$final = Import_Job_Repository::get_job( $job_id );
		if ( $final ) {
			$payload = $this->processor->status_payload( $final );
			WP_CLI::success(
				sprintf(
					/* translators: 1: imported count, 2: errors count */
					__( 'Import finished. Imported: %1$d, Errors: %2$d.', 'add-from-server-reloaded' ),
					(int) $payload['imported'],
					(int) $payload['errors']
				)
			);
		}
	}

	/**
	 * Parse comma-separated CLI argument.
	 *
	 * @since 5.4.0
	 *
	 * @param array  $assoc_args Associative args.
	 * @param string $key        Key.
	 * @return string[]
	 */
	protected function csv_arg( $assoc_args, $key ) {
		if ( empty( $assoc_args[ $key ] ) ) {
			return array();
		}

		$parts = array_map( 'trim', explode( ',', (string) $assoc_args[ $key ] ) );
		return array_values( array_filter( array_map( 'sanitize_text_field', $parts ) ) );
	}

	/**
	 * Resolve path under root (same rules as REST).
	 *
	 * @since 5.4.0
	 *
	 * @param string $relative Relative path.
	 * @return array|WP_Error
	 */
	protected function resolve_under_root( $relative ) {
		$root = $this->plugin->get_root();
		if ( ! $root ) {
			return new WP_Error( 'no_root', __( 'Unable to determine root directory.', 'add-from-server-reloaded' ) );
		}

		$root_real = realpath( $root );
		if ( ! $root_real ) {
			return new WP_Error( 'no_root', __( 'Root directory is not accessible.', 'add-from-server-reloaded' ) );
		}

		$root_real = wp_normalize_path( $root_real );
		$relative  = ltrim( wp_normalize_path( (string) $relative ), '/' );

		if ( str_contains( $relative, '..' ) ) {
			return new WP_Error( 'security_path', __( 'Security error: file is outside the allowed directory.', 'add-from-server-reloaded' ) );
		}

		$candidate = $relative ? trailingslashit( $root_real ) . $relative : $root_real;
		$realpath  = realpath( $candidate );

		if ( ! $realpath || ! str_starts_with( wp_normalize_path( $realpath ), $root_real ) ) {
			return new WP_Error( 'security_path', __( 'Security error: file is outside the allowed directory.', 'add-from-server-reloaded' ) );
		}

		return array(
			'root'     => $root_real,
			'relative' => $relative,
			'absolute' => wp_normalize_path( $realpath ),
		);
	}
}
