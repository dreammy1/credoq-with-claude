<?php
/**
 * Admin Users Page.
 *
 * @package CredoqMembership\Admin
 */

namespace CredoqMembership\Admin;

use CredoqMembership\Membership_Service;
use CredoqMembership\Plan_Repository;

defined( 'ABSPATH' ) || exit;

class Users_Page {

	public static function init() : void {
		add_action( 'credoq_admin_menu', [ __CLASS__, 'register_submenu' ] );
	}

	public static function register_submenu( string $parent_slug ) : void {
		add_submenu_page(
			$parent_slug,
			__( 'Members', 'credoq-membership' ),
			__( 'Members', 'credoq-membership' ),
			'manage_options',
			'credoq-membership-users',
			[ __CLASS__, 'render' ]
		);
	}

	public static function render() : void {
		$service = new Membership_Service();
		$plan_repo = new Plan_Repository();

		// Handle grant plan.
		if ( isset( $_POST['credoq_grant_plan'] ) ) {
			check_admin_referer( 'credoq_grant_plan' );
			$user_id = absint( $_POST['user_id'] );
			$plan_id = absint( $_POST['plan_id'] );
			if ( $user_id && $plan_id ) {
				$service->grant_membership( $user_id, $plan_id );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Plan granted.', 'credoq-membership' ) . '</p></div>';
			}
		}

		// Handle adjustment.
		if ( isset( $_POST['credoq_adjust_credit'] ) ) {
			check_admin_referer( 'credoq_adjust_credit' );
			$user_id = absint( $_POST['user_id'] );
			$amount  = (int) $_POST['amount'];
			$plan_id = absint( $_POST['plan_id'] );
			$note    = sanitize_text_field( $_POST['note'] );
			if ( $user_id && $amount !== 0 ) {
				$service->add_ledger_entry( $user_id, $amount, 'adjustment', $plan_id, 0, $note );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Credit adjusted.', 'credoq-membership' ) . '</p></div>';
			}
		}

		$plans = $plan_repo->all();
		
		global $wpdb;
		$user_memberships = $wpdb->get_results( "
			SELECT m.*, u.user_email, u.display_name, p.name as plan_name
			FROM {$wpdb->prefix}credoq_user_memberships m
			LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
			LEFT JOIN {$wpdb->prefix}credoq_membership_plans p ON p.id = m.plan_id
			ORDER BY m.expiry_date DESC
			LIMIT 100
		" );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Membership Users', 'credoq-membership' ); ?></h1>

			<div class="card" style="max-width: 600px; margin-top: 20px;">
				<h2><?php esc_html_e( 'Grant Plan', 'credoq-membership' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'credoq_grant_plan' ); ?>
					<p>
						<label><?php esc_html_e( 'User ID', 'credoq-membership' ); ?></label><br>
						<input type="number" name="user_id" required>
					</p>
					<p>
						<label><?php esc_html_e( 'Plan', 'credoq-membership' ); ?></label><br>
						<select name="plan_id" required>
							<option value=""><?php esc_html_e( '— Select Plan —', 'credoq-membership' ); ?></option>
							<?php foreach ( $plans as $p ) : ?>
								<option value="<?php echo $p->id; ?>"><?php echo esc_html( $p->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<?php submit_button( __( 'Grant Plan', 'credoq-membership' ), 'secondary', 'credoq_grant_plan' ); ?>
				</form>
			</div>

			<table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'credoq-membership' ); ?></th>
						<th><?php esc_html_e( 'Plan', 'credoq-membership' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'credoq-membership' ); ?></th>
						<th><?php esc_html_e( 'Current Balance', 'credoq-membership' ); ?></th>
						<th><?php esc_html_e( 'Adjust', 'credoq-membership' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $user_memberships ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No members found.', 'credoq-membership' ); ?></td></tr>
					<?php else : foreach ( $user_memberships as $m ) : 
						$balance = $service->get_balance( (int) $m->user_id, (int) $m->plan_id );
					?>
						<tr>
							<td>
								<strong><?php echo esc_html( $m->display_name ?: $m->user_email ); ?></strong><br>
								<small><?php echo esc_html( $m->user_email ); ?></small>
							</td>
							<td><?php echo esc_html( $m->plan_name ); ?></td>
							<td><?php echo esc_html( $m->expiry_date ); ?></td>
							<td><strong><?php echo (int) $balance; ?></strong> <?php esc_html_e( 'slots', 'credoq-membership' ); ?></td>
							<td>
								<form method="post" style="display: flex; gap: 5px;">
									<?php wp_nonce_field( 'credoq_adjust_credit' ); ?>
									<input type="hidden" name="user_id" value="<?php echo (int) $m->user_id; ?>">
									<input type="hidden" name="plan_id" value="<?php echo (int) $m->plan_id; ?>">
									<input type="number" name="amount" placeholder="+/-" style="width: 60px;" required>
									<input type="text" name="note" placeholder="Note" style="width: 100px;">
									<input type="submit" name="credoq_adjust_credit" value="Go" class="button button-small">
								</form>
							</td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
