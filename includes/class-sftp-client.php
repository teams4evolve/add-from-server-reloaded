<?php
/**
 * SFTP client using PHP ssh2 extension.
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
 * Lightweight SFTP client wrapper.
 *
 * @since 5.4.0
 */
class Sftp_Client {

	/**
	 * SSH host.
	 *
	 * @var string
	 */
	protected $host;

	/**
	 * SSH port.
	 *
	 * @var int
	 */
	protected $port;

	/**
	 * Connection timeout.
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * Optional public key path.
	 *
	 * @var string
	 */
	protected $pubkey_path = '';

	/**
	 * Optional private key path.
	 *
	 * @var string
	 */
	protected $privkey_path = '';

	/**
	 * SSH connection resource.
	 *
	 * @var resource|null
	 */
	protected $connection = null;

	/**
	 * SFTP subsystem resource.
	 *
	 * @var resource|null
	 */
	protected $sftp = null;

	/**
	 * Whether authenticated.
	 *
	 * @var bool
	 */
	protected $logged_in = false;

	/**
	 * Constructor.
	 *
	 * @since 5.4.0
	 *
	 * @param string $host    Hostname.
	 * @param int    $port    Port (default 22).
	 * @param int    $timeout Timeout seconds.
	 */
	public function __construct( $host, $port = 22, $timeout = 30 ) {
		$this->host    = sanitize_text_field( (string) $host );
		$this->port    = max( 1, min( 65535, absint( $port ) ) );
		$this->timeout = max( 5, absint( $timeout ) );
	}

	/**
	 * Connect to SSH server.
	 *
	 * @since 5.4.0
	 *
	 * @param string $pubkey_path  Optional public key path.
	 * @param string $privkey_path Optional private key path.
	 * @return true|WP_Error
	 */
	public function connect( $pubkey_path = '', $privkey_path = '' ) {
		if ( ! function_exists( 'ssh2_connect' ) ) {
			return new WP_Error(
				'ssh2_required',
				__( 'The PHP ssh2 extension is required for SFTP connections.', 'add-from-server-reloaded' )
			);
		}

		if ( $this->is_connected() ) {
			return true;
		}

		$this->pubkey_path  = $pubkey_path ? wp_normalize_path( (string) $pubkey_path ) : '';
		$this->privkey_path = $privkey_path ? wp_normalize_path( (string) $privkey_path ) : '';

		$conn = @ssh2_connect( $this->host, $this->port ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $conn ) {
			return new WP_Error(
				'sftp_connect_failed',
				__( 'Could not connect to the SFTP server.', 'add-from-server-reloaded' )
			);
		}

		$this->connection = $conn;
		return true;
	}

