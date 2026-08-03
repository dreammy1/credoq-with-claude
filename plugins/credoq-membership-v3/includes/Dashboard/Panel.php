<?php
/**
 * Dashboard Panel — integrates Membership views into the Engine SPA.
 *
 * @package CredoqMembership\Dashboard
 */

namespace CredoqMembership\Dashboard;

use CredoqMembership\Membership_Service;
use CredoqMembership\Plan_Repository;

defined( 'ABSPATH' ) || exit;

class Panel {

	public static function init() : void {
		add_filter( 'credoq_dashboard_panels', [ __CLASS__, 'register_panels' ] );
	}

	public static function register_panels( array $panels ) : array {
		$panels['my_plans'] = array(
			'label_sidebar' => __( 'My Plans', 'credoq-membership' ),
			'label_bottom'  => __( 'Plans', 'credoq-membership' ),
			'icon_key'      => 'courses',
			'render'        => [ __CLASS__, 'render_plans' ],
			'priority'      => 20,
		);

		$panels['member_card'] = array(
			'label_sidebar' => __( 'My Member Card', 'credoq-membership' ),
			'label_bottom'  => __( 'My Card', 'credoq-membership' ),
			'icon_key'      => 'member_card',
			'render'        => [ __CLASS__, 'render_card' ],
			'priority'      => 30,
		);

		return $panels;
	}

	public static function render_plans() : string {
		$user_id = get_current_user_id();
		$service = new Membership_Service();
		$active  = $service->get_active_memberships( $user_id );

		ob_start();
		?>
		<div class="cq-sec-lbl"><?php esc_html_e( 'Active Memberships', 'credoq-membership' ); ?></div>
		<?php if ( empty( $active ) ) : ?>
			<div class="credoq-empty-dashboard">
				<?php esc_html_e( 'You have no active memberships.', 'credoq-membership' ); ?>
			</div>
		<?php else : foreach ( $active as $m ) :
			$repo = new Plan_Repository();
			$plan = $repo->find( (int) $m->plan_id );
			if ( ! $plan ) continue;
			$balance = $service->get_balance( $user_id, (int) $plan->id );
			$limit   = (int) ( $plan->rules['slot_credit'] ?? 0 );
			$pct     = $limit > 0 ? min( 100, round( ( ( $limit - $balance ) / $limit ) * 100 ) ) : 0;
		?>
			<div class="cq-card cq-cp cq-mb16">
				<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
					<strong><?php echo esc_html( $plan->name ); ?></strong>
					<span class="cq-pill cq-pg"><?php esc_html_e( 'Active', 'credoq-membership' ); ?></span>
				</div>
				<div style="font-size:12px;color:var(--cqfe-text-muted);margin-bottom:8px;">
					<?php printf( esc_html__( 'Expires on %s', 'credoq-membership' ), esc_html( $m->expiry_date ) ); ?>
				</div>
				<?php if ( $limit > 0 ) : ?>
					<div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:5px;">
						<span><?php esc_html_e( 'Slot Usage', 'credoq-membership' ); ?></span>
						<strong><?php echo ( $limit - $balance ); ?> / <?php echo $limit; ?></strong>
					</div>
					<div style="height:6px;background:var(--cqfe-bg);border-radius:3px;overflow:hidden;">
						<div style="width:<?php echo $pct; ?>%;height:100%;background:var(--cqfe-brand);border-radius:3px;"></div>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; endif; ?>
		<?php
		return ob_get_clean();
	}

	public static function render_card() : string {
		$user = wp_get_current_user();
		// Simplified 3D card layout from legacy, adapted for Engine CSS.
		ob_start();
		?>
		<div class="cq-sec-lbl"><?php esc_html_e( 'My Member ID', 'credoq-membership' ); ?></div>
		<div class="cq-card cq-cp" style="background:linear-gradient(135deg, #4f46e5, #7c3aed);color:#fff;border:none;">
			<div style="font-size:10px;text-transform:uppercase;letter-spacing:2px;opacity:.8;margin-bottom:20px;">Credoq Membership</div>
			<div style="display:flex;align-items:center;gap:15px;margin-bottom:30px;">
				<div style="width:60px;height:60px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;">
					<?php echo esc_html( strtoupper( substr( $user->display_name, 0, 1 ) ) ); ?>
				</div>
				<div>
					<div style="font-size:18px;font-weight:700;"><?php echo esc_html( $user->display_name ); ?></div>
					<div style="font-size:12px;opacity:.8;"><?php echo esc_html( $user->user_email ); ?></div>
				</div>
			</div>
			<div style="display:flex;justify-content:space-between;align-items:flex-end;">
				<div>
					<div style="font-size:9px;text-transform:uppercase;opacity:.6;margin-bottom:2px;">Member ID</div>
					<div style="font-family:monospace;font-size:14px;letter-spacing:1px;">CQ-<?php echo str_pad( (string)$user->ID, 5, '0', STR_PAD_LEFT ); ?></div>
				</div>
				<div style="text-align:right;">
					<div style="font-size:9px;text-transform:uppercase;opacity:.6;margin-bottom:2px;">Status</div>
					<div style="font-size:13px;font-weight:700;">ACTIVE</div>
				</div>
			</div>
		</div>
		<div style="margin-top:16px;text-align:center;font-size:12px;color:var(--cqfe-text-muted);">
			<?php esc_html_e( 'Scan this card at any Credoq terminal.', 'credoq-membership' ); ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
