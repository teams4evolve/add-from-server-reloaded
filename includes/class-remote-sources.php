<?php
/**
 * Remote source profiles (FTP/SFTP/S3) and staging import UI.
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
 * Admin UI and helpers for remote import sources.
 *
 * @since 5.4.0
 */
class Remote_Sources {

	const OPTION      = 'afsrreloaded_remote_sources';
	const PAGE        = 'add-from-server-reloaded-remote';
	const STAGING_DIR = 'afsr-remote-staging';

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
	 * Form error message.
	 *
	 * @var string
	 */
	protected $form_error = '';

	/**
	 * Whether passwords are stored without encryption.
	 *
	 * @var bool
	 */
	protected $storage_warning = false;

	/**
	 * Constructor.
	 *
	 * @since 5.4.0
	 *
	 * @param Plugin           $plugin    Plugin.
	 * @param Import_Processor $processor Processor.
	 */
	public function __construct( Plugin $plugin, Import_Processor $processor ) {
		$this->plugin    = $plugin;
		$this->processor = $processor;

		// Hooks always; is_enabled() checked at admin_menu / admin_init (after Pro filters).
		add_action( 'admin_menu', array( $this, 'register_menu' ), 36 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Whether remote sources feature is available.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	protected function is_enabled() {
		return Features::enabled( 'ftp_sftp' ) || Features::enabled( 'cloud_storage' );
	}

	/**
	 * Register admin submenu.
	 *
	 * @since 5.4.0
	 */
	public function register_menu() {
		if ( ! Capabilities::can_manage_remote() && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$hook = add_submenu_page(
			'add-from-server-reloaded',
			__( 'Remote Sources', 'add-from-server-reloaded' ),
			__( 'Remote Sources', 'add-from-server-reloaded' ),
			Capabilities::rbac_enabled() ? Capabilities::CAP_REMOTE : 'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

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
	 * Get stored profiles.
	 *
	 * @since 5.4.0
	 *
	 * @return array
	 */
	public function get_profiles() {
		$profiles = get_option( self::OPTION, array() );
		return is_array( $profiles ) ? $profiles : array();
	}

	/**
	 * Save profiles array.
	 *
	 * @since 5.4.0
	 *
	 * @param array $profiles Profiles.
	 */
	public function save_profiles( array $profiles ) {
		update_option( self::OPTION, $profiles, false );
	}

	/**
	 * Handle admin POST/GET actions.
	 *
	 * @since 5.4.0
	 */
	public function handle_actions() {
		if ( ! Capabilities::can_manage_remote() || ! $this->is_enabled() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::PAGE !== $screen->id ) {
			// Also allow early admin_init when page param matches.
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( self::PAGE !== $page ) {
				return;
			}
		}

		$redirect = admin_url( 'admin.php?page=' . self::PAGE );

		if ( isset( $_POST['afsrreloaded_save_remote'] ) ) {
			check_admin_referer( 'afsrreloaded_save_remote' );
			$this->save_profile_from_post();
			if ( ! $this->form_error ) {
				wp_safe_redirect( add_query_arg( 'message', 'saved', $redirect ) );
				exit;
			}
		}

		if ( isset( $_POST['afsrreloaded_stage_import'] ) ) {
			check_admin_referer( 'afsrreloaded_stage_import' );
			$result = $this->handle_stage_import();
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'message'   => 'stage_error',
							'error_msg' => rawurlencode( $result->get_error_message() ),
						),
						$redirect
					)
				);
				exit;
			}
			wp_safe_redirect( add_query_arg( 'message', 'stage_started', $redirect ) );
			exit;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $action || ! $id ) {
			return;
		}

		$profiles = $this->get_profiles();
		if ( ! isset( $profiles[ $id ] ) ) {
			return;
		}

		if ( 'delete' === $action ) {
			check_admin_referer( 'delete-remote-' . $id );
			unset( $profiles[ $id ] );
			$this->save_profiles( $profiles );
			wp_safe_redirect( add_query_arg( 'message', 'deleted', $redirect ) );
			exit;
		}

		if ( 'test' === $action ) {
			check_admin_referer( 'test-remote-' . $id );
			$result = $this->test_profile( $profiles[ $id ] );
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'message'   => 'test_error',
							'error_msg' => rawurlencode( $result->get_error_message() ),
						),
						$redirect
					)
				);
			} else {
				wp_safe_redirect(
					add_query_arg(
						array(
							'message'   => 'test_ok',
							'tested_id' => rawurlencode( $id ),
						),
						$redirect
					)
				);
			}
			exit;
		}
	}

	/**
	 * Render admin page.
	 *
	 * @since 5.4.0
	 */
	public function render_page() {
		if ( ! Capabilities::can_manage_remote() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'add-from-server-reloaded' ) );
		}

		if ( ! $this->is_enabled() ) {
			Pro_Locked_Screens::remote();
			return;
		}

		$profiles = $this->get_profiles();
		$edit_id  = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing  = ( $edit_id && isset( $profiles[ $edit_id ] ) ) ? $profiles[ $edit_id ] : null;
		$message  = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$notices = array(
			'saved'         => __( 'Remote source saved.', 'add-from-server-reloaded' ),
			'deleted'       => __( 'Remote source deleted.', 'add-from-server-reloaded' ),
			'test_ok'       => __( 'Connection test succeeded.', 'add-from-server-reloaded' ),
			'stage_started' => __( 'Remote files staged and import job created.', 'add-from-server-reloaded' ),
		);

		$browse_id          = '';
		$browse_path        = '';
		$browse_profile     = null;
		$browse_entries     = array();
		$browse_error       = '';
		$browse_parent_path = '';

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'browse' === $action && isset( $_GET['id'] ) ) {
			$browse_id = sanitize_key( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $profiles[ $browse_id ] ) ) {
				check_admin_referer( 'browse-remote-' . $browse_id );
				$browse_profile = $profiles[ $browse_id ];
				$browse_path    = isset( $_GET['remote_path'] )
					? sanitize_text_field( wp_unslash( $_GET['remote_path'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					: (string) ( $browse_profile['path'] ?? $browse_profile['prefix'] ?? '' );

				$list = $this->list_remote_directory( $browse_profile, $browse_path );
				if ( is_wp_error( $list ) ) {
					$browse_error = $list->get_error_message();
				} else {
					$browse_entries = $list;
				}

				$browse_parent_path = $this->remote_parent_path( $browse_path, $browse_profile );
			}
		}

		$tested_id = isset( $_GET['tested_id'] ) ? sanitize_key( wp_unslash( $_GET['tested_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$view                    = compact(
			'profiles',
			'editing',
			'edit_id',
			'message',
			'notices',
			'browse_id',
			'browse_path',
			'browse_profile',
			'browse_entries',
			'browse_error',
			'browse_parent_path',
			'tested_id'
		);
		$view['form_error']      = $this->form_error;
		$view['storage_warning'] = $this->storage_warning;
		$view['uses_plaintext']  = $this->uses_plaintext_storage();
		$view['page_slug']       = self::PAGE;

		include AFSRRELOADED_PLUGIN_DIR_PATH . 'admin/partials/remote-sources-page.php';
	}

	/**
	 * Save profile from POST data.
	 *
	 * @since 5.4.0
	 */
	protected function save_profile_from_post() {
		$profiles = $this->get_profiles();
		$edit_id  = isset( $_POST['edit_id'] ) ? sanitize_key( wp_unslash( $_POST['edit_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'ftp'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $type, array( 'ftp', 'sftp', 's3' ), true ) ) {
			$this->form_error = __( 'Invalid connection type.', 'add-from-server-reloaded' );
			return;
		}

		if ( 's3' === $type && ! Features::enabled( 'cloud_storage' ) ) {
			$this->form_error = __( 'Cloud storage is not enabled.', 'add-from-server-reloaded' );
			return;
		}

		if ( in_array( $type, array( 'ftp', 'sftp' ), true ) && ! Features::enabled( 'ftp_sftp' ) ) {
			$this->form_error = __( 'FTP/SFTP is not enabled.', 'add-from-server-reloaded' );
			return;
		}

		$id = ( $edit_id && isset( $profiles[ $edit_id ] ) ) ? $edit_id : 'remote_' . strtolower( wp_generate_password( 8, false, false ) );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $name ) {
			$this->form_error = __( 'Name is required.', 'add-from-server-reloaded' );
			return;
		}

		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- verified before save_profile_from_post() is called.
		$secret   = isset( $_POST['secret_key'] ) ? (string) wp_unslash( $_POST['secret_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- verified before save_profile_from_post() is called.

		if ( $edit_id && '' === $password && isset( $profiles[ $edit_id ]['password'] ) ) {
			$password_enc = $profiles[ $edit_id ]['password'];
		} else {
			$password_enc = $this->encrypt_secret( $password );
		}

		if ( $edit_id && '' === $secret && isset( $profiles[ $edit_id ]['secret_key'] ) ) {
			$secret_enc = $profiles[ $edit_id ]['secret_key'];
		} else {
			$secret_enc = $this->encrypt_secret( $secret );
		}

		$profile = array(
			'id'         => $id,
			'type'       => $type,
			'name'       => $name,
			'host'       => isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'port'       => isset( $_POST['port'] ) ? absint( $_POST['port'] ) : ( 'sftp' === $type ? 22 : 21 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified before save_profile_from_post() is called.
			'username'   => isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'password'   => $password_enc,
			'path'       => isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'ssl'        => ! empty( $_POST['ssl'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'bucket'     => isset( $_POST['bucket'] ) ? sanitize_text_field( wp_unslash( $_POST['bucket'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'endpoint'   => isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'region'     => isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : 'us-east-1', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'access_key' => isset( $_POST['access_key'] ) ? sanitize_text_field( wp_unslash( $_POST['access_key'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'secret_key' => $secret_enc,
			'path_style' => ! empty( $_POST['path_style'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);

		$profile['prefix'] = $profile['path'];
		$profiles[ $id ]   = $profile;
		$this->save_profiles( $profiles );
	}

	/**
	 * Test a stored profile connection.
	 *
	 * @since 5.4.0
	 *
	 * @param array $profile Profile.
	 * @return true|WP_Error
	 */
	public function test_profile( array $profile ) {
		$client = $this->build_client( $profile );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$type = $profile['type'] ?? 'ftp';

		if ( 's3' === $type && $client instanceof S3_Client ) {
			return $client->test_connection();
		}

		if ( $client instanceof Ftp_Client || $client instanceof Sftp_Client ) {
			$path = $profile['path'] ?? '/';
			$list = $client->list_dir( $path ? $path : '/' );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			return true;
		}

		return new WP_Error( 'unknown_type', __( 'Unknown remote source type.', 'add-from-server-reloaded' ) );
	}

	/**
	 * Stage remote files locally and create import job.
	 *
	 * @since 5.4.0
	 *
	 * @return true|WP_Error
	 */
	protected function handle_stage_import() {
		$profile_id  = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$remote_path = isset( $_POST['remote_path'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_path'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$background  = ! empty( $_POST['background'] ) && Features::enabled( 'background' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$remote_files = array();
		if ( isset( $_POST['remote_files'] ) && is_array( $_POST['remote_files'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( wp_unslash( $_POST['remote_files'] ) as $file_path ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- verified before handle_stage_import() is called.
				$file_path = sanitize_text_field( (string) $file_path );
				if ( '' !== $file_path ) {
					$remote_files[] = $file_path;
				}
			}
		}

		$remote_dirs = array();
		if ( isset( $_POST['remote_dirs'] ) && is_array( $_POST['remote_dirs'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( wp_unslash( $_POST['remote_dirs'] ) as $dir_path ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- verified before handle_stage_import() is called.
				$dir_path = sanitize_text_field( (string) $dir_path );
				if ( '' !== $dir_path ) {
					$remote_dirs[] = $dir_path;
				}
			}
		}

		$profiles = $this->get_profiles();
		if ( ! isset( $profiles[ $profile_id ] ) ) {
			return new WP_Error( 'missing_profile', __( 'Remote source not found.', 'add-from-server-reloaded' ) );
		}

		$has_selection = ! empty( $remote_files ) || ! empty( $remote_dirs );
		if ( ! $has_selection && '' === $remote_path ) {
			return new WP_Error( 'nothing_selected', __( 'Select files or folders to stage, or provide a remote path.', 'add-from-server-reloaded' ) );
		}

		$staging_rel = self::STAGING_DIR . '/' . $profile_id;
		$staging     = $this->resolve_staging_dir( $staging_rel );
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}

		if ( ! wp_mkdir_p( $staging['absolute'] ) ) {
			return new WP_Error( 'staging_failed', __( 'Could not create staging directory.', 'add-from-server-reloaded' ) );
		}

		if ( $has_selection ) {
			$downloaded = $this->download_selected( $profiles[ $profile_id ], $remote_files, $remote_dirs, $staging['absolute'], $remote_path );
		} else {
			$downloaded = $this->download_remote_tree( $profiles[ $profile_id ], $remote_path, $staging['absolute'] );
		}

		if ( is_wp_error( $downloaded ) ) {
			return $downloaded;
		}

		if ( $downloaded < 1 ) {
			return new WP_Error( 'nothing_staged', __( 'No files were downloaded from the remote path.', 'add-from-server-reloaded' ) );
		}

		$folder_rel = $staging_rel;

		$options = array(
			'background' => $background,
		);

		$job = $this->processor->create_job( array(), array( $folder_rel ), $options );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return true;
	}

	/**
	 * List immediate children of a remote directory.
	 *
	 * @since 5.4.0
	 *
	 * @param array  $profile     Profile.
	 * @param string $remote_path Remote path or S3 prefix.
	 * @return array|WP_Error
	 */
	protected function list_remote_directory( array $profile, $remote_path ) {
		$client = $this->build_client( $profile );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$type        = $profile['type'] ?? 'ftp';
		$remote_path = trim( wp_normalize_path( (string) $remote_path ), '/' );

		if ( 's3' === $type && $client instanceof S3_Client ) {
			$prefix = $remote_path ? trailingslashit( $remote_path ) : '';
			$list   = $client->list_objects( $prefix, 1000 );
			if ( is_wp_error( $list ) ) {
				return $list;
			}

			return $this->filter_s3_immediate_children( $list, $prefix );
		}

		if ( $client instanceof Ftp_Client || $client instanceof Sftp_Client ) {
			$list_path = $remote_path ? '/' . $remote_path : '/';
			return $client->list_dir( $list_path );
		}

		return new WP_Error( 'unknown_type', __( 'Unknown remote source type.', 'add-from-server-reloaded' ) );
	}

	/**
	 * Reduce flat S3 listing to immediate children under a prefix.
	 *
	 * @since 5.4.0
	 *
	 * @param array  $entries Raw list entries.
	 * @param string $prefix  Current prefix (with trailing slash when non-empty).
	 * @return array
	 */
	protected function filter_s3_immediate_children( array $entries, $prefix ) {
		$dirs  = array();
		$files = array();

		foreach ( $entries as $entry ) {
			$name = $entry['name'] ?? '';
			$path = $entry['path'] ?? '';
			$type = $entry['type'] ?? 'file';

			if ( 'dir' === $type ) {
				$dirs[ $path ] = $entry;
				continue;
			}

			if ( '' === $prefix ) {
				$relative = ltrim( $path, '/' );
			} else {
				if ( ! str_starts_with( $path, $prefix ) ) {
					continue;
				}
				$relative = substr( $path, strlen( $prefix ) );
			}

			if ( str_contains( $relative, '/' ) ) {
				$segment = strtok( $relative, '/' );
				if ( '' !== $segment ) {
					$dir_path          = $prefix . $segment . '/';
					$dirs[ $dir_path ] = array(
						'name' => $segment,
						'type' => 'dir',
						'path' => $dir_path,
					);
				}
				continue;
			}

			$files[ $path ] = $entry;
		}

		$merged = array_merge( array_values( $dirs ), array_values( $files ) );
		usort(
			$merged,
			static function ( $a, $b ) {
				if ( ( $a['type'] ?? 'file' ) !== ( $b['type'] ?? 'file' ) ) {
					return 'dir' === ( $a['type'] ?? 'file' ) ? -1 : 1;
				}
				return strcasecmp( $a['name'] ?? '', $b['name'] ?? '' );
			}
		);

		return $merged;
	}

	/**
	 * Parent path for browse navigation.
	 *
	 * @since 5.4.0
	 *
	 * @param string $remote_path Remote path.
	 * @param array  $profile     Profile.
	 * @return string
	 */
	protected function remote_parent_path( $remote_path, array $profile ) {
		$remote_path = trim( wp_normalize_path( (string) $remote_path ), '/' );
		if ( '' === $remote_path ) {
			return '';
		}

		$default = trim( wp_normalize_path( (string) ( $profile['path'] ?? $profile['prefix'] ?? '' ) ), '/' );
		if ( $default && $remote_path === $default ) {
			return '';
		}

		$parts = explode( '/', $remote_path );
		array_pop( $parts );
		return implode( '/', $parts );
	}

	/**
	 * Download selected remote files and directories into local staging.
	 *
	 * @since 5.4.0
	 *
	 * @param array  $profile      Profile.
	 * @param array  $files        Selected file paths.
	 * @param array  $dirs         Selected directory paths.
	 * @param string $local_base   Local staging directory.
	 * @param string $remote_base  Browse base path for relative layout.
	 * @return int|WP_Error Number of files downloaded.
	 */
	protected function download_selected( array $profile, array $files, array $dirs, $local_base, $remote_base = '' ) {
		$client = $this->build_client( $profile );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$type        = $profile['type'] ?? 'ftp';
		$remote_base = trim( wp_normalize_path( (string) $remote_base ), '/' );
		$max_files   = (int) apply_filters( 'afsrreloaded_remote_stage_max_files', 500 );
		$count       = 0;

		foreach ( $files as $file_path ) {
			if ( $count >= $max_files ) {
				break;
			}

			$file_path = trim( wp_normalize_path( sanitize_text_field( (string) $file_path ) ), '/' );
			if ( '' === $file_path ) {
				continue;
			}

			$rel = $this->relative_remote_path( $file_path, $remote_base );
			if ( '' === $rel ) {
				$rel = basename( $file_path );
			}

			$local_file = wp_normalize_path( trailingslashit( $local_base ) . $rel );
			if ( ! wp_mkdir_p( dirname( $local_file ) ) ) {
				return new WP_Error( 'local_dir_failed', __( 'Could not create local directory for staged file.', 'add-from-server-reloaded' ) );
			}

			if ( 's3' === $type && $client instanceof S3_Client ) {
				$result = $client->download_object( $file_path, $local_file );
			} else {
				$result = $client->download( '/' . ltrim( $file_path, '/' ), $local_file );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			++$count;
		}

		foreach ( $dirs as $dir_path ) {
			if ( $count >= $max_files ) {
				break;
			}

			$dir_path = trim( wp_normalize_path( sanitize_text_field( (string) $dir_path ) ), '/' );
			if ( '' === $dir_path ) {
				continue;
			}

			$rel = $this->relative_remote_path( $dir_path, $remote_base );
			if ( '' === $rel ) {
				$rel = basename( $dir_path );
			}

			$local_dir  = wp_normalize_path( trailingslashit( $local_base ) . $rel );
			$downloaded = $this->download_remote_tree( $profile, $dir_path, $local_dir );
			if ( is_wp_error( $downloaded ) ) {
				return $downloaded;
			}

			$count += (int) $downloaded;
		}

		return $count;
	}

	/**
	 * Path relative to a browse base, for local staging layout.
	 *
	 * @since 5.4.0
	 *
	 * @param string $path Remote path.
	 * @param string $base Browse base path.
	 * @return string
	 */
	protected function relative_remote_path( $path, $base ) {
		$path = trim( wp_normalize_path( (string) $path ), '/' );
		$base = trim( wp_normalize_path( (string) $base ), '/' );

		if ( '' === $path ) {
			return '';
		}

		if ( '' !== $base && str_starts_with( $path, $base . '/' ) ) {
			return substr( $path, strlen( $base ) + 1 );
		}

		if ( '' !== $base && $path === $base ) {
			return basename( $path );
		}

		return $path;
	}

	/**
	 * Recursively download remote path into local staging directory.
	 *
	 * @since 5.4.0
	 *
	 * @param array  $profile     Profile.
	 * @param string $remote_path Remote path.
	 * @param string $local_base  Local base directory.
	 * @return int|WP_Error Number of files downloaded.
	 */
	protected function download_remote_tree( array $profile, $remote_path, $local_base ) {
		$client = $this->build_client( $profile );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$max_files   = (int) apply_filters( 'afsrreloaded_remote_stage_max_files', 500 );
		$count       = 0;
		$type        = $profile['type'] ?? 'ftp';
		$remote_path = trim( wp_normalize_path( (string) $remote_path ), '/' );

		$queue = array(
			array(
				'remote' => $remote_path,
				'local'  => $local_base,
			),
		);

		while ( ! empty( $queue ) && $count < $max_files ) {
			$current = array_shift( $queue );
			$remote  = $current['remote'];
			$local   = $current['local'];

			if ( 's3' === $type && $client instanceof S3_Client ) {
				$prefix = $remote ? trailingslashit( $remote ) : '';
				$list   = $client->list_objects( $prefix, min( 100, $max_files - $count ) );
			} else {
				$list = $client->list_dir( $remote ? '/' . $remote : '/' );
			}

			if ( is_wp_error( $list ) ) {
				return $list;
			}

			foreach ( $list as $entry ) {
				if ( $count >= $max_files ) {
					break 2;
				}

				$name = $entry['name'] ?? '';
				if ( '' === $name ) {
					continue;
				}

				if ( 'dir' === ( $entry['type'] ?? 'file' ) ) {
					$child_remote = isset( $entry['path'] ) ? ltrim( wp_normalize_path( $entry['path'] ), '/' ) : trim( $remote . '/' . $name, '/' );
					$child_local  = trailingslashit( $local ) . $name;
					wp_mkdir_p( $child_local );
					$queue[] = array(
						'remote' => $child_remote,
						'local'  => $child_local,
					);
					continue;
				}

				$remote_file = isset( $entry['path'] ) ? (string) $entry['path'] : ( $remote ? $remote . '/' . $name : $name );
				$local_file  = trailingslashit( $local ) . $name;

				if ( 's3' === $type && $client instanceof S3_Client ) {
					$result = $client->download_object( $remote_file, $local_file );
				} else {
					$result = $client->download( '/' . ltrim( wp_normalize_path( $remote_file ), '/' ), $local_file );
				}

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				++$count;
			}
		}

		return $count;
	}

	/**
	 * Build client instance from profile.
	 *
	 * @since 5.4.0
	 *
	 * @param array $profile Profile.
	 * @return Ftp_Client|Sftp_Client|S3_Client|WP_Error
	 */
	protected function build_client( array $profile ) {
		$type = $profile['type'] ?? 'ftp';

		if ( 's3' === $type ) {
			$client = new S3_Client(
				$profile['endpoint'] ?? '',
				$profile['region'] ?? 'us-east-1',
				$profile['bucket'] ?? '',
				$profile['access_key'] ?? '',
				$this->decrypt_secret( $profile['secret_key'] ?? '' ),
				! empty( $profile['path_style'] )
			);
			return $client;
		}

		$host = $profile['host'] ?? '';
		$port = isset( $profile['port'] ) ? absint( $profile['port'] ) : ( 'sftp' === $type ? 22 : 21 );
		$user = $profile['username'] ?? '';
		$pass = $this->decrypt_secret( $profile['password'] ?? '' );

		if ( 'sftp' === $type ) {
			$client = new Sftp_Client( $host, $port );
			$conn   = $client->connect();
			if ( is_wp_error( $conn ) ) {
				return $conn;
			}
			$login = $client->login( $user, $pass );
			return is_wp_error( $login ) ? $login : $client;
		}

		$client = new Ftp_Client( $host, $port, ! empty( $profile['ssl'] ) );
		$conn   = $client->connect();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		$login = $client->login( $user, $pass );
		return is_wp_error( $login ) ? $login : $client;
	}

	/**
	 * Resolve staging directory under plugin root.
	 *
	 * @since 5.4.0
	 *
	 * @param string $relative Relative path.
	 * @return array|WP_Error Keys absolute, relative, root.
	 */
	protected function resolve_staging_dir( $relative ) {
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
			return new WP_Error( 'security_path', __( 'Staging path is outside the allowed root.', 'add-from-server-reloaded' ) );
		}

		$candidate = trailingslashit( $root_real ) . $relative;
		$realpath  = realpath( dirname( $candidate ) );
		if ( $realpath && ! str_starts_with( wp_normalize_path( $realpath ), $root_real ) ) {
			return new WP_Error( 'security_path', __( 'Staging path is outside the allowed root.', 'add-from-server-reloaded' ) );
		}

		return array(
			'root'     => $root_real,
			'relative' => $relative,
			'absolute' => wp_normalize_path( $candidate ),
		);
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * @since 5.4.0
	 *
	 * @param string $plain Plaintext.
	 * @return string
	 */
	protected function encrypt_secret( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}

		if ( function_exists( 'wp_encrypt' ) ) {
			$encrypted = wp_encrypt( $plain );
			if ( is_string( $encrypted ) && '' !== $encrypted ) {
				return 'wp:' . $encrypted;
			}
		}

		if ( defined( 'AUTH_KEY' ) && AUTH_KEY && function_exists( 'openssl_encrypt' ) ) {
			$key    = hash( 'sha256', AUTH_KEY, true );
			$iv     = random_bytes( 16 );
			$cipher = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $cipher ) {
				return 'enc:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}

		$this->storage_warning = true;
		return 'plain:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt stored secret.
	 *
	 * @since 5.4.0
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	protected function decrypt_secret( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}

		if ( str_starts_with( $stored, 'wp:' ) && function_exists( 'wp_decrypt' ) ) {
			$decrypted = wp_decrypt( substr( $stored, 3 ) );
			return is_string( $decrypted ) ? $decrypted : '';
		}

		if ( str_starts_with( $stored, 'enc:' ) && defined( 'AUTH_KEY' ) && AUTH_KEY && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( is_string( $raw ) && strlen( $raw ) > 16 ) {
				$iv     = substr( $raw, 0, 16 );
				$cipher = substr( $raw, 16 );
				$key    = hash( 'sha256', AUTH_KEY, true );
				$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
				if ( false !== $plain ) {
					return $plain;
				}
			}
		}

		if ( str_starts_with( $stored, 'plain:' ) ) {
			$decoded = base64_decode( substr( $stored, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return is_string( $decoded ) ? $decoded : '';
		}

		return $stored;
	}

	/**
	 * Whether any profile uses plaintext storage.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	protected function uses_plaintext_storage() {
		foreach ( $this->get_profiles() as $profile ) {
			foreach ( array( 'password', 'secret_key' ) as $field ) {
				if ( ! empty( $profile[ $field ] ) && str_starts_with( (string) $profile[ $field ], 'plain:' ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
