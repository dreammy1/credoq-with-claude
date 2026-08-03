<?php
/**
 * Admin Shell — Booknetic-style full-screen takeover for Credoq admin pages.
 *
 * How it works:
 *   1. On 'admin_init' we detect if the current page is a Credoq admin page.
 *   2. If yes:
 *      - Add a body class 'credoq-takeover' that hides WP chrome via CSS.
 *      - Wrap the page render in our own shell HTML (header + sidebar + content).
 *      - Inject the floating "Back to WordPress" button.
 *   3. If no: do nothing. Standard WP admin renders normally.
 *
 * Addons register sidebar entries via the 'credoq_admin_sidebar_items' filter.
 * Engine adds the core entries (Dashboard, Forms, Submissions, Settings).
 *
 * @package CredoqEngine\Admin
 */

namespace CredoqEngine\Admin;

defined( 'ABSPATH' ) || exit;

class Shell {

	/** @var bool */
	private static $is_credoq_page = false;

	/** @var bool */
	private static $booted = false;

	public static function boot() : void {
		if ( self::$booted ) return;
		self::$booted = true;

		add_action( 'admin_init',         [ __CLASS__, 'detect_credoq_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 100 );
		add_filter( 'admin_body_class',   [ __CLASS__, 'body_class' ] );
		add_action( 'in_admin_header',    [ __CLASS__, 'open_shell' ], 0 );
		add_action( 'admin_footer',       [ __CLASS__, 'close_shell' ], 999 );
		add_action( 'admin_head',         [ __CLASS__, 'inline_dark_mode_bootstrap' ], 1 );
	}

	public static function detect_credoq_page() : void {
		self::$is_credoq_page = \CredoqEngine\Assets::is_credoq_admin_screen();
	}

	public static function is_active() : bool {
		return self::$is_credoq_page;
	}

	public static function body_class( $classes ) {
		if ( ! self::$is_credoq_page ) return $classes;
		return $classes . ' credoq-takeover';
	}

	/**
	 * Outputs a tiny inline script in <head> that applies dark-mode class
	 * BEFORE the page paints, so we don't get a flash of light theme.
	 */
	public static function inline_dark_mode_bootstrap() : void {
		if ( ! self::$is_credoq_page ) return;
		?>
		<script>
		(function () {
			try {
				var saved = localStorage.getItem('credoq_theme');
				var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
				var dark = saved ? (saved === 'dark') : prefersDark;
				if (dark) document.documentElement.classList.add('credoq-dark');
				document.documentElement.classList.add('credoq-theme-ready');
			} catch (e) { /* localStorage blocked */ }
		})();
		</script>
		<?php
	}

	public static function enqueue_assets( $hook ) : void {
		if ( ! self::$is_credoq_page ) return;

		// Our admin shell CSS — must load AFTER core wp-admin so our
		// overrides win. priority 100 above ensures hook order.
		wp_enqueue_style(
			'credoq-admin-shell',
			CREDOQ_ENGINE_URL . 'assets/css/admin-shell.css',
			array(),
			CREDOQ_ENGINE_VERSION
		);
		wp_enqueue_script(
			'credoq-admin-shell',
			CREDOQ_ENGINE_URL . 'assets/js/admin-shell.js',
			array(),
			CREDOQ_ENGINE_VERSION,
			true
		);
		wp_localize_script( 'credoq-admin-shell', 'CredoqShell', array(
			'wpDashUrl' => admin_url( 'index.php' ),
			'i18n'      => array(
				'backToWp'   => __( 'Back to WordPress', 'credoq-engine' ),
				'toggleNav'  => __( 'Toggle navigation', 'credoq-engine' ),
				'lightMode'  => __( 'Switch to light mode', 'credoq-engine' ),
				'darkMode'   => __( 'Switch to dark mode', 'credoq-engine' ),
			),
		) );
	}

	/**
	 * Opens the takeover container right after WP's #wpwrap. Outputs:
	 *   .credoq-shell
	 *     .credoq-shell-header
	 *     .credoq-shell-body
	 *       .credoq-shell-sidebar
	 *       .credoq-shell-content  ← WP's .wrap renders inside here
	 *     .credoq-shell-fab (floating WP button)
	 */
	public static function open_shell() : void {
		if ( ! self::$is_credoq_page ) return;

		$user           = wp_get_current_user();
		$sidebar_items  = self::get_sidebar_items();
		$current_slug   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'credoq';
		$breadcrumb     = self::get_breadcrumb( $current_slug, $sidebar_items );

		?>
		<div class="credoq-shell" id="credoq-shell">
			<header class="credoq-shell-header" role="banner">
				<div class="credoq-shell-header-left">
					<button type="button" class="credoq-shell-burger" id="credoq-shell-burger"
					        aria-label="<?php esc_attr_e( 'Toggle navigation', 'credoq-engine' ); ?>"
					        aria-controls="credoq-shell-sidebar" aria-expanded="false">
						<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=credoq' ) ); ?>" class="credoq-shell-brand">
						<span class="credoq-shell-brand-mark" aria-hidden="true">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M8 12l3 3 5-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</span>
						<span class="credoq-shell-brand-name">Credoq</span>
					</a>
					<nav class="credoq-shell-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'credoq-engine' ); ?>">
						<?php foreach ( $breadcrumb as $i => $crumb ) : ?>
							<?php if ( $i > 0 ) : ?>
								<span class="credoq-shell-breadcrumb-sep" aria-hidden="true">/</span>
							<?php endif; ?>
							<?php if ( ! empty( $crumb['url'] ) && $i < count( $breadcrumb ) - 1 ) : ?>
								<a href="<?php echo esc_url( $crumb['url'] ); ?>"><?php echo esc_html( $crumb['label'] ); ?></a>
							<?php else : ?>
								<span aria-current="page"><?php echo esc_html( $crumb['label'] ); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</nav>
				</div>
				<div class="credoq-shell-header-right">
					<button type="button" class="credoq-shell-theme-toggle" id="credoq-shell-theme-toggle"
					        aria-label="<?php esc_attr_e( 'Toggle theme', 'credoq-engine' ); ?>">
						<svg class="credoq-shell-theme-sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
						<svg class="credoq-shell-theme-moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
					</button>
					<?php $unread = self::notifications_badge(); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-notifications' ) ); ?>"
					   class="credoq-shell-theme-toggle" style="position:relative;text-decoration:none;"
					   aria-label="<?php esc_attr_e( 'Notifications', 'credoq-engine' ); ?>"
					   title="<?php esc_attr_e( 'Notifications', 'credoq-engine' ); ?>">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
						<?php if ( $unread ) : ?>
						<span style="position:absolute;top:-2px;right:-2px;background:#dc2626;color:#fff;font-size:10px;font-weight:800;line-height:1;border-radius:9px;min-width:16px;height:16px;display:flex;align-items:center;justify-content:center;padding:0 3px;"><?php echo esc_html( $unread ); ?></span>
						<?php endif; ?>
					</a>
					<div class="credoq-shell-user">
						<div class="credoq-shell-user-avatar" aria-hidden="true">
							<?php echo esc_html( strtoupper( substr( $user->display_name, 0, 1 ) ) ); ?>
						</div>
						<div class="credoq-shell-user-info">
							<div class="credoq-shell-user-name"><?php echo esc_html( $user->display_name ); ?></div>
							<div class="credoq-shell-user-role"><?php echo esc_html( self::user_role_label( $user ) ); ?></div>
						</div>
					</div>
				</div>
			</header>

			<div class="credoq-shell-body">
				<aside class="credoq-shell-sidebar" id="credoq-shell-sidebar" role="navigation"
				       aria-label="<?php esc_attr_e( 'Credoq navigation', 'credoq-engine' ); ?>">
					<nav class="credoq-shell-sidebar-nav">
						<?php
						$current_group = null;
						foreach ( $sidebar_items as $item ) :
							if ( ! empty( $item['group'] ) && $item['group'] !== $current_group ) :
								if ( null !== $current_group ) echo '</div>';
								$current_group = $item['group'];
								echo '<div class="credoq-shell-sidebar-group"><div class="credoq-shell-sidebar-group-label">' . esc_html( $current_group ) . '</div>';
							endif;
							$is_active = ( $item['slug'] === $current_slug );
							?>
							<a href="<?php echo esc_url( $item['url'] ); ?>"
							   class="credoq-shell-sidebar-item<?php echo $is_active ? ' is-active' : ''; ?>"
							   <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
								<span class="credoq-shell-sidebar-item-icon" aria-hidden="true"><?php echo self::sidebar_icon( $item['icon'] ?? 'home' ); ?></span>
								<span class="credoq-shell-sidebar-item-label"><?php echo esc_html( $item['label'] ); ?></span>
								<?php if ( ! empty( $item['badge'] ) ) : ?>
									<span class="credoq-shell-sidebar-item-badge"><?php echo esc_html( $item['badge'] ); ?></span>
								<?php endif; ?>
							</a>
						<?php endforeach;
						if ( null !== $current_group ) echo '</div>';
						?>
					</nav>
					<div class="credoq-shell-sidebar-footer">
						<div class="credoq-shell-sidebar-version">
							v<?php echo esc_html( CREDOQ_ENGINE_VERSION ); ?>
						</div>
					</div>
				</aside>

				<main class="credoq-shell-content" id="credoq-shell-content" role="main">
					<div class="credoq-shell-backdrop" id="credoq-shell-backdrop" aria-hidden="true"></div>
					<div class="credoq-shell-content-inner">
		<?php
	}

	public static function close_shell() : void {
		if ( ! self::$is_credoq_page ) return;
		?>
					</div><!-- .credoq-shell-content-inner -->
				</main>
			</div><!-- .credoq-shell-body -->

			<a href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>"
			   class="credoq-shell-fab" id="credoq-shell-fab"
			   title="<?php esc_attr_e( 'Back to WordPress', 'credoq-engine' ); ?>"
			   aria-label="<?php esc_attr_e( 'Back to WordPress dashboard', 'credoq-engine' ); ?>">
				<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M19 12H5"/>
					<polyline points="12 19 5 12 12 5"/>
				</svg>
				<span class="credoq-shell-fab-label">WP</span>
			</a>
		</div><!-- .credoq-shell -->
		<?php
	}

	/**
	 * Builds the sidebar items array.
	 *
	 * Each item: [
	 *   'slug'  => 'credoq-forms',
	 *   'label' => 'Forms',
	 *   'icon'  => 'forms',
	 *   'url'   => admin_url('admin.php?page=credoq-forms'),
	 *   'group' => 'Engine' (optional grouping label),
	 *   'badge' => '3'      (optional badge count),
	 *   'priority' => 20    (sort order),
	 * ]
	 *
	 * Addons add items via the 'credoq_admin_sidebar_items' filter.
	 */
	public static function get_sidebar_items() : array {
		$default = array(
			array(
				'slug'     => 'credoq',
				'label'    => __( 'Advance Report', 'credoq-engine' ),
				'icon'     => 'chart-bar',
				'url'      => admin_url( 'admin.php?page=credoq' ),
				'group'    => __( 'Credoq Overview', 'credoq-engine' ),
				'priority' => 10,
			),
			array(
				'slug'     => 'credoq-forms',
				'label'    => __( 'Forms Builder', 'credoq-engine' ),
				'icon'     => 'forms',
				'url'      => admin_url( 'admin.php?page=credoq-forms' ),
				'group'    => __( 'Forms Builder Pro', 'credoq-engine' ),
				'priority' => 20,
			),
			array(
				'slug'     => 'credoq-submissions',
				'label'    => __( 'General Submissions', 'credoq-engine' ),
				'icon'     => 'inbox',
				'url'      => admin_url( 'admin.php?page=credoq-submissions' ),
				'group'    => __( 'Forms Builder Pro', 'credoq-engine' ),
				'priority' => 30,
			),
			array(
				'slug'     => 'credoq-settings',
				'label'    => __( 'Core Settings', 'credoq-engine' ),
				'icon'     => 'settings',
				'url'      => admin_url( 'admin.php?page=credoq-settings' ),
				'group'    => __( 'Forms Builder Pro', 'credoq-engine' ),
				'priority' => 35,
			),
			array(
				'slug'     => 'credoq-tools',
				'label'    => __( 'Automation Tools', 'credoq-engine' ),
				'icon'     => 'tools',
				'url'      => admin_url( 'admin.php?page=credoq-tools' ),
				'group'    => __( 'System Health Monitor', 'credoq-engine' ),
				'priority' => 95,
			),
			array(
				'slug'     => 'credoq-smtp',
				'label'    => __( 'SMTP', 'credoq-engine' ),
				'icon'     => 'settings',
				'url'      => admin_url( 'admin.php?page=credoq-smtp' ),
				'group'    => __( 'System Health Monitor', 'credoq-engine' ),
				'priority' => 96,
			),
			array(
				'slug'     => 'credoq-notifications',
				'label'    => __( 'Notifications', 'credoq-engine' ),
				'icon'     => 'star',
				'url'      => admin_url( 'admin.php?page=credoq-notifications' ),
				'group'    => __( 'System Health Monitor', 'credoq-engine' ),
				'badge'    => self::notifications_badge(),
				'priority' => 97,
			),
			array(
				'slug'     => 'credoq-audit-log',
				'label'    => __( 'Audit log', 'credoq-engine' ),
				'icon'     => 'report',
				'url'      => admin_url( 'admin.php?page=credoq-audit-log' ),
				'group'    => __( 'System Health Monitor', 'credoq-engine' ),
				'priority' => 98,
			),
		);
		$items = apply_filters( 'credoq_admin_sidebar_items', $default );

		// Normalize + sort.
		$clean = array();
		foreach ( $items as $item ) {
			if ( empty( $item['slug'] ) || empty( $item['label'] ) ) continue;
			$clean[] = wp_parse_args( $item, array(
				'icon'     => 'home',
				'url'      => admin_url( 'admin.php?page=' . sanitize_key( $item['slug'] ) ),
				'group'    => '',
				'badge'    => '',
				'priority' => 50,
			) );
		}
		usort( $clean, fn( $a, $b ) => ( (int) $a['priority'] ) <=> ( (int) $b['priority'] ) );
		return $clean;
	}

	private static function get_breadcrumb( string $current_slug, array $items ) : array {
		$home = array( 'label' => __( 'Credoq', 'credoq-engine' ), 'url' => admin_url( 'admin.php?page=credoq' ) );
		if ( 'credoq' === $current_slug ) {
			return array( $home );
		}
		foreach ( $items as $item ) {
			if ( $item['slug'] === $current_slug ) {
				return array( $home, array( 'label' => $item['label'], 'url' => '' ) );
			}
		}
		return array( $home, array( 'label' => ucfirst( str_replace( array( 'credoq-', '-', '_' ), array( '', ' ', ' ' ), $current_slug ) ), 'url' => '' ) );
	}

	/** Unread notifications count, formatted for the sidebar badge / header bell. Empty string = hide. */
	private static function notifications_badge() : string {
		if ( ! class_exists( '\CredoqEngine\Mail\Notifications' ) ) return '';
		$n = \CredoqEngine\Mail\Notifications::count_unread();
		if ( $n <= 0 ) return '';
		return $n > 99 ? '99+' : (string) $n;
	}

	private static function user_role_label( \WP_User $user ) : string {
		if ( empty( $user->roles ) ) return '';
		$role_obj = get_role( $user->roles[0] );
		if ( ! $role_obj ) return ucfirst( $user->roles[0] );
		global $wp_roles;
		if ( $wp_roles && isset( $wp_roles->role_names[ $user->roles[0] ] ) ) {
			return translate_user_role( $wp_roles->role_names[ $user->roles[0] ] );
		}
		return ucfirst( $user->roles[0] );
	}

	private static function sidebar_icon( string $key ) : string {
		$icons = array(
			'home'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 01-1 1H4a1 1 0 01-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>',
			'forms'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
			'inbox'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>',
			'settings'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
			'users'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
			'calendar'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
			'star'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
			'qr'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><line x1="14" y1="14" x2="14" y2="17"/><line x1="14" y1="20" x2="14" y2="21"/><line x1="17" y1="14" x2="20" y2="14"/><line x1="20" y1="17" x2="20" y2="21"/><line x1="17" y1="17" x2="17" y2="20"/></svg>',
			'grid'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
			'ticket'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9.5V8a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v1.5a2.5 2.5 0 0 0 0 5V16a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1.5a2.5 2.5 0 0 0 0-5z"/><path d="M13 5v14"/></svg>',
			'report'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
		);
		return $icons[ $key ] ?? $icons['home'];
	}
}