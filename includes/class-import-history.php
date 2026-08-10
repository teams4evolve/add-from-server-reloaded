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
	}

	/**
	 * Register submenu.
	 *
	 * @since 5.3.0
	 */
	public function register_menu() {
		$hook = add_submenu_page(
			'add-from-server-reloaded',
			__( 'Import History', 'add-from-server-reloaded' ),
			__( 'Import History', 'add-from-server-reloaded' ),
			'upload_files',
			'add-from-server-reloaded-history',
			array( $this, 'render_page' )
		);

		add_action(
			'load-' . $hook,
			static function () {
				wp_enqueue_style( 'add-from-server-reloaded' );
			}
		);
	}

	/**
	 * Delete a job (admin-post).
	 *
	 * @since 5.3.0
	 */
	public function handle_delete_job() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'add-from-server-reloaded' ) );
		}

		check_admin_referer( 'afsrreloaded_delete_job' );

		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;
		$job    = Import_Job_Repository::get_job( $job_id );

		if ( ! $job ) {
			wp_safe_redirect( admin_url( 'admin.php?page=add-from-server-reloaded-history' ) );
			exit;
		}

		if ( (int) $job->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
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
	 * Render history page.
	 *
	 * @since 5.3.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Import History', 'add-from-server-reloaded' ) . '</h1>';

		if ( ! empty( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Import job deleted.', 'add-from-server-reloaded' ) . '</p></div>';
		}

		if ( $job_id ) {
			$this->render_job_detail( $job_id );
		} else {
			$this->render_job_list();
		}

		echo '</div>';
	}

	/**
	 * Render job list table.
	 *
	 * @since 5.3.0
	 */
	protected function render_job_list() {
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args    = array(
			'page'     => $page,
			'per_page' => 20,
		);

		if ( ! current_user_can( 'manage_options' ) ) {
			$args['user_id'] = get_current_user_id();
		}

		$jobs  = Import_Job_Repository::list_jobs( $args );
		$total = Import_Job_Repository::count_jobs( $args );

		echo '<p>' . esc_html__( 'Track bulk imports, resume paused jobs, and review errors.', 'add-from-server-reloaded' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=add-from-server-reloaded' ) ) . '">' . esc_html__( 'Start New Import', 'add-from-server-reloaded' ) . '</a></p>';

		if ( empty( $jobs ) ) {
			echo '<p>' . esc_html__( 'No import jobs yet.', 'add-from-server-reloaded' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped afsrreloaded-history-table">';
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

			echo '<tr>';
			echo '<td><a href="' . esc_url( $view ) . '">#' . absint( $job->id ) . '</a></td>';
			echo '<td><span class="afsrreloaded-status afsrreloaded-status--' . esc_attr( $job->status ) . '">' . esc_html( ucfirst( $job->status ) ) . '</span></td>';
			echo '<td>' . esc_html( sprintf( '%1$d / %2$d (%3$s%%)', $payload['processed'], $payload['total'], $payload['percent'] ) ) . '</td>';
			echo '<td>' . absint( $job->imported ) . '</td>';
			echo '<td>' . absint( $job->errors ) . '</td>';
			echo '<td>' . esc_html( get_date_from_gmt( $job->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</td>';
			echo '<td>';
			echo '<a href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'add-from-server-reloaded' ) . '</a> | ';
			echo '<a href="' . esc_url( $delete ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this job record?', 'add-from-server-reloaded' ) ) . '\');">' . esc_html__( 'Delete', 'add-from-server-reloaded' ) . '</a>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

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

		if ( (int) $job->user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'You cannot view this import job.', 'add-from-server-reloaded' ) . '</p></div>';
			return;
		}

		$payload = $this->processor->status_payload( $job );
		$back    = admin_url( 'admin.php?page=add-from-server-reloaded-history' );

		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to history', 'add-from-server-reloaded' ) . '</a></p>';
		echo '<h2>' . esc_html( sprintf( /* translators: %d: job id */ __( 'Import Job #%d', 'add-from-server-reloaded' ), $job->id ) ) . '</h2>';

		echo '<div class="afsrreloaded-job-summary">';
		echo '<p><strong>' . esc_html__( 'Status:', 'add-from-server-reloaded' ) . '</strong> ' . esc_html( ucfirst( $job->status ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Mode:', 'add-from-server-reloaded' ) . '</strong> ' . esc_html( $job->mode ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Progress:', 'add-from-server-reloaded' ) . '</strong> ' . esc_html( sprintf( '%1$d / %2$d (%3$s%%)', $payload['processed'], $payload['total'], $payload['percent'] ) ) . '</p>';
		echo '<ul>';
		echo '<li>' . esc_html__( 'Imported:', 'add-from-server-reloaded' ) . ' ' . absint( $job->imported ) . '</li>';
		echo '<li>' . esc_html__( 'Duplicates:', 'add-from-server-reloaded' ) . ' ' . absint( $job->duplicates ) . '</li>';
		echo '<li>' . esc_html__( 'Errors:', 'add-from-server-reloaded' ) . ' ' . absint( $job->errors ) . '</li>';
		echo '<li>' . esc_html__( 'Skipped:', 'add-from-server-reloaded' ) . ' ' . absint( $job->skipped ) . '</li>';
		echo '</ul>';
		echo '</div>';

		$items = Import_Job_Repository::get_recent_items( $job_id, 100 );
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No processed files yet.', 'add-from-server-reloaded' ) . '</p>';
			return;
		}

		echo '<h3>' . esc_html__( 'Recent file results', 'add-from-server-reloaded' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
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
				echo '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View in Media Library', 'add-from-server-reloaded' ) . '</a>';
				if ( ! empty( $item->message ) ) {
					echo ' — ';
				}
			}
			echo esc_html( (string) $item->message );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
