<?php
/**
 * Dashboard SPA — [credoq_dashboard_app], [credoq_sidebar_nav], [credoq_bottom_nav].
 *
 * Ported from the v9 plugin's nav-shortcodes.php WITHOUT VISUAL CHANGES.
 * Layout, CSS, animations, navigation flow — all identical to the v9
 * design the user explicitly asked to preserve.
 *
 * Architectural change vs v9: the panel content is now provided by ADDONS
 * via the 'credoq_dashboard_panels' filter. Each addon can register one or
 * more tabs. The Engine itself ships the dashboard chrome (nav + router)
 * but no specific panel content.
 *
 *   add_filter( 'credoq_dashboard_panels', function( $panels ) {
 *       $panels['my_courses'] = [
 *           'label_sidebar' => 'My Courses',
 *           'label_bottom'  => 'Courses',
 *           'icon_key'      => 'courses',
 *           'render'        => function() { return '<div>...</div>'; },
 *           'priority'      => 30,
 *       ];
 *       return $panels;
 *   });
 *
 * @package CredoqEngine\Dashboard
 */

namespace CredoqEngine\Dashboard;

defined( 'ABSPATH' ) || exit;

class Spa {

	/**
	 * Built-in nav icons. Addons can register more via the
	 * 'credoq_dashboard_nav_icons' filter.
	 */
	private static function nav_icons() : array {
		return apply_filters( 'credoq_dashboard_nav_icons', array(
			'home'        => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 01-1 1H4a1 1 0 01-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>',
			'schedule'    => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>',
			'courses'     => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/><path d="M8 7h8M8 11h5"/></svg>',
			'member_card' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
			'account'     => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>',
		'events'      => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3H7A2 2 0 005 5v14a2 2 0 002 2h10a2 2 0 002-2V5a2 2 0 00-2-2z"/><path d="M3 9h18M9 3v18M15 3v18"/></svg>',
		'my_schedule' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01"/></svg>',
		'my_events'   => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/></svg>',
		'notification'=> '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>',
		) );
	}

