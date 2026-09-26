<?php
/**
 * Pro upgrade teasers: locked UI, badges, modal, and Free sidebar links.
 *
 * @package AFSRReloaded
 * @since   5.4.2
 */

namespace AFSRReloaded;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Freemium upgrade prompts for Pro-only features.
 *
 * Detection uses Features::is_pro() (license filters), not merely whether
 * the Pro plugin file is installed.
 *
 * @since 5.4.2
 */
class Pro_Teaser {

	/**
	 * Upgrade / pricing URL.
	 *
	 * @since 5.4.2
	 * @var string
	 */
	const UPGRADE_URL = 'https://elearningevolve.com/products/add-from-server-reloaded-pro/';

	/**
	 * Pro Features admin page slug.
	 *
	 * @since 5.4.2
	 * @var string
	 */
	const PAGE_FEATURES = 'add-from-server-reloaded-pro-features';

	/**
	 * Brand primary button color (forced on plugin screens).
	 *
	 * @since 5.4.2
	 * @var string
	 */
	const BRAND_PRIMARY = '#183ad6';

	/**
	 * Whether hooks are already registered.
	 *
	 * @since 5.4.2
	 * @var bool
	 */
	protected static $booted = false;

	/**
	 * Register admin hooks once.
	 *
	 * @since 5.4.2
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'admin_menu', array( __CLASS__, 'register_teaser_menus' ), 60 );
		add_action( 'admin_menu', array( __CLASS__, 'decorate_submenu_labels' ), 1000 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_menu_badge_assets' ) );
		// Pro upgrade modal removed from Free UX (tooltip / locked screens instead).
	}

	/**
	 * Whether Pro is active and licensed (filter-based).
	 *
	 * @since 5.4.2
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		return Features::is_pro();
	}

	/**
	 * Whether the Pro plugin is loaded (may still be unlicensed).
	 *
	 * @since 5.3.0
	 *
	 * @return bool
	 */
	public static function is_pro_plugin_present() {
		return defined( 'AFSR_PRO_VERSION' ) || class_exists( '\Afsrreloadedpro', false );
	}

	/**
	 * Admin URL for the Pro License settings page.
	 *
	 * @since 5.3.0
	 *
	 * @return string
	 */
	public static function license_page_url() {
		return admin_url( 'admin.php?page=add-from-server-reloaded-settings#afsr-pro-license' );
	}

	/**
	 * Upgrade URL (filterable).
	 *
	 * @since 5.4.2
	 *
	 * @return string
	 */
	public static function upgrade_url() {
		/**
		 * Filters the Pro upgrade URL.
		 *
		 * @since 5.4.2
		 *
		 * @param string $url Upgrade URL.
		 */
		return (string) apply_filters( 'afsrreloaded_pro_upgrade_url', self::UPGRADE_URL );
	}

