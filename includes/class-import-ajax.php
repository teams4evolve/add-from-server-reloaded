<?php
/**
 * AJAX handlers for chunked imports.
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
 * Registers and handles import AJAX endpoints.
 *
 * @since 5.3.0
 */
class Import_Ajax {

	/**
	 * Processor instance.
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

		$actions = array(
			'afsrreloaded_create_job'   => 'create_job',
			'afsrreloaded_scan_job'     => 'scan_job',
			'afsrreloaded_process_job'  => 'process_job',
			'afsrreloaded_job_status'   => 'job_status',
			'afsrreloaded_pause_job'    => 'pause_job',
			'afsrreloaded_resume_job'   => 'resume_job',
			'afsrreloaded_cancel_job'   => 'cancel_job',
			'afsrreloaded_retry_failed' => 'retry_failed',
		);

		foreach ( $actions as $hook => $method ) {
			add_action( 'wp_ajax_' . $hook, array( $this, $method ) );
		}
	}

	/**
	 * Create import job.
	 *
	 * @since 5.3.0
	 */
	public function create_job() {
		$this->guard();

		$files   = isset( $_POST['files'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['files'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via array_map.
		$folders = isset( $_POST['folders'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['folders'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via array_map.

		$options = array(
			'background'        => ! empty( $_POST['background'] ),
			'generate_metadata' => ! isset( $_POST['generate_metadata'] ) || '0' !== (string) wp_unslash( $_POST['generate_metadata'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'chunk_size'        => isset( $_POST['chunk_size'] ) ? absint( $_POST['chunk_size'] ) : Import_Processor::DEFAULT_CHUNK_SIZE,
		);

		$result = $this->processor->create_job( $files, $folders, $options );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success( $this->processor->status_payload( $result ) );
	}

	/**
	 * Scan folders for a job.
	 *
	 * @since 5.3.0
	 */
	public function scan_job() {
		$this->guard();
		$job_id = $this->job_id_from_request();
		$this->assert_job_access( $job_id );

		$result = $this->processor->scan_chunk( $job_id );
		$this->send_result( $result );
	}

	/**
	 * Process import chunk.
	 *
	 * @since 5.3.0
	 */
	public function process_job() {
		$this->guard();
		$job_id = $this->job_id_from_request();
		$this->assert_job_access( $job_id );

		$result = $this->processor->process_chunk( $job_id );
		$this->send_result( $result );
	}

	/**
	 * Job status.
	 *
	 * @since 5.3.0
	 */
	public function job_status() {
		$this->guard();
		$job_id = $this->job_id_from_request();
		$this->assert_job_access( $job_id );

		$job = Import_Job_Repository::get_job( $job_id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Import job not found.', 'add-from-server-reloaded' ) ), 404 );
		}

		wp_send_json_success( $this->processor->status_payload( $job ) );
	}

	/**
	 * Pause job.
	 *
	 * @since 5.3.0
	 */
	public function pause_job() {
		$this->guard();
		$job_id = $this->job_id_from_request();
		$this->assert_job_access( $job_id );
		$this->send_result( $this->processor->pause_job( $job_id ) );
	}

	/**
	 * Resume job.
	 *
	 * @since 5.3.0
	 */
	public function resume_job() {
		$this->guard();
		$job_id = $this->job_id_from_request();
		$this->assert_job_access( $job_id );
		$this->send_result( $this->processor->resume_job( $job_id ) );
	}

	/**
	 * Cancel job.
	 *
	 * @since 5.3.0
	 */
	public function cancel_job() {
		$this->guard();
		$job_id = $this->job_id_from_request();
		$this->assert_job_access( $job_id );
		$this->send_result( $this->processor->cancel_job( $job_id ) );
	}

	/**
	 * Retry failed items.
	 *
	 * @since 5.3.0
	 */
	public function retry_failed() {
		$this->guard();
		$job_id = $this->job_id_from_request();
		$this->assert_job_access( $job_id );
		$this->send_result( $this->processor->retry_failed( $job_id ) );
	}

	/**
	 * Shared capability + nonce check.
	 *
	 * @since 5.3.0
	 */
	protected function guard() {
		check_ajax_referer( 'afsrreloaded_import', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to upload files.', 'add-from-server-reloaded' ) ),
				403
			);
		}
	}

	/**
	 * Read job ID from request.
	 *
	 * @since 5.3.0
	 *
	 * @return int
	 */
	protected function job_id_from_request() {
		return isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	/**
	 * Ensure current user owns the job (admins can access all).
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 */
	protected function assert_job_access( $job_id ) {
		$job = Import_Job_Repository::get_job( $job_id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Import job not found.', 'add-from-server-reloaded' ) ), 404 );
		}

		if ( (int) $job->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot manage this import job.', 'add-from-server-reloaded' ) ), 403 );
		}
	}

	/**
	 * Send processor result as JSON.
	 *
	 * @since 5.3.0
	 *
	 * @param array|\WP_Error $result Result.
	 */
	protected function send_result( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success( $result );
	}
}
