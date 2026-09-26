<?php
/**
 * FTP / FTPS client using PHP ftp extension.
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
 * Lightweight FTP client wrapper.
 *
 * @since 5.4.0
 */
class Ftp_Client {

	/**
	 * FTP host.
	 *
	 * @var string
	 */
	protected $host;

	/**
	 * FTP port.
	 *
	 * @var int
	 */
	protected $port;

	/**
	 * Use SSL (FTPS).
	 *
	 * @var bool
	 */
	protected $ssl;

	/**
	 * Connection timeout seconds.
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * Connection resource.
	 *
	 * @var resource|null
	 */
	protected $connection = null;

	/**
	 * Whether logged in.
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
	 * @param int    $port    Port (default 21).
	 * @param bool   $ssl     Use FTPS.
	 * @param int    $timeout Timeout seconds.
	 */
	public function __construct( $host, $port = 21, $ssl = false, $timeout = 30 ) {
		$this->host    = sanitize_text_field( (string) $host );
		$this->port    = max( 1, min( 65535, absint( $port ) ) );
		$this->ssl     = (bool) $ssl;
		$this->timeout = max( 5, absint( $timeout ) );
	}

	/**
	 * Open FTP connection.
	 *
	 * @since 5.4.0
	 *
	 * @return true|WP_Error
	 */
	public function connect() {
		if ( ! function_exists( 'ftp_connect' ) ) {
			return new WP_Error(
				'ftp_unavailable',
				__( 'The PHP FTP extension is not available on this server.', 'add-from-server-reloaded' )
			);
		}

		if ( $this->is_connected() ) {
			return true;
		}

		if ( $this->ssl ) {
			if ( ! function_exists( 'ftp_ssl_connect' ) ) {
				return new WP_Error(
					'ftps_unavailable',
					__( 'FTPS is not supported by the PHP FTP extension on this server.', 'add-from-server-reloaded' )
				);
			}
			$conn = @ftp_ssl_connect( $this->host, $this->port, $this->timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} else {
			$conn = @ftp_connect( $this->host, $this->port, $this->timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! $conn ) {
			return new WP_Error(
				'ftp_connect_failed',
				__( 'Could not connect to the FTP server.', 'add-from-server-reloaded' )
			);
		}

		$this->connection = $conn;

		return true;
	}

	/**
	 * Log in to FTP server.
	 *
	 * @since 5.4.0
	 *
	 * @param string $user Username.
	 * @param string $pass Password.
	 * @return true|WP_Error
	 */
	public function login( $user, $pass ) {
		if ( ! $this->is_connected() ) {
			$connected = $this->connect();
			if ( is_wp_error( $connected ) ) {
				return $connected;
			}
		}

		$user = sanitize_user( (string) $user, true );
		$pass = (string) $pass;

		if ( ! @ftp_login( $this->connection, $user, $pass ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->logged_in = false;
			return new WP_Error(
				'ftp_login_failed',
				__( 'FTP login failed. Check username and password.', 'add-from-server-reloaded' )
			);
		}

		$this->logged_in = true;

		// PassiveV must run after a successful login; many servers (including vsftpd) reject it earlier.
		if ( ! @ftp_pasv( $this->connection, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->disconnect();
			return new WP_Error(
				'ftp_passive_failed',
				__( 'Could not enable passive mode on the FTP connection.', 'add-from-server-reloaded' )
			);
		}

		return true;
	}

	/**
	 * List directory entries on remote server.
	 *
	 * @since 5.4.0
	 *
	 * @param string $remote_path Remote path.
	 * @return array|WP_Error Array of entries with name, type, path.
	 */
	public function list_dir( $remote_path ) {
		if ( ! $this->logged_in ) {
			return new WP_Error(
				'ftp_not_logged_in',
				__( 'Not connected to FTP server.', 'add-from-server-reloaded' )
			);
		}

		$remote_path = $this->normalize_remote_path( $remote_path );
		$raw         = @ftp_nlist( $this->connection, $remote_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $raw ) {
			return new WP_Error(
				'ftp_list_failed',
				__( 'Could not list remote directory.', 'add-from-server-reloaded' )
			);
		}

		$entries = array();
		foreach ( (array) $raw as $item ) {
			$name = basename( wp_normalize_path( (string) $item ) );
			if ( '' === $name || '.' === $name || '..' === $name ) {
				continue;
			}

			$full = $this->join_remote( $remote_path, $name );
			$type = 'file';

			$size = @ftp_size( $this->connection, $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( -1 === $size ) {
				$type = 'dir';
			}

			$entry = array(
				'name' => $name,
				'type' => $type,
				'path' => $full,
			);

			if ( 'file' === $type ) {
				$entry['size'] = (int) $size;
			}

			$entries[] = $entry;
		}

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
	 * Download a remote file to local path.
	 *
	 * @since 5.4.0
	 *
	 * @param string $remote_file Remote file path.
	 * @param string $local_file  Local destination.
	 * @return true|WP_Error
	 */
	public function download( $remote_file, $local_file ) {
		if ( ! $this->logged_in ) {
			return new WP_Error(
				'ftp_not_logged_in',
				__( 'Not connected to FTP server.', 'add-from-server-reloaded' )
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

		$mode = FTP_BINARY;
		if ( ! @ftp_get( $this->connection, $local_file, $remote_file, $mode ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error(
				'ftp_download_failed',
				__( 'FTP download failed.', 'add-from-server-reloaded' )
			);
		}

		return true;
	}

	/**
	 * Close FTP connection.
	 *
	 * @since 5.4.0
	 */
	public function disconnect() {
		if ( is_resource( $this->connection ) ) {
			@ftp_close( $this->connection ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$this->connection = null;
		$this->logged_in  = false;
	}

	/**
	 * Whether connection is open.
	 *
	 * @since 5.4.0
	 *
	 * @return bool
	 */
	public function is_connected() {
		return is_resource( $this->connection );
	}

	/**
	 * Normalize remote FTP path.
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
	 * @param string $base Base path.
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