	/**
	 * Admin URL for the Pro Features list page.
	 *
	 * @since 5.4.2
	 *
	 * @return string
	 */
	public static function features_page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_FEATURES );
	}

	/**
	 * Shared Upgrade banner used on Import + locked Pro screens.
	 *
	 * When Pro is installed but the license is inactive, the same banner UI
	 * prompts the user to activate their license instead of upgrading.
	 *
	 * @since 5.4.3
	 *
	 * @param string $title Bold banner title.
	 * @param string $description Supporting copy.
	 */
	public static function render_upgrade_banner( $title, $description ) {
		if ( self::is_pro_active() ) {
			return;
		}

		$needs_license = self::is_pro_plugin_present();
		if ( $needs_license ) {
			$title         = __( 'Activate your Pro license', 'add-from-server-reloaded' );
			$description   = __( 'Enter a valid license key to unlock Pro features on this site.', 'add-from-server-reloaded' );
			$primary_url   = self::license_page_url();
			$primary_lbl   = __( 'Activate License', 'add-from-server-reloaded' );
			$primary_blank = false;
		} else {
			$primary_url   = self::upgrade_url();
			$primary_lbl   = __( 'Upgrade to Pro', 'add-from-server-reloaded' );
			$primary_blank = true;
		}
		?>
		<div class="afsr-pro-banner">
			<div class="afsr-pro-banner__left">
				<span class="afsr-pro-pill"><?php esc_html_e( 'PRO', 'add-from-server-reloaded' ); ?></span>
				<p class="afsr-pro-banner__text">
					<strong><?php echo esc_html( $title ); ?></strong>
					<?php echo esc_html( $description ); ?>
				</p>
			</div>
			<div class="afsr-pro-banner__actions">
				<a
					class="afsr-btn afsr-btn-primary"
					href="<?php echo esc_url( $primary_url ); ?>"
					<?php echo $primary_blank ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
				><?php echo esc_html( $primary_lbl ); ?></a>
				<a class="afsr-link" href="<?php echo esc_url( self::features_page_url() ); ?>"><?php esc_html_e( 'See Pro Features', 'add-from-server-reloaded' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue Import UI assets for locked Pro screens / Settings.
	 *
	 * @since 5.4.3
	 */
	public static function enqueue_locked_ui_assets() {
		$wizard_css = AFSRRELOADED_PLUGIN_DIR_PATH . 'assets/css/admin-styles.css';
		$wizard_js  = AFSRRELOADED_PLUGIN_DIR_PATH . 'assets/js/admin-scripts.js';

		if ( ! wp_style_is( 'afsr-admin-ui', 'registered' ) ) {
			wp_register_style(
				'afsr-admin-ui',
				plugins_url( 'assets/css/admin-styles.css', AFSRRELOADED_PLUGIN_FILE ),
				array( 'add-from-server-reloaded' ),
				file_exists( $wizard_css ) ? (string) filemtime( $wizard_css ) : AFSRRELOADED_VERSION
			);
		}

		if ( ! wp_script_is( 'afsr-admin-ui', 'registered' ) ) {
			wp_register_script(
				'afsr-admin-ui',
				plugins_url( 'assets/js/admin-scripts.js', AFSRRELOADED_PLUGIN_FILE ),
				array( 'jquery', 'add-from-server-reloaded' ),
				file_exists( $wizard_js ) ? (string) filemtime( $wizard_js ) : AFSRRELOADED_VERSION,
				true
			);
		}

		wp_enqueue_style( 'add-from-server-reloaded' );
		wp_enqueue_style( 'afsr-admin-ui' );
		wp_enqueue_script( 'add-from-server-reloaded' );
		wp_enqueue_script( 'afsr-admin-ui' );
	}

	/**
	 * Sidebar-only PRO badge / Get Pro styling (keeps default WP admin fonts).
	 *
	 * @since 5.4.3
	 */
	public static function enqueue_menu_badge_assets() {
		$css = '
			#adminmenu .afsr-menu-pro-badge {
				display: inline-block;
				margin-left: 6px;
				padding: 1px 6px;
				border-radius: 999px;
				background: #8a6d3b;
				color: #f5e6b8;
				font-size: 10px;
				font-weight: 700;
				letter-spacing: 0.04em;
				line-height: 1.5;
				vertical-align: middle;
			}
			#adminmenu .wp-submenu a[href*="elearningevolve.com/products/add-from-server-reloaded-pro"] {
				color: #e8c56a !important;
			}
			#adminmenu .afsr-menu-external {
				margin-left: 4px;
				font-size: 12px;
			}
		';
		wp_register_style( 'afsr-admin-menu-badges', false, array(), AFSRRELOADED_VERSION );
		wp_enqueue_style( 'afsr-admin-menu-badges' );
		wp_add_inline_style( 'afsr-admin-menu-badges', $css );
	}

	/**
	 * Add PRO badges to Free submenu labels and normalize order.
	 *
	 * @since 5.4.3
	 */
	public static function decorate_submenu_labels() {
		global $submenu;
		if ( empty( $submenu['add-from-server-reloaded'] ) || ! is_array( $submenu['add-from-server-reloaded'] ) ) {
			return;
		}

		$top_label = self::is_pro_active()
			? __( 'AFS Pro', 'add-from-server-reloaded' )
			: __( 'AFS Lite', 'add-from-server-reloaded' );

		// Keep the auto first submenu item label in sync with the top-level menu name.
		foreach ( $submenu['add-from-server-reloaded'] as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item[2] ) ) {
				continue;
			}
			if ( 'add-from-server-reloaded' === (string) $item[2] ) {
				$submenu['add-from-server-reloaded'][ $index ][0] = $top_label;
			}
		}

		if ( self::is_pro_active() ) {
			return;
		}

		$pro_slugs = array(
			'add-from-server-reloaded-history'   => true,
			'add-from-server-reloaded-scheduler' => true,
		);

		$desired = array(
			'add-from-server-reloaded',
			'add-from-server-reloaded-history',
			'add-from-server-reloaded-scheduler',
			'add-from-server-reloaded-remote',
			'add-from-server-reloaded-duplicates',
			'add-from-server-reloaded-email',
			'add-from-server-reloaded-access',
			'add-from-server-reloaded-settings',
			self::PAGE_FEATURES,
		);

		$by_slug = array();
		$extras  = array();
		foreach ( $submenu['add-from-server-reloaded'] as $item ) {
			if ( ! is_array( $item ) || empty( $item[2] ) ) {
				continue;
			}
			$slug = (string) $item[2];
			if ( isset( $pro_slugs[ $slug ] ) && false === strpos( (string) $item[0], 'afsr-menu-pro-badge' ) ) {
				$item[0] = wp_strip_all_tags( (string) $item[0] ) . ' <span class="afsr-menu-pro-badge">PRO</span>';
			}
			if ( 0 === strpos( $slug, 'http://' ) || 0 === strpos( $slug, 'https://' ) ) {
				if ( false === strpos( (string) $item[0], 'afsr-menu-external' ) ) {
					$item[0] = wp_strip_all_tags( (string) $item[0] ) . ' <span class="afsr-menu-external" aria-hidden="true">↗</span>';
				}
				$extras[] = $item;
				continue;
			}
			$by_slug[ $slug ] = $item;
		}

		$ordered = array();
		foreach ( $desired as $slug ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$ordered[] = $by_slug[ $slug ];
				unset( $by_slug[ $slug ] );
			}
		}
		foreach ( $by_slug as $item ) {
			$ordered[] = $item;
		}
		foreach ( $extras as $item ) {
			$ordered[] = $item;
		}

		$submenu['add-from-server-reloaded'] = $ordered;
	}

	/**
	 * Catalog of Pro features (non-technical one-line descriptions).
	 *
	 * @since 5.4.2
	 *
	 * @return array<string,array{title:string,description:string,slug:string}>
	 */
	public static function feature_catalog() {
		return array(
			'background'          => array(
				'title'       => __( 'Keep importing after you leave', 'add-from-server-reloaded' ),
				'description' => __( 'Start a large import, then leave the page — Pro keeps working in the background until it finishes.', 'add-from-server-reloaded' ),
				'slug'        => 'background',
			),
			'defer_thumbnails'    => array(
				'title'       => __( 'Faster image imports', 'add-from-server-reloaded' ),
				'description' => __( 'Import photos first, then create the smaller preview sizes later so big image jobs finish sooner.', 'add-from-server-reloaded' ),
				'slug'        => 'defer_thumbnails',
			),
			'folder_preserve'     => array(
				'title'       => __( 'Keep your folder layout', 'add-from-server-reloaded' ),
				'description' => __( 'Imported files keep the same folder / subfolder layout they had on the server, instead of only a date folder.', 'add-from-server-reloaded' ),
				'slug'        => 'folder_preserve',
			),
			'advanced_duplicates' => array(
				'title'       => __( 'Smarter duplicate handling', 'add-from-server-reloaded' ),
				'description' => __( 'Choose to skip, replace, or keep both copies when the same file already exists — plus tools to clean up duplicates.', 'add-from-server-reloaded' ),
				'slug'        => 'advanced_duplicates',
			),
			'queue_controls'      => array(
				'title'       => __( 'Pause, resume, and retry', 'add-from-server-reloaded' ),
				'description' => __( 'Pause a long import, continue later, and retry only the files that failed — without starting over.', 'add-from-server-reloaded' ),
				'slug'        => 'queue_controls',
			),
			'history'             => array(
				'title'       => __( 'Import History', 'add-from-server-reloaded' ),
				'description' => __( 'See every past import job, what succeeded or failed, and open details for each file.', 'add-from-server-reloaded' ),
				'slug'        => 'history',
			),
			'scheduled_imports'   => array(
				'title'       => __( 'Scheduled Imports', 'add-from-server-reloaded' ),
				'description' => __( 'Automatically pull files from a folder on a schedule (hourly, daily, and more) without doing it by hand.', 'add-from-server-reloaded' ),
				'slug'        => 'scheduled_imports',
			),
			'ftp_sftp'            => array(
				'title'       => __( 'Remote Sources (FTP / SFTP / cloud)', 'add-from-server-reloaded' ),
				'description' => __( 'Connect to another server or S3-style cloud storage, pick files there, then import them into WordPress.', 'add-from-server-reloaded' ),
				'slug'        => 'ftp_sftp',
			),
			'email_notifications' => array(
				'title'       => __( 'Email alerts', 'add-from-server-reloaded' ),
				'description' => __( 'Get an email when an import finishes or fails, so you do not have to watch the screen.', 'add-from-server-reloaded' ),
				'slug'        => 'email_notifications',
			),
			'rbac'                => array(
				'title'       => __( 'Access Control', 'add-from-server-reloaded' ),
				'description' => __( 'Choose which user roles can only browse files, and which roles are allowed to import.', 'add-from-server-reloaded' ),
				'slug'        => 'rbac',
			),
			'rest_api'            => array(
				'title'       => __( 'REST API', 'add-from-server-reloaded' ),
				'description' => __( 'Let other apps or automation tools start and check imports through WordPress’s API.', 'add-from-server-reloaded' ),
				'slug'        => 'rest_api',
			),
			'wp_cli'              => array(
				'title'       => __( 'Command-line tools (WP-CLI)', 'add-from-server-reloaded' ),
				'description' => __( 'Run imports and schedules from the server command line — useful for developers and hosting scripts.', 'add-from-server-reloaded' ),
				'slug'        => 'wp_cli',
			),
		);
	}

	/**
	 * Feature map for JavaScript (slug => title/description).
	 *
	 * @since 5.4.2
	 *
	 * @return array<string,array{title:string,description:string}>
	 */
	public static function feature_catalog_for_js() {
		$out = array();
		foreach ( self::feature_catalog() as $slug => $item ) {
			$out[ $slug ] = array(
				'title'       => $item['title'],
				'description' => $item['description'],
			);
		}
		return $out;
	}

	/**
	 * Small PRO badge HTML.
	 *
	 * @since 5.4.2
	 *
	 * @return string
	 */
	public static function badge_html() {
		return '<span class="afsrreloaded-pro-badge" aria-hidden="true">' . esc_html__( 'PRO', 'add-from-server-reloaded' ) . '</span>';
	}

	/**
	 * Compact “Pro options available” summary for the import page.
	 *
	 * @since 5.4.2
	 */
	public static function render_import_summary() {
		if ( self::is_pro_active() ) {
			return;
		}
		?>
		<div class="afsrreloaded-pro-import-summary">
			<div class="afsrreloaded-pro-import-summary__icon" aria-hidden="true">
				<span class="dashicons dashicons-lock"></span>
			</div>
			<div class="afsrreloaded-pro-import-summary__body">
				<strong>
					<?php esc_html_e( 'Pro options available', 'add-from-server-reloaded' ); ?>
					<?php echo self::badge_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</strong>
				<p>
					<?php esc_html_e( 'Background imports, folder layout, schedules, remote sources, history, and more are unlocked with Pro.', 'add-from-server-reloaded' ); ?>
				</p>
			</div>
			<div class="afsrreloaded-pro-import-summary__actions">
				<a class="button button-primary" href="<?php echo esc_url( self::features_page_url() ); ?>">
					<?php esc_html_e( 'View Pro Features', 'add-from-server-reloaded' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( self::upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Get Pro', 'add-from-server-reloaded' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings-page Pro upsell — same banner UI as locked Pro screens.
	 *
	 * @since 5.4.2
	 */
	public static function render_settings_section() {
		self::render_upgrade_banner(
			__( 'Unlock the full Add From Server Reloaded toolkit', 'add-from-server-reloaded' ),
			__( 'Pro adds background imports, history, schedules, remote FTP/S3, email alerts, and access control.', 'add-from-server-reloaded' )
		);
	}

	/**
	 * Single locked control for queue actions in the progress panel.
	 *
	 * @since 5.4.2
	 */
	public static function render_locked_queue_summary() {
		if ( self::is_pro_active() ) {
			return;
		}
		?>
		<button type="button" class="button" disabled>
			<?php esc_html_e( 'Pause / Resume / Retry', 'add-from-server-reloaded' ); ?>
			<?php echo self::badge_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>
		<?php
	}

	/**
	 * Shared upgrade modal markup (disabled in Free UX).
	 *
	 * @since 5.4.2
	 */
	public static function render_modal() {
		// Intentionally empty: Free no longer shows the Pro popup modal.
	}

	/**
	 * Free sidebar: Pro Features page + Get Pro (external, never as first item).
	 *
	 * @since 5.4.2
	 */
	/**
	 * Free sidebar: Pro Features page + Get Pro (external, never as first item).
	 *
	 * @since 5.4.2
	 */
	public static function register_teaser_menus() {
		if ( self::is_pro_active() ) {
			return;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$hook = add_submenu_page(
			'add-from-server-reloaded',
			__( 'Pro Features', 'add-from-server-reloaded' ),
			__( 'Pro Features', 'add-from-server-reloaded' ),
			'upload_files',
			self::PAGE_FEATURES,
			array( __CLASS__, 'render_features_page' )
		);

		if ( $hook ) {
			add_action(
				'load-' . $hook,
				static function () {
					self::enqueue_locked_ui_assets();
				}
			);
		}

		add_action( 'admin_menu', array( __CLASS__, 'append_get_pro_menu_link' ), 999 );
	}

	/**
	 * Append external Get Pro link as the last submenu item only.
	 *
	 * WordPress uses the first submenu slug as the parent menu URL. An external
	 * URL must never sit at index 0 or "Add From Server" will leave wp-admin.
	 *
	 * @since 5.4.2
	 */
	public static function append_get_pro_menu_link() {
		if ( self::is_pro_active() ) {
			return;
		}

		global $submenu;

		if ( empty( $submenu['add-from-server-reloaded'] ) || ! is_array( $submenu['add-from-server-reloaded'] ) ) {
			return;
		}

		$upgrade = self::upgrade_url();

		// Drop any prior Get Pro entries (avoid duplicates on re-register).
		$submenu['add-from-server-reloaded'] = array_values(
			array_filter(
				$submenu['add-from-server-reloaded'],
				static function ( $item ) use ( $upgrade ) {
					return ! ( is_array( $item ) && isset( $item[2] ) && (string) $item[2] === $upgrade );
				}
			)
		);

		// If index 0 was somehow an external URL, restore the main plugin page first.
		$first_slug = isset( $submenu['add-from-server-reloaded'][0][2] ) ? (string) $submenu['add-from-server-reloaded'][0][2] : '';
		if ( 0 === strpos( $first_slug, 'http://' ) || 0 === strpos( $first_slug, 'https://' ) ) {
			array_unshift(
				$submenu['add-from-server-reloaded'],
				array(
					Features::is_pro()
						? __( 'AFS Pro', 'add-from-server-reloaded' )
						: __( 'AFS Lite', 'add-from-server-reloaded' ),
					'upload_files',
					'add-from-server-reloaded',
				)
			);
		}

		$submenu['add-from-server-reloaded'][] = array(
			__( 'Get Pro', 'add-from-server-reloaded' ),
			'upload_files',
			$upgrade,
		);
	}

	/**
	 * Full Pro Features list page.
	 *
	 * @since 5.4.2
	 */
	public static function render_features_page() {
		if ( self::is_pro_active() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=add-from-server-reloaded' ) );
			exit;
		}

		self::enqueue_locked_ui_assets();
		$cards   = self::features_page_cards();
		$upgrade = self::upgrade_url();
		?>
		<div class="wrap afsr-admin-wrap">
			<div id="afsr-admin-app" class="afsr-wrap afsr-features-page">
				<div class="afsr-features-hero">
					<div>
						<h1 class="afsr-page-title"><?php esc_html_e( 'Pro Features', 'add-from-server-reloaded' ); ?></h1>
						<p class="afsr-page-subtitle"><?php esc_html_e( 'Everything below is included with Add From Server Reloaded Pro.', 'add-from-server-reloaded' ); ?></p>
					</div>
					<a class="afsr-btn afsr-btn-primary" href="<?php echo esc_url( $upgrade ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade to Pro', 'add-from-server-reloaded' ); ?></a>
				</div>

				<div class="afsr-features-grid">
					<?php foreach ( $cards as $card ) : ?>
						<div class="afsr-feature-card">
							<span class="afsr-pro-badge-dark"><?php esc_html_e( 'PRO', 'add-from-server-reloaded' ); ?></span>
							<h2 class="afsr-feature-card__title"><?php echo esc_html( $card['title'] ); ?></h2>
							<p class="afsr-feature-card__desc"><?php echo esc_html( $card['description'] ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Display-only cards for the Pro Features page (matches Free marketing UI).
	 *
	 * @since 5.4.3
	 *
	 * @return array<int,array{title:string,description:string}>
	 */
	public static function features_page_cards() {
		return array(
			array(
				'title'       => __( 'Keep importing after you leave', 'add-from-server-reloaded' ),
				'description' => __( 'Start a large import, then leave the page — Pro keeps working until it finishes.', 'add-from-server-reloaded' ),
			),
			array(
				'title'       => __( 'Faster image imports', 'add-from-server-reloaded' ),
				'description' => __( 'Import files first, generate thumbnail sizes later so big batches finish sooner.', 'add-from-server-reloaded' ),
			),
			array(
				'title'       => __( 'Keep your folder layout', 'add-from-server-reloaded' ),
				'description' => __( 'Imported files keep the same folder structure they had on the server.', 'add-from-server-reloaded' ),
			),
			array(
				'title'       => __( 'Smarter duplicate handling', 'add-from-server-reloaded' ),
				'description' => __( 'Skip, replace, or import as new when a file already exists.', 'add-from-server-reloaded' ),
			),
			array(
				'title'       => __( 'Pause, resume, and retry', 'add-from-server-reloaded' ),
				'description' => __( 'Pause a long import and continue later. Retry only the files that failed.', 'add-from-server-reloaded' ),
			),
			array(
				'title'       => __( 'Import History', 'add-from-server-reloaded' ),
				'description' => __( 'See every past import job, what succeeded or failed, and open per-file details.', 'add-from-server-reloaded' ),
			),
			array(
				'title'       => __( 'Scheduled Imports', 'add-from-server-reloaded' ),
				'description' => __( 'Pull files from a folder automatically, on a schedule, no manual runs needed.', 'add-from-server-reloaded' ),
			),
			array(
				'title'       => __( 'Remote Sources', 'add-from-server-reloaded' ),
				'description' => __( 'Connect FTP, SFTP, or S3-compatible storage, browse it, and import straight in.', 'add-from-server-reloaded' ),
			),
			array(
				'title'       => __( 'Email alerts', 'add-from-server-reloaded' ),
				'description' => __( 'Get an email when an import finishes or fails, so you don\'t have to watch it.', 'add-from-server-reloaded' ),
			),
			array(
				'title'       => __( 'Access Control', 'add-from-server-reloaded' ),
				'description' => __( 'Choose which roles can browse files and which can run imports.', 'add-from-server-reloaded' ),
			),
			array(
				'title'       => __( 'REST API & WP-CLI', 'add-from-server-reloaded' ),
				'description' => __( 'Start and check imports from other tools, automations, or the command line.', 'add-from-server-reloaded' ),
			),
		);
	}

	/**
	 * Enqueue teaser assets on our admin screens.
	 *
	 * @since 5.4.2
	 *
	 * @param string $hook Current admin hook.
	 */
	public static function enqueue_assets( $hook ) {
		unset( $hook );

		if ( ! self::is_plugin_screen() ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'add-from-server-reloaded' );
		wp_enqueue_script( 'add-from-server-reloaded' );

		wp_register_style(
			'afsrreloaded-pro-teaser',
			plugins_url( 'admin/css/pro-teaser.css', AFSRRELOADED_PLUGIN_FILE ),
			array( 'add-from-server-reloaded' ),
			AFSRRELOADED_VERSION
		);

		wp_register_script(
			'afsrreloaded-pro-teaser',
			plugins_url( 'admin/js/pro-teaser.js', AFSRRELOADED_PLUGIN_FILE ),
			array( 'jquery', 'add-from-server-reloaded' ),
			AFSRRELOADED_VERSION,
			true
		);

		wp_localize_script(
			'afsrreloaded-pro-teaser',
			'afsrreloadedProTeaser',
			array(
				'isPro'      => self::is_pro_active(),
				'upgradeUrl' => self::upgrade_url(),
				'features'   => self::is_pro_active() ? array() : self::feature_catalog_for_js(),
				'i18n'       => array(
					'defaultTitle' => __( 'This is a Pro feature', 'add-from-server-reloaded' ),
					'upgrade'      => __( 'Upgrade to Pro', 'add-from-server-reloaded' ),
				),
			)
		);

		wp_enqueue_style( 'afsrreloaded-pro-teaser' );
		wp_enqueue_script( 'afsrreloaded-pro-teaser' );

		// Force brand primary button color on all plugin screens (Chrome/Firefox parity).
		$brand = self::BRAND_PRIMARY;
		$hover = '#1430b8';
		$css   = '
			body.toplevel_page_add-from-server-reloaded .wrap .button-primary,
			body[class*="add-from-server-reloaded"] .wrap .button-primary,
			body[class*="add-from-server-reloaded"] .afsrreloaded-pro-modal .button-primary {
				background: ' . $brand . ' !important;
				border-color: ' . $brand . ' !important;
				color: #fff !important;
				text-shadow: none !important;
				box-shadow: none !important;
			}
			body.toplevel_page_add-from-server-reloaded .wrap .button-primary:hover,
			body.toplevel_page_add-from-server-reloaded .wrap .button-primary:focus,
			body[class*="add-from-server-reloaded"] .wrap .button-primary:hover,
			body[class*="add-from-server-reloaded"] .wrap .button-primary:focus,
			body[class*="add-from-server-reloaded"] .afsrreloaded-pro-modal .button-primary:hover,
			body[class*="add-from-server-reloaded"] .afsrreloaded-pro-modal .button-primary:focus {
				background: ' . $hover . ' !important;
				border-color: ' . $hover . ' !important;
				color: #fff !important;
			}
			.afsrreloaded-pro-badge { background: ' . $brand . ' !important; }
		';
		wp_add_inline_style( 'afsrreloaded-pro-teaser', $css );
	}

	/**
	 * Whether the current admin screen belongs to this plugin.
	 *
	 * @since 5.4.2
	 *
	 * @return bool
	 */
	protected static function is_plugin_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page ) {
			return false;
		}

		return 0 === strpos( $page, 'add-from-server-reloaded' );
	}
}
