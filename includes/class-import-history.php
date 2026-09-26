<?php
/**
 * Import history admin screen.
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
 * Renders import history and job details.
 *
 * @since 5.3.0
 */
class Import_History {

	/**
	 * Processor for status helpers.
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
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_afsrreloaded_delete_job', array( $this, 'handle_delete_job' ) );
		add_action( 'admin_post_afsrreloaded_clear_history', array( $this, 'handle_clear_history' ) );
		add_action( 'admin_post_afsrreloaded_retry_failed_job', array( $this, 'handle_retry_failed_job' ) );
		add_action( 'admin_post_afsrreloaded_continue_job', array( $this, 'handle_continue_job' ) );
	}

	/**
	 * Register submenu.
	 *
	 * @since 5.3.0
	 */
	public function register_menu() {
		$cap = Capabilities::rbac_enabled() ? Capabilities::CAP_HISTORY : 'upload_files';

		$hook = add_submenu_page(
			'add-from-server-reloaded',
			__( 'Import History', 'add-from-server-reloaded' ),
			__( 'Import History', 'add-from-server-reloaded' ),
			$cap,
			'add-from-server-reloaded-history',
			array( $this, 'render_page' )
		);

		add_action(
			'load-' . $hook,
			static function () {
				wp_enqueue_style( 'add-from-server-reloaded' );
				Pro_Teaser::enqueue_locked_ui_assets();
			}
		);
	}

