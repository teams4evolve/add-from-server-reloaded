<?php
/**
 * Email notifications for completed / failed import jobs.
 *
 * @package AFSRReloaded
 * @since   5.4.0
 */

namespace AFSRReloaded;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends wp_mail summaries when Pro email_notifications is enabled.
 *
 * @since 5.4.0
 */
class Email_Notifications {

	/**
	 * Option key for notification settings.
	 *
	 * @since 5.4.0
	 * @var string
	 */
	const OPTION = 'afsrreloaded_email_notifications';

	/**
	 * Constructor.
	 *
	 * @since 5.4.0
	 */
	public function __construct() {
		add_action( 'afsrreloaded_import_job_completed', array( $this, 'on_job_completed' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 45 );
	}

	/**
	 * Default settings.
	 *
	 * @since 5.4.0
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'         => true,
			'on_completed'    => true,
			'on_failed'       => true,
			'on_cancelled'    => false,
			'recipients'      => '',
			'include_summary' => true,
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @since 5.4.0
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Register settings when Pro feature is on.
	 *
	 * @since 5.4.0
	 */
	public function register_settings() {
		if ( ! Features::enabled( 'email_notifications' ) ) {
			return;
		}

		register_setting(
			'afsrreloaded_email_notifications_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize settings array.
	 *
	 * @since 5.4.0
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$out['enabled']         = ! empty( $input['enabled'] );
		$out['on_completed']    = ! empty( $input['on_completed'] );
		$out['on_failed']       = ! empty( $input['on_failed'] );
		$out['on_cancelled']    = ! empty( $input['on_cancelled'] );
		$out['include_summary'] = ! empty( $input['include_summary'] );
		$out['recipients']      = isset( $input['recipients'] ) ? sanitize_textarea_field( $input['recipients'] ) : '';

		return $out;
	}

	/**
	 * Admin submenu for email settings.
	 *
	 * @since 5.4.0
	 */
	public function register_menu() {
		if ( ! Capabilities::can_manage_settings() && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$hook = add_submenu_page(
			'add-from-server-reloaded',
			__( 'Import Email Notifications', 'add-from-server-reloaded' ),
			__( 'Email Alerts', 'add-from-server-reloaded' ),
			Capabilities::rbac_enabled() ? Capabilities::CAP_SETTINGS : 'manage_options',
			'add-from-server-reloaded-email',
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
	 * Settings page.
	 *
	 * @since 5.4.0
	 */
	public function render_page() {
		if ( ! Capabilities::can_manage_settings() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'add-from-server-reloaded' ) );
		}

		if ( ! Features::enabled( 'email_notifications' ) ) {
			Pro_Locked_Screens::email();
			return;
		}

		$settings = self::get_settings();
		?>
		<div class="wrap afsr-admin-wrap">
			<h1><?php esc_html_e( 'Email Alerts', 'add-from-server-reloaded' ); ?></h1>
			<div id="afsr-admin-app" class="afsr-wrap afsr-pro-page">
				<header class="afsr-page-header">
					<h2 class="afsr-page-title"><?php esc_html_e( 'Email Alerts', 'add-from-server-reloaded' ); ?></h2>
					<p class="afsr-page-subtitle"><?php esc_html_e( 'Get notified when an import finishes or fails.', 'add-from-server-reloaded' ); ?></p>
				</header>

				<div class="afsr-form-card">
					<form method="post" action="options.php">
						<?php settings_fields( 'afsrreloaded_email_notifications_group' ); ?>
						<div class="afsr-form-stack afsr-email-alerts-form">
							<div class="afsr-check-list">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
									<?php esc_html_e( 'Send email when import jobs finish', 'add-from-server-reloaded' ); ?>
								</label>
							</div>

							<div class="afsr-field afsr-field--checks">
								<span class="afsr-field-label"><?php esc_html_e( 'Events', 'add-from-server-reloaded' ); ?></span>
								<div class="afsr-check-list">
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[on_completed]" value="1" <?php checked( ! empty( $settings['on_completed'] ) ); ?> />
										<?php esc_html_e( 'Completed jobs', 'add-from-server-reloaded' ); ?>
									</label>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[on_failed]" value="1" <?php checked( ! empty( $settings['on_failed'] ) ); ?> />
										<?php esc_html_e( 'Failed jobs', 'add-from-server-reloaded' ); ?>
									</label>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[on_cancelled]" value="1" <?php checked( ! empty( $settings['on_cancelled'] ) ); ?> />
										<?php esc_html_e( 'Cancelled jobs', 'add-from-server-reloaded' ); ?>
									</label>
								</div>
							</div>

							<div class="afsr-field">
								<label for="afsrreloaded-email-recipients"><?php esc_html_e( 'Recipients', 'add-from-server-reloaded' ); ?></label>
								<textarea id="afsrreloaded-email-recipients" rows="3" name="<?php echo esc_attr( self::OPTION ); ?>[recipients]" placeholder="<?php esc_attr_e( 'Optional. Comma-separated addresses.', 'add-from-server-reloaded' ); ?>"><?php echo esc_textarea( $settings['recipients'] ); ?></textarea>
								<p class="afsr-field-help"><?php esc_html_e( 'If empty, notifications go to the site’s Administration Email Address (Settings → General).', 'add-from-server-reloaded' ); ?></p>
							</div>

							<div class="afsr-field afsr-field--checks">
								<span class="afsr-field-label"><?php esc_html_e( 'Summary', 'add-from-server-reloaded' ); ?></span>
								<div class="afsr-check-list">
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[include_summary]" value="1" <?php checked( ! empty( $settings['include_summary'] ) ); ?> />
										<?php esc_html_e( 'Include imported / duplicate / error counts', 'add-from-server-reloaded' ); ?>
									</label>
								</div>
							</div>

							<div class="afsr-form-actions">
								<button type="submit" class="afsr-btn afsr-btn-primary"><?php esc_html_e( 'Save changes', 'add-from-server-reloaded' ); ?></button>
							</div>
						</div>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle completed job action (also fires for cancelled/failed terminal states).
	 *
	 * @since 5.4.0
	 *
	 * @param object|null $job Job row.
	 */
	public function on_job_completed( $job ) {
		if ( ! Features::enabled( 'email_notifications' ) || ! $job || empty( $job->status ) ) {
			return;
		}

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$status = (string) $job->status;
		if ( 'completed' === $status && empty( $settings['on_completed'] ) ) {
			return;
		}
		if ( 'failed' === $status && empty( $settings['on_failed'] ) ) {
			return;
		}
		if ( 'cancelled' === $status && empty( $settings['on_cancelled'] ) ) {
			return;
		}
		if ( ! in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return;
		}

		$recipients = $this->parse_recipients( $settings['recipients'] );
		if ( empty( $recipients ) ) {
			return;
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: site name, 2: job id, 3: status */
		$subject = sprintf( __( '[%1$s] Import job #%2$d %3$s', 'add-from-server-reloaded' ), $site, (int) $job->id, $status );

		$lines   = array();
		$lines[] = sprintf(
			/* translators: 1: job id, 2: status */
			__( 'Import job #%1$d finished with status: %2$s', 'add-from-server-reloaded' ),
			(int) $job->id,
			$status
		);

		if ( ! empty( $settings['include_summary'] ) ) {
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %d: number of imported files */
				__( 'Imported: %d', 'add-from-server-reloaded' ),
				(int) $job->imported
			);
			$lines[] = sprintf(
				/* translators: %d: number of duplicate files */
				__( 'Duplicates: %d', 'add-from-server-reloaded' ),
				(int) $job->duplicates
			);
			$lines[] = sprintf(
				/* translators: %d: number of errors */
				__( 'Errors: %d', 'add-from-server-reloaded' ),
				(int) $job->errors
			);
			$lines[] = sprintf(
				/* translators: %d: number of skipped files */
				__( 'Skipped: %d', 'add-from-server-reloaded' ),
				(int) $job->skipped
			);
			$lines[] = sprintf(
				/* translators: 1: processed file count, 2: total file count */
				__( 'Processed: %1$d / %2$d', 'add-from-server-reloaded' ),
				(int) $job->processed_files,
				(int) $job->total_files
			);
		}

		if ( Features::enabled( 'history' ) ) {
			$lines[] = '';
			$lines[] = __( 'History:', 'add-from-server-reloaded' ) . ' ' . admin_url( 'admin.php?page=add-from-server-reloaded-history&job_id=' . absint( $job->id ) );
		}

		$body = implode( "\n", $lines );

		/**
		 * Filters notification recipients before send.
		 *
		 * @since 5.4.0
		 *
		 * @param string[] $recipients Emails.
		 * @param object   $job        Job.
		 */
		$recipients = apply_filters( 'afsrreloaded_email_recipients', $recipients, $job );

		wp_mail( $recipients, $subject, $body );
	}

	/**
	 * Parse recipient list.
	 *
	 * @since 5.4.0
	 *
	 * @param string $raw Raw textarea.
	 * @return string[]
	 */
	protected function parse_recipients( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			$admin = get_option( 'admin_email' );
			return is_email( $admin ) ? array( $admin ) : array();
		}

		$parts = preg_split( '/[\s,;]+/', $raw );
		$out   = array();
		foreach ( (array) $parts as $email ) {
			$email = sanitize_email( $email );
			if ( is_email( $email ) ) {
				$out[] = $email;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
