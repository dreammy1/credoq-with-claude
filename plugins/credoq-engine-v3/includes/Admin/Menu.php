<?php
/**
 * Admin menu — Engine top-level "Credoq" + core submenus.
 * AUDIT-FIX B-5: parent slug is 'credoq' (was 'jfb-slot-tracker').
 *
 * @package CredoqEngine\Admin
 */

namespace CredoqEngine\Admin;

defined( 'ABSPATH' ) || exit;

class Menu {

	const PARENT_SLUG = 'credoq';

	public static function register() : void {
		add_menu_page(
			__( 'Credoq', 'credoq-engine' ),
			__( 'Credoq', 'credoq-engine' ),
			'manage_options',
			self::PARENT_SLUG,
			[ __CLASS__, 'render_dashboard' ],
			self::menu_icon_svg(),
			56
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Dashboard', 'credoq-engine' ),
			__( 'Dashboard', 'credoq-engine' ),
			'manage_options',
			self::PARENT_SLUG,
			[ __CLASS__, 'render_dashboard' ]
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Forms', 'credoq-engine' ),
			__( 'Forms', 'credoq-engine' ),
			'manage_options',
			'credoq-forms',
			[ Forms_Page::class, 'render' ]
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Submissions', 'credoq-engine' ),
			__( 'Submissions', 'credoq-engine' ),
			'manage_options',
			'credoq-submissions',
			[ Submissions_Page::class, 'render' ]
		);

		// Unified Reports — tabs auto-populated by installed addons
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Reports', 'credoq-engine' ),
			__( 'Reports', 'credoq-engine' ),
			'manage_options',
			'credoq-reports',
			[ Reports_Page::class, 'render' ]
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Settings', 'credoq-engine' ),
			__( 'Settings', 'credoq-engine' ),
			'manage_options',
			'credoq-settings',
			[ Settings_Page::class, 'render' ]
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'SMTP', 'credoq-engine' ),
			__( 'SMTP', 'credoq-engine' ),
			'manage_options',
			'credoq-smtp',
			[ Smtp_Page::class, 'render' ]
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Notifications', 'credoq-engine' ),
			__( 'Notifications', 'credoq-engine' ),
			'manage_options',
			'credoq-notifications',
			[ Notifications_Page::class, 'render' ]
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Audit log', 'credoq-engine' ),
			__( 'Audit log', 'credoq-engine' ),
			'manage_options',
			'credoq-audit-log',
			[ Audit_Page::class, 'render' ]
		);

		// Tools: DB health, upgrade, repair, backup, cleanup
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Credoq Tools', 'credoq-engine' ),
			__( 'Tools 🔧', 'credoq-engine' ),
			'manage_options',
			'credoq-tools',
			[ Tools_Page::class, 'render' ]
		);

		// Addons register their own submenus here.
		do_action( 'credoq_admin_menu', self::PARENT_SLUG );
	}

	public static function render_dashboard() : void {
		global $wpdb;

		$installed_addons = apply_filters( 'credoq_installed_addons', [] );

		$total_forms       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}credoq_forms" );
		$total_submissions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}credoq_submissions" );
		$submissions_today = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}credoq_submissions WHERE created_at >= %s",
			gmdate( 'Y-m-d 00:00:00' )
		) );

		// Cross-addon totals — pulled from addon tables when present
		$active_members = 0;
		$mt = $wpdb->prefix . 'credoq_user_memberships';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mt ) ) === $mt ) {
			$active_members = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$mt} WHERE status='active' AND expiry_date > NOW()"
			);
		}
		$total_bookings = 0;
		$bt = $wpdb->prefix . 'credoq_bookings';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bt ) ) === $bt ) {
			$total_bookings = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$bt} WHERE status IN ('confirmed','completed')"
			);
		}
		$total_events = 0;
		$et = $wpdb->prefix . 'credoq_event_bookings';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $et ) ) === $et ) {
			$total_events = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$et} WHERE status IN ('confirmed')"
			);
		}

		$recent = $wpdb->get_results(
			"SELECT s.id, s.form_id, s.user_email, s.status, s.total_price, s.created_at, f.title AS form_title
			 FROM {$wpdb->prefix}credoq_submissions s
			 LEFT JOIN {$wpdb->prefix}credoq_forms f ON f.id = s.form_id
			 ORDER BY s.id DESC LIMIT 5"
		);

		$settings = get_option( 'credoq_engine_settings', [] );
		$currency = $settings['currency'] ?? 'USD';
		?>
		<div class="wrap credoq-admin-wrap">

		<div class="credoq-page-header">
			<div class="credoq-page-header-inner">
				<h1 class="credoq-page-title">
					<span class="dashicons dashicons-admin-home" style="font-size:28px;margin-right:8px;color:#4f46e5;"></span>
					<?php esc_html_e( 'Credoq Dashboard', 'credoq-engine' ); ?>
				</h1>
				<a href="<?php echo esc_url( admin_url('admin.php?page=credoq-reports') ); ?>"
				   class="button button-primary">View Reports &rarr;</a>
			</div>
		</div>

		<!-- KPI Row -->
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px;">
			<?php foreach ( [
				[ '📋', 'Forms',          $total_forms,       '#4f46e5' ],
				[ '📬', 'Submissions',    $total_submissions, '#0891b2' ],
				[ '🏅', 'Active Members', $active_members,    '#16a34a' ],
				[ '📅', 'Bookings',       $total_bookings,    '#9333ea' ],
				[ '🎟', 'Event Reg.',     $total_events,      '#f59e0b' ],
				[ '📥', 'Today',          $submissions_today, '#64748b' ],
			] as [ $icon, $label, $value, $color ] ) : ?>
			<div class="credoq-card" style="display:flex;gap:12px;align-items:center;padding:18px 16px;">
				<div style="width:44px;height:44px;border-radius:12px;background:<?php echo $color; ?>18;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;"><?php echo $icon; ?></div>
				<div>
					<div style="font-size:26px;font-weight:900;color:<?php echo $color; ?>;line-height:1;"><?php echo number_format_i18n($value); ?></div>
					<div style="font-size:12px;color:#64748b;margin-top:2px;font-weight:600;"><?php echo esc_html($label); ?></div>
				</div>
			</div>
			<?php endforeach; ?>
		</div>

		<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;">

			<!-- Recent submissions -->
			<div class="credoq-card">
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
					<h2 style="margin:0;font-size:15px;font-weight:800;color:#1e293b;"><?php esc_html_e( 'Recent Submissions', 'credoq-engine' ); ?></h2>
					<a href="<?php echo esc_url(admin_url('admin.php?page=credoq-submissions')); ?>" style="font-size:12px;color:#4f46e5;text-decoration:none;font-weight:700;">View all &rarr;</a>
				</div>
				<?php if ( empty($recent) ) : ?>
					<p style="color:#94a3b8;font-size:13px;">No submissions yet. Place a form on a page to start receiving submissions.</p>
				<?php else : ?>
				<table class="credoq-table wp-list-table widefat fixed striped">
					<thead><tr><th>Form</th><th>Email</th><th>Status</th><th>Time</th></tr></thead>
					<tbody>
					<?php foreach ($recent as $r) : ?>
					<tr>
						<td><?php echo esc_html( $r->form_title ?: '#'.(int)$r->form_id ); ?></td>
						<td style="color:#64748b;"><?php echo esc_html( $r->user_email ?: '(guest)' ); ?></td>
						<td><span class="credoq-badge credoq-badge-<?php echo $r->status==='confirmed'?'green':($r->status==='pending'?'blue':'gray'); ?>"><?php echo esc_html($r->status); ?></span></td>
						<td style="color:#94a3b8;font-size:12px;"><?php echo esc_html(human_time_diff(strtotime($r->created_at.' UTC'),time())).' ago'; ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>

			<!-- Installed addons -->
			<div class="credoq-card">
				<h2 style="margin:0 0 16px;font-size:15px;font-weight:800;color:#1e293b;"><?php esc_html_e( 'Installed Addons', 'credoq-engine' ); ?></h2>
				<?php
				$known = [
					'credoq-membership'    => [ '🏅', 'Credoq Membership',    defined('CREDOQ_MEMBERSHIP_VERSION') ],
					'credoq-appointments'  => [ '📅', 'Credoq Appointments',  defined('CREDOQ_APT_VERSION') ],
					'credoq-events'        => [ '🎟', 'Credoq Events',        defined('CREDOQ_EVENTS_VERSION') ],
					'credoq-seat-res'      => [ '💺', 'Seat Reservation',     defined('CREDOQ_SEATS_VERSION') ],
					'credoq-qr-checkin'    => [ '✅', 'QR Check-in',          defined('CREDOQ_QR_VERSION') ],
				];
				foreach ( $known as $slug => [$icon,$name,$active] ) : ?>
				<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;">
					<span style="font-size:18px;"><?php echo $icon; ?></span>
					<span style="flex:1;font-size:13px;font-weight:600;color:<?php echo $active?'#1e293b':'#94a3b8'; ?>;"><?php echo esc_html($name); ?></span>
					<span class="credoq-badge credoq-badge-<?php echo $active?'green':'gray'; ?>"><?php echo $active?'Active':'Not Installed'; ?></span>
				</div>
				<?php endforeach; ?>
				<div style="margin-top:16px;">
					<a href="<?php echo esc_url(admin_url('admin.php?page=credoq-reports')); ?>" class="button button-primary" style="width:100%;text-align:center;">
						📊 Open Reports
					</a>
				</div>
			</div>

		</div>
		</div>
		<?php
	}

	private static function menu_icon_svg() : string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a7aaad" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