	/**
	 * Delete a job (admin-post).
	 *
	 * @since 5.3.0
	 */
	public function handle_delete_job() {
		if ( ! Capabilities::can_manage_history() ) {
			wp_die( esc_html__( 'You do not have permission.', 'add-from-server-reloaded' ) );
		}

		check_admin_referer( 'afsrreloaded_delete_job' );

		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;
		$job    = Import_Job_Repository::get_job( $job_id );

		if ( ! $job ) {
			wp_safe_redirect( admin_url( 'admin.php?page=add-from-server-reloaded-history' ) );
			exit;
		}

		if ( get_current_user_id() !== (int) $job->user_id && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot delete this import job.', 'add-from-server-reloaded' ) );
		}

		Import_Job_Repository::delete_job( $job_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'add-from-server-reloaded-history',
					'deleted' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Clear import history jobs (admin-post).
	 *
	 * @since 5.4.4
	 */
	public function handle_clear_history() {
		if ( ! Capabilities::can_manage_history() ) {
			wp_die( esc_html__( 'You do not have permission.', 'add-from-server-reloaded' ) );
		}

		check_admin_referer( 'afsrreloaded_clear_history' );

		$user_id = current_user_can( 'manage_options' ) ? null : get_current_user_id();
		Import_Job_Repository::delete_jobs( $user_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'add-from-server-reloaded-history',
					'cleared' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Retry failed items for a job (History screen).
	 *
	 * @since 5.4.1
	 */
	public function handle_retry_failed_job() {
		if ( ! Capabilities::can_manage_history() && ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'add-from-server-reloaded' ) );
		}

		if ( ! Features::enabled( 'queue_controls' ) ) {
			wp_die( esc_html__( 'Retry requires Add From Server Reloaded Pro.', 'add-from-server-reloaded' ) );
		}

		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;
		check_admin_referer( 'afsrreloaded_retry_failed_job_' . $job_id );

		$job = Import_Job_Repository::get_job( $job_id );
		if ( ! $job ) {
			wp_safe_redirect( admin_url( 'admin.php?page=add-from-server-reloaded-history' ) );
			exit;
		}

		if ( get_current_user_id() !== (int) $job->user_id && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage this import job.', 'add-from-server-reloaded' ) );
		}

		$result = $this->processor->retry_failed( $job_id );
		if ( ! is_wp_error( $result ) && Features::enabled( 'background' ) ) {
			Import_Cron::ensure_scheduled();
			Import_Cron::schedule_soon();
			Import_Cron::process_now( $this->processor );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'add-from-server-reloaded-history',
					'job_id' => $job_id,
					'retried' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Continue a pending/scanning background job from History.
	 *
	 * @since 5.4.1
	 */
	public function handle_continue_job() {
		if ( ! Capabilities::can_manage_history() && ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'add-from-server-reloaded' ) );
		}

		if ( ! Features::enabled( 'background' ) ) {
			wp_die( esc_html__( 'Background processing requires Add From Server Reloaded Pro.', 'add-from-server-reloaded' ) );
		}

		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;
		check_admin_referer( 'afsrreloaded_continue_job_' . $job_id );

		$job = Import_Job_Repository::get_job( $job_id );
		if ( ! $job ) {
			wp_safe_redirect( admin_url( 'admin.php?page=add-from-server-reloaded-history' ) );
			exit;
		}

		if ( get_current_user_id() !== (int) $job->user_id && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage this import job.', 'add-from-server-reloaded' ) );
		}

		Import_Cron::ensure_scheduled();
		Import_Cron::schedule_soon();
		Import_Cron::process_now( $this->processor );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'add-from-server-reloaded-history',
					'job_id'    => $job_id,
					'continued' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render history page.
	 *
	 * @since 5.3.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'upload_files' ) && ! Capabilities::can_manage_history() ) {
			return;
		}

		if ( ! Features::enabled( 'history' ) ) {
			Pro_Locked_Screens::history();
			return;
		}

		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap afsr-admin-wrap">';
		echo '<h1>' . esc_html__( 'Import History', 'add-from-server-reloaded' ) . '</h1>';
		echo '<div id="afsr-admin-app" class="afsr-wrap afsr-pro-page">';
		echo '<header class="afsr-page-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">';
		echo '<div>';
		echo '<h2 class="afsr-page-title">' . esc_html__( 'Import History', 'add-from-server-reloaded' ) . '</h2>';
		echo '<p class="afsr-page-subtitle">' . esc_html__( 'Every import job, with per-file results.', 'add-from-server-reloaded' ) . '</p>';
		echo '</div>';
		if ( ! $job_id && Capabilities::can_manage_history() ) {
			$clear_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=afsrreloaded_clear_history' ),
				'afsrreloaded_clear_history'
			);
			echo '<a class="afsr-btn afsr-btn-outline" href="' . esc_url( $clear_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete all import history records? This cannot be undone.', 'add-from-server-reloaded' ) ) . '\');">' . esc_html__( 'Clear history', 'add-from-server-reloaded' ) . '</a>';
		}
		echo '</header>';

		if ( ! empty( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Import job deleted.', 'add-from-server-reloaded' ) . '</p></div>';
		}

		if ( ! empty( $_GET['cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Import history cleared.', 'add-from-server-reloaded' ) . '</p></div>';
		}

		if ( $job_id ) {
			$this->render_job_detail( $job_id );
		} else {
			$this->render_job_list();
		}

		echo '</div></div>';
	}

	/**
	 * Render job list table.
	 *
	 * @since 5.3.0
	 */
	protected function render_job_list() {
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args = array(
			'page'     => $page,
			'per_page' => 20,
		);

		if ( ! current_user_can( 'manage_options' ) ) {
			$args['user_id'] = get_current_user_id();
		}

		$jobs  = Import_Job_Repository::list_jobs( $args );
		$total = Import_Job_Repository::count_jobs( $args );

		if ( empty( $jobs ) ) {
			echo '<div class="afsr-data-card"><p class="afsr-empty">' . esc_html__( 'No import jobs yet.', 'add-from-server-reloaded' ) . '</p></div>';
			return;
		}

		echo '<div class="afsr-data-card">';
		echo '<table class="afsr-data-table afsrreloaded-history-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Job', 'add-from-server-reloaded' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'add-from-server-reloaded' ) . '</th>';
		echo '<th>' . esc_html__( 'Progress', 'add-from-server-reloaded' ) . '</th>';
		echo '<th>' . esc_html__( 'Imported', 'add-from-server-reloaded' ) . '</th>';
		echo '<th>' . esc_html__( 'Errors', 'add-from-server-reloaded' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'add-from-server-reloaded' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'add-from-server-reloaded' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $jobs as $job ) {
			$payload = $this->processor->status_payload( $job );
			$view    = admin_url( 'admin.php?page=add-from-server-reloaded-history&job_id=' . (int) $job->id );
			$delete  = wp_nonce_url(
				admin_url( 'admin-post.php?action=afsrreloaded_delete_job&job_id=' . (int) $job->id ),
				'afsrreloaded_delete_job'
			);
			$status  = (string) $job->status;
			$badge   = 'afsr-badge--neutral';
			if ( in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
				$badge = 'afsr-badge--success';
			} elseif ( in_array( $status, array( 'failed', 'error' ), true ) ) {
				$badge = 'afsr-badge--danger';
			} elseif ( in_array( $status, array( 'running', 'pending', 'scanning' ), true ) ) {
				$badge = 'afsr-badge--warn';
			}

			echo '<tr>';
			echo '<td><a class="afsr-link" href="' . esc_url( $view ) . '">#' . absint( $job->id ) . '</a></td>';
			echo '<td><span class="afsr-badge ' . esc_attr( $badge ) . ' afsrreloaded-status afsrreloaded-status--' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span></td>';
			echo '<td>' . esc_html( sprintf( '%1$d / %2$d (%3$s%%)', $payload['processed'], $payload['total'], $payload['percent'] ) ) . '</td>';
			echo '<td>' . absint( $job->imported ) . '</td>';
			echo '<td>' . absint( $job->errors ) . '</td>';
			echo '<td class="afsr-muted">' . esc_html( get_date_from_gmt( $job->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</td>';
			echo '<td><span class="afsr-actions">';
			echo '<a class="afsr-link" href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'add-from-server-reloaded' ) . '</a>';
			echo '<span class="afsr-sep">|</span>';
			echo '<a class="afsr-link-danger" href="' . esc_url( $delete ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this job record?', 'add-from-server-reloaded' ) ) . '\');">' . esc_html__( 'Delete', 'add-from-server-reloaded' ) . '</a>';
			echo '</span></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';

		$total_pages = (int) ceil( $total / 20 );
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $total_pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Render single job detail.
	 *
	 * @since 5.3.0
	 *
	 * @param int $job_id Job ID.
	 */
	protected function render_job_detail( $job_id ) {
		$job = Import_Job_Repository::get_job( $job_id );
		if ( ! $job ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Import job not found.', 'add-from-server-reloaded' ) . '</p></div>';
			return;
		}

		if ( get_current_user_id() !== (int) $job->user_id && ! current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'You cannot view this import job.', 'add-from-server-reloaded' ) . '</p></div>';
			return;
		}

		$payload = $this->processor->status_payload( $job );
		$back    = admin_url( 'admin.php?page=add-from-server-reloaded-history' );

		echo '<a class="afsr-link afsr-back-link" href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to history', 'add-from-server-reloaded' ) . '</a>';
		echo '<h3 class="afsr-section-title">' . esc_html( sprintf( /* translators: %d: job id */ __( 'Import Job #%d', 'add-from-server-reloaded' ), $job->id ) ) . '</h3>';

		if ( ! empty( $_GET['retried'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Failed files were queued for retry.', 'add-from-server-reloaded' ) . '</p></div>';
		}
		if ( ! empty( $_GET['continued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Background processing was nudged for this job.', 'add-from-server-reloaded' ) . '</p></div>';
		}

		echo '<div class="afsr-data-card afsr-job-summary">';
		echo '<p><strong>' . esc_html__( 'Status:', 'add-from-server-reloaded' ) . '</strong> ' . esc_html( ucfirst( $job->status ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Mode:', 'add-from-server-reloaded' ) . '</strong> ' . esc_html( $job->mode ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Progress:', 'add-from-server-reloaded' ) . '</strong> ' . esc_html( sprintf( '%1$d / %2$d (%3$s%%)', $payload['processed'], $payload['total'], $payload['percent'] ) ) . '</p>';
		echo '<ul>';
		echo '<li>' . esc_html__( 'Imported:', 'add-from-server-reloaded' ) . ' ' . absint( $job->imported ) . '</li>';
		echo '<li>' . esc_html__( 'Duplicates:', 'add-from-server-reloaded' ) . ' ' . absint( $job->duplicates ) . '</li>';
		echo '<li>' . esc_html__( 'Errors:', 'add-from-server-reloaded' ) . ' ' . absint( $job->errors ) . '</li>';
		echo '<li>' . esc_html__( 'Skipped:', 'add-from-server-reloaded' ) . ' ' . absint( $job->skipped ) . '</li>';
		echo '</ul>';

		$actions = array();
		if ( Features::enabled( 'queue_controls' ) && (int) $job->errors > 0 ) {
			$actions[] = '<a class="afsr-btn afsr-btn-outline" href="' . esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=afsrreloaded_retry_failed_job&job_id=' . absint( $job->id ) ),
					'afsrreloaded_retry_failed_job_' . absint( $job->id )
				)
			) . '">' . esc_html__( 'Retry failed', 'add-from-server-reloaded' ) . '</a>';
		}
		if ( Features::enabled( 'background' ) && in_array( $job->status, array( 'pending', 'scanning', 'running' ), true ) ) {
			$actions[] = '<a class="afsr-btn afsr-btn-primary" href="' . esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=afsrreloaded_continue_job&job_id=' . absint( $job->id ) ),
					'afsrreloaded_continue_job_' . absint( $job->id )
				)
			) . '">' . esc_html__( 'Continue processing', 'add-from-server-reloaded' ) . '</a>';
		}
		if ( ! empty( $actions ) ) {
			echo '<p class="afsrreloaded-job-actions afsr-form-actions">' . implode( ' ', $actions ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped URLs/labels above.
		}
		echo '</div>';

		$failed = Import_Job_Repository::get_items_by_status( $job_id, 'error', 100 );
		if ( ! empty( $failed ) ) {
			echo '<section class="afsr-section"><h3 class="afsr-section-title">' . esc_html__( 'Failed files', 'add-from-server-reloaded' ) . '</h3>';
			echo '<div class="afsr-data-card"><table class="afsr-data-table"><thead><tr>';
			echo '<th>' . esc_html__( 'File', 'add-from-server-reloaded' ) . '</th>';
			echo '<th>' . esc_html__( 'Message', 'add-from-server-reloaded' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $failed as $item ) {
				echo '<tr>';
				echo '<td>' . esc_html( $item->file_path ) . '</td>';
				echo '<td>' . esc_html( (string) $item->message ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div></section>';
		}

		$items = Import_Job_Repository::get_recent_items( $job_id, 100 );
		if ( empty( $items ) ) {
			echo '<p class="afsr-muted">' . esc_html__( 'No processed files yet.', 'add-from-server-reloaded' ) . '</p>';
			return;
		}

		echo '<section class="afsr-section"><h3 class="afsr-section-title">' . esc_html__( 'Recent file results', 'add-from-server-reloaded' ) . '</h3>';
		echo '<div class="afsr-data-card"><table class="afsr-data-table"><thead><tr>';
		echo '<th>' . esc_html__( 'File', 'add-from-server-reloaded' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'add-from-server-reloaded' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'add-from-server-reloaded' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $items as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( basename( $item->file_path ) ) . '</td>';
			echo '<td>' . esc_html( $item->status ) . '</td>';
			echo '<td>';
			if ( ! empty( $item->attachment_id ) ) {
				$edit = admin_url( 'post.php?post=' . absint( $item->attachment_id ) . '&action=edit' );
				echo '<a class="afsr-link" href="' . esc_url( $edit ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View in Media Library', 'add-from-server-reloaded' ) . '</a>';
				if ( ! empty( $item->message ) ) {
					echo ' — ';
				}
			}
			echo esc_html( (string) $item->message );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div></section>';
	}
}