	/**
	 * Authenticate with password or public key.
	 *
	 * @since 5.4.0
	 *
	 * @param string $user Username.
	 * @param string $pass Password (optional when using keys).
	 * @return true|WP_Error
	 */
	public function login( $user, $pass = '' ) {
		if ( ! $this->is_connected() ) {
			$connected = $this->connect( $this->pubkey_path, $this->privkey_path );
			if ( is_wp_error( $connected ) ) {
				return $connected;
			}
		}

		$user = sanitize_user( (string) $user, true );
		$pass = (string) $pass;
		$auth = false;

		if ( $this->pubkey_path && $this->privkey_path && is_readable( $this->pubkey_path ) && is_readable( $this->privkey_path ) ) {
			$auth = @ssh2_auth_pubkey_file( $this->connection, $user, $this->pubkey_path, $this->privkey_path, $pass ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} else {
			$auth = @ssh2_auth_password( $this->connection, $user, $pass ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! $auth ) {
			$this->logged_in = false;
			return new WP_Error(
				'sftp_login_failed',
				__( 'SFTP login failed. Check credentials or key paths.', 'add-from-server-reloaded' )
			);
		}

		$sftp = @ssh2_sftp( $this->connection ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $sftp ) {
			return new WP_Error(
				'sftp_subsystem_failed',
				__( 'Could not initialize SFTP subsystem.', 'add-from-server-reloaded' )
			);
		}

		$this->sftp      = $sftp;
		$this->logged_in = true;
		return true;
	}

	/**
	 * List remote directory.
	 *
	 * @since 5.4.0
	 *
	 * @param string $remote_path Remote path.
	 * @return array|WP_Error
	 */
	public function list_dir( $remote_path ) {
		if ( ! $this->logged_in || ! $this->sftp ) {
			return new WP_Error(
				'sftp_not_logged_in',
				__( 'Not connected to SFTP server.', 'add-from-server-reloaded' )
			);
		}

		$remote_path = $this->normalize_remote_path( $remote_path );
		$dir_uri     = 'ssh2.sftp://' . intval( $this->sftp ) . $remote_path;
		$handle      = @opendir( $dir_uri ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return new WP_Error(
				'sftp_list_failed',
				__( 'Could not list remote directory.', 'add-from-server-reloaded' )
			);
		}

		$entries = array();
		while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$full = $this->join_remote( $remote_path, $name );
			$stat = @ssh2_sftp_stat( $this->sftp, $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$type = ( $stat && isset( $stat['mode'] ) && ( $stat['mode'] & 0040000 ) ) ? 'dir' : 'file';

			$entry = array(
				'name' => $name,
				'type' => $type,
				'path' => $full,
			);

			if ( 'file' === $type && $stat && isset( $stat['size'] ) ) {
				$entry['size'] = (int) $stat['size'];
			}

			$entries[] = $entry;
		}

		closedir( $handle );

		usort(
			$entries,
			static function ( $a, $b ) {
				if ( $a['type'] !== $b['type'] ) {
					return 'dir' === $a['type'] ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $entries;
	}

	/**
	 * Download remote file to local path.
	 *
	 * @since 5.4.0
	 *
	 * @param string $remote_file Remote path.
	 * @param string $local_file  Local path.
	 * @return true|WP_Error
	 */
	public function download( $remote_file, $local_file ) {
		if ( ! $this->logged_in || ! $this->sftp ) {
			return new WP_Error(
				'sftp_not_logged_in',
				__( 'Not connected to SFTP server.', 'add-from-server-reloaded' )
			);
		}

		$remote_file = $this->normalize_remote_path( $remote_file );
		$local_file  = wp_normalize_path( (string) $local_file );
		$local_dir   = dirname( $local_file );

		if ( ! wp_mkdir_p( $local_dir ) ) {
			return new WP_Error(
				'local_dir_failed',
				__( 'Could not create local directory for download.', 'add-from-server-reloaded' )
			);
		}

		$remote_uri = 'ssh2.sftp://' . intval( $this->sftp ) . $remote_file;
		$in         = @fopen( $remote_uri, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $in ) {
			return new WP_Error(
				'sftp_download_failed',
				__( 'Could not open remote file for download.', 'add-from-server-reloaded' )
			);
		}

		$out = fopen( $local_file, 'wb' );
		if ( ! $out ) {
			fclose( $in );
			return new WP_Error(
				'local_write_failed',
				__( 'Could not write local file.', 'add-from-server-reloaded' )
			);
		}

		while ( ! feof( $in ) ) {
			$chunk = fread( $in, 8192 );
			if ( false === $chunk ) {
				fclose( $in );
				fclose( $out );
				wp_delete_file( $local_file );
				return new WP_Error(
					'sftp_download_failed',
					__( 'SFTP download failed while reading remote file.', 'add-from-server-reloaded' )
				);
			}
			fwrite( $out, $chunk );
		}

		fclose( $in );
		fclose( $out );

		return true;
	}

	/**
	 * Close SSH connection.
	 *
	 * @since 5.4.0
	 */
	public function disconnect() {
		$this->sftp       = null;
		$this->connection = null;
		$this->logged_in  = false;
	}

	/**
	 * Whether SSH connection is open.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public function is_connected() {
		return is_resource( $this->connection );
	}

	/**
	 * Normalize remote path.
	 *
	 * @since 5.4.0
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected function normalize_remote_path( $path ) {
		$path = wp_normalize_path( (string) $path );
		$path = preg_replace( '#\.\./#', '', $path );
		if ( '' === $path || '/' === $path ) {
			return '/';
		}
		return '/' . ltrim( $path, '/' );
	}

	/**
	 * Join remote path segments.
	 *
	 * @since 5.4.0
	 *
	 * @param string $base Base.
	 * @param string $name Name.
	 * @return string
	 */
	protected function join_remote( $base, $name ) {
		$base = rtrim( $this->normalize_remote_path( $base ), '/' );
		return $base . '/' . $name;
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		$this->disconnect();
	}
}