	/**
	 * Get all registered panels, sorted by priority.
	 *
	 * @return array<string, array> keyed by panel slug
	 */
	public static function get_panels() : array {
		$panels = apply_filters( 'credoq_dashboard_panels', array() );

		// Validate + default each.
		$clean = array();
		foreach ( $panels as $slug => $cfg ) {
			$slug = sanitize_key( $slug );
			if ( '' === $slug || empty( $cfg['render'] ) || ! is_callable( $cfg['render'] ) ) continue;
			$clean[ $slug ] = wp_parse_args( $cfg, array(
				'label_sidebar' => ucfirst( str_replace( '_', ' ', $slug ) ),
				'label_bottom'  => ucfirst( str_replace( '_', ' ', $slug ) ),
				'icon_key'      => 'home',
				'priority'      => 50,
			) );
		}

		// Sort by priority.
		uasort( $clean, fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
		return $clean;
	}

	/* ═══════════════════════════════════════════════════════════════
	   [credoq_dashboard_app] — main SPA container
	═══════════════════════════════════════════════════════════════ */
	public static function render_dashboard_app( $atts = array() ) : string {
		if ( ! is_user_logged_in() ) {
			return self::login_gate();
		}

		$panels = self::get_panels();
		if ( empty( $panels ) ) {
			return '<div class="credoq-empty-dashboard">'
				. esc_html__( 'No dashboard panels registered. Install at least one Credoq addon.', 'credoq-engine' )
				. '</div>';
		}

		// Enqueue dashboard CSS + SPA router JS.
		\CredoqEngine\Assets::enqueue_dashboard();

		$tabs_json = wp_json_encode( array_keys( $panels ), JSON_UNESCAPED_SLASHES );

		ob_start();
		?>
		<div id="credoq-app" class="credoq-app-wrap" data-tabs="<?php echo esc_attr( $tabs_json ); ?>">
			<?php foreach ( $panels as $slug => $cfg ) : ?>
				<div class="credoq-panel"
				     id="credoq-panel-<?php echo esc_attr( $slug ); ?>"
				     data-tab="<?php echo esc_attr( $slug ); ?>"
				     style="display:none"
				     role="tabpanel"
				     aria-labelledby="credoq-tab-<?php echo esc_attr( $slug ); ?>">
					<?php echo call_user_func( $cfg['render'] ); // output is HTML by contract ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ═══════════════════════════════════════════════════════════════
	   [credoq_sidebar_nav] — desktop sidebar
	═══════════════════════════════════════════════════════════════ */
	public static function render_sidebar_nav( $atts = array() ) : string {
		\CredoqEngine\Assets::enqueue_dashboard();
		$panels = self::get_panels();
		$icons  = self::nav_icons();
		$user   = wp_get_current_user();

		ob_start();
		?>
		<div class="cq-sidebar-nav" id="credoq-sidebar-nav" role="navigation"
		     aria-label="<?php esc_attr_e( 'Dashboard navigation', 'credoq-engine' ); ?>">
			<div class="csn-logo">
				<div class="csn-logo-icon" aria-hidden="true">
					<?php echo $icons['home']; // safe SVG ?>
				</div>
				<div class="csn-logo-text">
					<strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong>
				</div>
			</div>
			<ul class="csn-list">
				<?php foreach ( $panels as $slug => $cfg ) : ?>
					<li>
						<button type="button"
						        class="csn-item"
						        data-credoq-tab="<?php echo esc_attr( $slug ); ?>"
						        aria-current="false">
							<span class="csn-icon"><?php echo $icons[ $cfg['icon_key'] ] ?? $icons['home']; ?></span>
							<span class="csn-label"><?php echo esc_html( $cfg['label_sidebar'] ); ?></span>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $user && $user->ID ) : ?>
				<div class="csn-footer">
					<div class="csn-avatar"><?php echo esc_html( strtoupper( substr( $user->display_name, 0, 1 ) ) ); ?></div>
					<div class="csn-user">
						<div class="csn-name"><?php echo esc_html( $user->display_name ); ?></div>
						<div class="csn-mail"><?php echo esc_html( $user->user_email ); ?></div>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ═══════════════════════════════════════════════════════════════
	   [credoq_bottom_nav] — mobile bottom tab bar
	═══════════════════════════════════════════════════════════════ */
	public static function render_bottom_nav( $atts = array() ) : string {
		\CredoqEngine\Assets::enqueue_dashboard();
		$panels = self::get_panels();
		$icons  = self::nav_icons();

		ob_start();
		?>
		<nav class="cq-bottom-nav" id="credoq-bottom-nav" role="navigation"
		     aria-label="<?php esc_attr_e( 'Mobile dashboard navigation', 'credoq-engine' ); ?>">
			<?php foreach ( $panels as $slug => $cfg ) : ?>
				<button type="button"
				        class="cbn-item"
				        data-credoq-tab="<?php echo esc_attr( $slug ); ?>"
				        aria-current="false">
					<span class="cbn-icon"><?php echo $icons[ $cfg['icon_key'] ] ?? $icons['home']; ?></span>
					<span class="cbn-label"><?php echo esc_html( $cfg['label_bottom'] ); ?></span>
				</button>
			<?php endforeach; ?>
		</nav>
		<?php
		return ob_get_clean();
	}

	private static function login_gate() : string {
		\CredoqEngine\Assets::enqueue_dashboard();
		$url = wp_login_url( get_permalink() );
		return '<div class="credoq-login-gate"><strong>'
			. sprintf(
				/* translators: %s: login URL */
				wp_kses_post( __( 'Please <a href="%s">log in</a> to access your dashboard.', 'credoq-engine' ) ),
				esc_url( $url )
			)
			. '</strong></div>';
	}
}
