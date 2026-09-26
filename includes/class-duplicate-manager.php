<?php
/**
 * Advanced duplicate detection management UI.
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
 * Pro duplicate manager: find hash matches and bulk cleanup.
 *
 * Import-time duplicate behaviour lives on the main import page and on each
 * schedule. This page is only for cleaning up existing Media Library copies
 * that share the same file hash.
 *
 * @since 5.4.0
 */
class Duplicate_Manager {

	const PAGE = 'add-from-server-reloaded-duplicates';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		add_action( 'admin_menu', array( $this, 'register_menu' ), 38 );
	}

	/**
	 * Register menu when Pro advanced_duplicates is on.
	 */
	public function register_menu() {
		if ( ! Capabilities::can_manage_history() && ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$hook = add_submenu_page(
			'add-from-server-reloaded',
			__( 'Duplicates', 'add-from-server-reloaded' ),
			__( 'Duplicates', 'add-from-server-reloaded' ),
			Capabilities::rbac_enabled() ? Capabilities::CAP_HISTORY : 'upload_files',
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
	 * Render page + actions.
	 */
	public function render_page() {
		if ( ! Capabilities::can_manage_history() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'add-from-server-reloaded' ) );
		}

		if ( ! Features::enabled( 'advanced_duplicates' ) ) {
			Pro_Locked_Screens::duplicates();
			return;
		}

		$this->handle_actions();
		$groups = $this->find_duplicate_groups( 50 );
		?>
		<div class="wrap afsr-admin-wrap afsr-dup-wrap">
			<h1><?php esc_html_e( 'Duplicates', 'add-from-server-reloaded' ); ?></h1>
			<div id="afsr-admin-app" class="afsr-wrap afsr-pro-page">
				<header class="afsr-page-header">
					<h2 class="afsr-page-title"><?php esc_html_e( 'Duplicates', 'add-from-server-reloaded' ); ?></h2>
					<p class="afsr-page-subtitle"><?php esc_html_e( 'Find files with identical content and keep one copy.', 'add-from-server-reloaded' ); ?></p>
				</header>

				<?php if ( empty( $groups ) ) : ?>
					<div class="afsr-data-card"><p class="afsr-empty"><?php esc_html_e( 'No duplicate groups found.', 'add-from-server-reloaded' ); ?></p></div>
				<?php else : ?>
					<div class="afsr-dup-grid">
						<?php foreach ( $groups as $hash => $ids ) : ?>
							<?php
							$titles = array();
							foreach ( $ids as $attachment_id ) {
								$titles[] = get_the_title( $attachment_id );
							}
							$titles     = array_filter( array_map( 'strval', $titles ) );
							$group_name = $titles ? $titles[0] : sprintf( /* translators: %s: short hash */ __( 'Group %s', 'add-from-server-reloaded' ), substr( $hash, 0, 8 ) );
							?>
							<div class="afsr-dup-card">
								<div class="afsr-dup-card__head">
									<h3 class="afsr-dup-card__title"><?php echo esc_html( $group_name ); ?></h3>
									<span class="afsr-dup-card__count">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: count */
												_n( '%d copy', '%d copies', count( $ids ), 'add-from-server-reloaded' ),
												count( $ids )
											)
										);
										?>
									</span>
								</div>

								<form method="post">
									<?php wp_nonce_field( 'afsrreloaded_dup_keep_' . $hash ); ?>
									<input type="hidden" name="hash" value="<?php echo esc_attr( $hash ); ?>" />

									<ul class="afsr-dup-items">
										<?php foreach ( $ids as $index => $attachment_id ) : ?>
											<?php
											$thumb = wp_get_attachment_image(
												$attachment_id,
												array( 320, 180 ),
												true,
												array( 'class' => 'afsr-dup-item__thumb' )
											);
											?>
											<li>
												<label class="afsr-dup-item<?php echo 0 === (int) $index ? ' is-selected' : ''; ?>">
													<input
														type="radio"
														name="keep_id"
														value="<?php echo absint( $attachment_id ); ?>"
														<?php checked( 0 === (int) $index ); ?>
														required
													/>
													<?php if ( $thumb ) : ?>
														<?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
													<?php else : ?>
														<span class="afsr-dup-item__thumb-placeholder" aria-hidden="true"></span>
													<?php endif; ?>
													<span class="afsr-dup-item__meta">
														<span class="afsr-dup-item__id">#<?php echo absint( $attachment_id ); ?></span>
														<span class="afsr-dup-item__name"><?php echo esc_html( get_the_title( $attachment_id ) ); ?></span>
														<span class="afsr-dup-item__links">
															<a class="afsr-link" href="<?php echo esc_url( get_edit_post_link( $attachment_id ) ); ?>"><?php esc_html_e( 'Edit', 'add-from-server-reloaded' ); ?></a>
															<span class="afsr-sep">|</span>
															<a class="afsr-link" href="<?php echo esc_url( wp_get_attachment_url( $attachment_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'add-from-server-reloaded' ); ?></a>
														</span>
													</span>
												</label>
											</li>
										<?php endforeach; ?>
									</ul>

									<button type="submit" class="afsr-btn afsr-btn-outline" name="afsrreloaded_dup_cleanup" value="1">
										<?php esc_html_e( 'Keep selected, delete rest', 'add-from-server-reloaded' ); ?>
									</button>
								</form>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle cleanup actions.
	 */
	protected function handle_actions() {
		if ( isset( $_POST['afsrreloaded_dup_cleanup'] ) ) {
			$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
			check_admin_referer( 'afsrreloaded_dup_keep_' . $hash );
			$keep    = isset( $_POST['keep_id'] ) ? absint( $_POST['keep_id'] ) : 0;
			$ids     = $this->ids_for_hash( $hash );
			$deleted = 0;
			foreach ( $ids as $id ) {
				if ( (int) $id === $keep ) {
					continue;
				}
				if ( wp_delete_attachment( $id, true ) ) {
					++$deleted;
				}
			}
			add_settings_error(
				'afsrreloaded_dup',
				'cleaned',
				sprintf(
					/* translators: %d: deleted count */
					__( 'Deleted %d duplicate attachment(s).', 'add-from-server-reloaded' ),
					$deleted
				),
				'updated'
			);
		}

		settings_errors( 'afsrreloaded_dup' );
	}

	/**
	 * Find hash groups with more than one attachment.
	 *
	 * @param int $limit Max groups.
	 * @return array<string,int[]>
	 */
	protected function find_duplicate_groups( $limit = 50 ) {
		global $wpdb;

		$limit = max( 1, min( 100, absint( $limit ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS hash, COUNT(post_id) AS cnt
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s AND meta_value <> ''
				GROUP BY meta_value
				HAVING cnt > 1
				ORDER BY cnt DESC
				LIMIT %d",
				'_afsrreloaded_file_hash',
				$limit
			)
		);

		$groups = array();
		if ( empty( $rows ) ) {
			return $groups;
		}

		foreach ( $rows as $row ) {
			$groups[ (string) $row->hash ] = $this->ids_for_hash( (string) $row->hash );
		}

		return $groups;
	}

	/**
	 * Attachment IDs for a hash.
	 *
	 * @param string $hash Hash.
	 * @return int[]
	 */
	protected function ids_for_hash( $hash ) {
		$hash = sanitize_text_field( $hash );
		if ( '' === $hash ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_afsrreloaded_file_hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Fallback duplicate action when a job/import does not specify one.
	 *
	 * Per-import and per-schedule settings own the real choice; this is Skip.
	 *
	 * @return string
	 */
	public static function default_action() {
		return 'skip';
	}
}
