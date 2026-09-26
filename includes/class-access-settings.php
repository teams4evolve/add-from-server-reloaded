<?php
/**
 * Role-based access control settings.
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
 * RBAC role map UI.
 *
 * @since 5.4.0
 */
class Access_Settings {

	const PAGE = 'add-from-server-reloaded-access';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 50 );
		add_action( 'admin_init', array( $this, 'maybe_sync_caps' ), 5 );
	}

	/**
	 * Sync caps when RBAC is enabled.
	 */
	public function maybe_sync_caps() {
		if ( Features::enabled( 'rbac' ) ) {
			Capabilities::sync_role_caps();
		}
	}

	/**
	 * Site-level RBAC menu.
	 */
	public function register_menu() {
		if ( ! Capabilities::can_manage_settings() && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$hook = add_submenu_page(
			'add-from-server-reloaded',
			__( 'Access Control', 'add-from-server-reloaded' ),
			__( 'Access Control', 'add-from-server-reloaded' ),
			Capabilities::rbac_enabled() ? Capabilities::CAP_SETTINGS : 'manage_options',
			self::PAGE,
			array( $this, 'render_rbac_page' )
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
	 * RBAC settings page.
	 */
	public function render_rbac_page() {
		if ( ! Capabilities::can_manage_settings() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'add-from-server-reloaded' ) );
		}

		if ( ! Features::enabled( 'rbac' ) ) {
			Pro_Locked_Screens::access();
			return;
		}

		if ( isset( $_POST['afsrreloaded_save_role_caps'] ) ) {
			check_admin_referer( 'afsrreloaded_role_caps' );
			$map  = array();
			$raw  = isset( $_POST['role_caps'] ) ? wp_unslash( $_POST['role_caps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw  = is_array( $raw ) ? $raw : array();
			$caps = Capabilities::all_caps();
			foreach ( $raw as $role => $granted ) {
				$role = sanitize_key( $role );
				if ( 'administrator' === $role ) {
					continue;
				}
				$granted      = is_array( $granted ) ? array_map( 'sanitize_key', $granted ) : array();
				$map[ $role ] = array_values( array_intersect( $caps, $granted ) );
			}
			update_option( 'afsrreloaded_role_caps', $map, false );
			Capabilities::sync_role_caps();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Access rules saved.', 'add-from-server-reloaded' ) . '</p></div>';
		}

		$stored = get_option( 'afsrreloaded_role_caps', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$roles  = function_exists( 'get_editable_roles' ) ? get_editable_roles() : wp_roles()->roles;
		$labels = array(
			Capabilities::CAP_BROWSE    => __( 'Browse', 'add-from-server-reloaded' ),
			Capabilities::CAP_IMPORT    => __( 'Import', 'add-from-server-reloaded' ),
			Capabilities::CAP_HISTORY   => __( 'History / duplicates', 'add-from-server-reloaded' ),
			Capabilities::CAP_SCHEDULES => __( 'Schedules', 'add-from-server-reloaded' ),
			Capabilities::CAP_REMOTE    => __( 'Remote sources', 'add-from-server-reloaded' ),
			Capabilities::CAP_SETTINGS  => __( 'Settings', 'add-from-server-reloaded' ),
		);
		?>
		<div class="wrap afsr-admin-wrap">
			<h1><?php esc_html_e( 'Access Control', 'add-from-server-reloaded' ); ?></h1>
			<div id="afsr-admin-app" class="afsr-wrap afsr-pro-page">
				<header class="afsr-page-header">
					<h2 class="afsr-page-title"><?php esc_html_e( 'Access Control', 'add-from-server-reloaded' ); ?></h2>
					<p class="afsr-page-subtitle"><?php esc_html_e( 'Grant custom capabilities to roles.', 'add-from-server-reloaded' ); ?></p>
				</header>

				<form method="post">
					<?php wp_nonce_field( 'afsrreloaded_role_caps' ); ?>
					<div class="afsr-data-card">
						<table class="afsr-data-table afsr-access-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Role', 'add-from-server-reloaded' ); ?></th>
									<?php foreach ( $labels as $cap => $label ) : ?>
										<th><?php echo esc_html( $label ); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $roles as $role_key => $role_data ) : ?>
								<tr>
									<td class="afsr-row-name"><?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?></td>
									<?php foreach ( array_keys( $labels ) as $cap ) : ?>
										<td>
											<?php if ( 'administrator' === $role_key ) : ?>
												<input type="checkbox" checked disabled />
											<?php else : ?>
												<?php
												$checked = isset( $stored[ $role_key ] ) && in_array( $cap, (array) $stored[ $role_key ], true );
												?>
												<input type="checkbox" name="role_caps[<?php echo esc_attr( $role_key ); ?>][]" value="<?php echo esc_attr( $cap ); ?>" <?php checked( $checked ); ?> />
											<?php endif; ?>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p style="margin-top:16px;">
						<button type="submit" class="afsr-btn afsr-btn-primary" name="afsrreloaded_save_role_caps" value="1"><?php esc_html_e( 'Save access rules', 'add-from-server-reloaded' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
}
