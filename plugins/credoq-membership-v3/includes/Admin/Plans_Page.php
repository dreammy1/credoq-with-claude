<?php
/**
 * Admin Plans Page — full plan management with restriction settings.
 *
 * AUDIT-FIX (Bug 1): submit_button() generates name="submit" — we now use
 * <button name="credoq_save_plan" value="1"> so the save check is reliable.
 * After save the page redirects (PRG pattern) so browser back-button is safe.
 *
 * @package CredoqMembership\Admin
 */

namespace CredoqMembership\Admin;

use CredoqMembership\Plan_Repository;

defined( 'ABSPATH' ) || exit;

class Plans_Page {

    public static function init() : void {
        add_action( 'credoq_admin_menu', [ __CLASS__, 'register_submenu' ] );
    }

    public static function register_submenu( string $parent_slug ) : void {
        add_submenu_page(
            $parent_slug,
            __( 'Membership Plans', 'credoq-membership' ),
            __( 'Membership Plans', 'credoq-membership' ),
            'manage_options',
            'credoq-membership-plans',
            [ __CLASS__, 'render' ]
        );
    }

    public static function render() : void {
        $repo   = new Plan_Repository();
        $action = sanitize_key( $_GET['action'] ?? 'list' );
        $id     = absint( $_GET['id'] ?? 0 );

        // Delete
        if ( 'delete' === $action && $id && check_admin_referer( 'credoq_delete_plan_' . $id ) ) {
            $repo->delete( $id );
            wp_safe_redirect( admin_url( 'admin.php?page=credoq-membership-plans&deleted=1' ) );
            exit;
        }

        // Save (PRG: redirect after POST so refresh doesn't re-submit)
        if ( isset( $_POST['credoq_save_plan'] ) ) {
            check_admin_referer( 'credoq_save_plan' );
            $current_id = absint( $_POST['plan_id'] ?? 0 );

            $rules = [
                'slot_credit'           => absint( $_POST['slot_credit']  ?? 0 ),
                'allowed_form_ids'      => sanitize_text_field( $_POST['allowed_form_ids'] ?? '' ),
                'purchase_limit_expiry' => ! empty( $_POST['purchase_limit_expiry'] ) ? 1 : 0,
                'progress_bar_color'    => sanitize_hex_color( $_POST['progress_bar_color'] ?? '#4f46e5' ) ?: '#4f46e5',
                'qr_scan_enabled'       => ! empty( $_POST['qr_scan_enabled'] ) ? 1 : 0,
                'unlock_url'            => esc_url_raw( wp_unslash( $_POST['unlock_url'] ?? '' ) ),
                'renewal_url'           => esc_url_raw( wp_unslash( $_POST['renewal_url'] ?? '' ) ),
                'restricted_pages'      => array_map( 'absint', (array) ( $_POST['restricted_pages'] ?? [] ) ),
                'restricted_products'   => array_map( 'absint', (array) ( $_POST['restricted_products'] ?? [] ) ),
                'restricted_urls'       => sanitize_textarea_field( wp_unslash( $_POST['restricted_urls'] ?? '' ) ),
                'hide_css_selectors'    => sanitize_text_field( wp_unslash( $_POST['hide_css_selectors'] ?? '' ) ),
                'restriction_html'      => wp_kses_post( wp_unslash( $_POST['restriction_html'] ?? '' ) ),
            ];

            $saved_id = $repo->save( [
                'id'            => $current_id,
                'name'          => sanitize_text_field( $_POST['name'] ?? '' ),
                'product_id'    => absint( $_POST['product_id'] ?? 0 ),
                'duration_days' => absint( $_POST['duration_days'] ?? 30 ),
                'rules'         => $rules,
            ] );

            wp_safe_redirect( admin_url( 'admin.php?page=credoq-membership-plans&action=edit&id=' . ( $current_id ?: $saved_id ) . '&saved=1' ) );
            exit;
        }

        if ( in_array( $action, [ 'edit', 'new' ], true ) ) {
            self::render_edit( $id, $repo );
        } else {
            self::render_list( $repo );
        }
    }

    /* ── LIST ──────────────────────────────────────────────────── */

    private static function render_list( Plan_Repository $repo ) : void {
        $plans = $repo->all();
        ?>
        <div class="wrap credoq-admin-wrap">
        <div class="credoq-page-header"><div class="credoq-page-header-inner">
            <h1 class="credoq-page-title">Membership Plans</h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-membership-plans&action=new' ) ); ?>" class="button button-primary">+ Add Plan</a>
        </div></div>

        <?php if ( ! empty( $_GET['saved'] ) )   : ?><div class="notice notice-success is-dismissible"><p>Plan saved.</p></div><?php endif; ?>
        <?php if ( ! empty( $_GET['deleted'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Plan deleted.</p></div><?php endif; ?>

        <?php if ( empty( $plans ) ) : ?>
            <div class="credoq-card"><p style="color:#94a3b8;margin:0;">No plans yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-membership-plans&action=new' ) ); ?>">Create your first plan →</a></p></div>
        <?php else : ?>
        <div class="credoq-card" style="padding:0;overflow:hidden;">
            <table class="widefat" style="border:none;">
                <thead><tr style="background:#f8fafc;">
                    <th style="padding:10px 16px;">Name</th>
                    <th>WC Product ID</th>
                    <th>Duration</th>
                    <th>Slot Credit</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $plans as $plan ) :
                    $r = is_array( $plan->rules ) ? $plan->rules : ( json_decode( $plan->rules ?: '{}', true ) ?: [] );
                    $credit = (int)( $r['slot_credit'] ?? 0 );
                ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:10px 16px;font-weight:600;">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-membership-plans&action=edit&id=' . $plan->id ) ); ?>"><?php echo esc_html( $plan->name ); ?></a>
                    </td>
                    <td><?php echo (int)$plan->product_id ?: '—'; ?></td>
                    <td><?php echo (int)$plan->duration_days; ?> days</td>
                    <td><?php echo $credit > 0 ? $credit . ' credits' : '—'; ?></td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-membership-plans&action=edit&id=' . $plan->id ) ); ?>" class="button button-small">Edit</a>
                        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => 'credoq-membership-plans', 'action' => 'delete', 'id' => $plan->id ], admin_url( 'admin.php' ) ), 'credoq_delete_plan_' . $plan->id ) ); ?>"
                           class="button button-small" onclick="return confirm('Delete this plan?');">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        </div>
        <?php
    }

    /* ── EDIT / NEW ────────────────────────────────────────────── */

    private static function render_edit( int $id, Plan_Repository $repo ) : void {
        $plan = $id ? $repo->find( $id ) : null;
        $rules = [];
        if ( $plan ) {
            $rules = is_array( $plan->rules ) ? $plan->rules : ( json_decode( $plan->rules ?: '{}', true ) ?: [] );
        }

        $all_pages    = get_pages( [ 'post_status' => 'publish', 'number' => 200 ] );
        $all_products = function_exists( 'wc_get_products' ) ? wc_get_products( [ 'status' => 'publish', 'limit' => 200 ] ) : [];
        ?>
        <div class="wrap credoq-admin-wrap">
        <div class="credoq-page-header"><div class="credoq-page-header-inner">
            <h1 class="credoq-page-title">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=credoq-membership-plans' ) ); ?>" style="color:#94a3b8;text-decoration:none;margin-right:6px;">&larr;</a>
                <?php echo $id ? 'Edit Plan: ' . esc_html( $plan->name ?? '' ) : 'New Plan'; ?>
            </h1>
        </div></div>

        <?php if ( ! empty( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Plan saved successfully.</p></div><?php endif; ?>

        <form method="post">
        <?php wp_nonce_field( 'credoq_save_plan' ); ?>
        <input type="hidden" name="plan_id" value="<?php echo (int)$id; ?>">

        <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">
        <div>

        <!-- Basic Info -->
        <div class="credoq-card">
            <h2 class="credoq-section-title">Basic Info</h2>
            <table class="form-table">
                <tr>
                    <th>Plan Name <span style="color:#dc2626;">*</span></th>
                    <td><input name="name" type="text" value="<?php echo esc_attr( $plan->name ?? '' ); ?>" class="regular-text" required placeholder="e.g. Gold Membership">
                    <p class="description">Displayed to admins and on the frontend membership status card.</p></td>
                </tr>
                <tr>
                    <th>WooCommerce Product ID <span style="color:#dc2626;">*</span></th>
                    <td><input name="product_id" type="number" value="<?php echo (int)( $plan->product_id ?? 0 ); ?>" class="small-text" placeholder="e.g. 123">
                    <p class="description">Membership is granted when this product's order completes.</p></td>
                </tr>
                <tr>
                    <th>Duration (days) <span style="color:#dc2626;">*</span></th>
                    <td><input name="duration_days" type="number" value="<?php echo (int)( $plan->duration_days ?? 30 ); ?>" class="small-text" min="1" placeholder="e.g. 90">
                    <p class="description">Active days after purchase date.</p></td>
                </tr>
            </table>
        </div>

        <!-- Slot Credits -->
        <div class="credoq-card">
            <h2 class="credoq-section-title">Slot Credits</h2>
            <table class="form-table">
                <tr>
                    <th>Slot Credit</th>
                    <td><input name="slot_credit" type="number" value="<?php echo (int)( $rules['slot_credit'] ?? 0 ); ?>" class="small-text" min="0">
                    <p class="description">Extra booking slots this plan grants. 0 = no credit-based booking.</p></td>
                </tr>
                <tr>
                    <th>Allowed Form IDs</th>
                    <td><input name="allowed_form_ids" type="text" value="<?php echo esc_attr( $rules['allowed_form_ids'] ?? '' ); ?>" class="regular-text" placeholder="5,12,15">
                    <p class="description">Comma-separated form IDs credits apply to. Empty = all forms.</p></td>
                </tr>
                <tr>
                    <th>Progress Bar Color</th>
                    <td><input name="progress_bar_color" type="color" value="<?php echo esc_attr( $rules['progress_bar_color'] ?? '#4f46e5' ); ?>">
                    <p class="description">Used in the membership progress bar on the frontend.</p></td>
                </tr>
                <tr>
                    <th>Purchase limited until expiry</th>
                    <td><label><input name="purchase_limit_expiry" type="checkbox" value="1" <?php checked( ! empty( $rules['purchase_limit_expiry'] ) ); ?>>
                    Prevents re-purchase while an active membership exists.</label></td>
                </tr>
            </table>
        </div>

        <!-- Unlock / Renewal URLs -->
        <div class="credoq-card">
            <h2 class="credoq-section-title">🔒 Unlock / Purchase URL</h2>
            <input name="unlock_url" type="url" value="<?php echo esc_attr( $rules['unlock_url'] ?? '' ); ?>" class="large-text" placeholder="https://yoursite.com/shop/">
            <p class="description">Where to redirect users who click the unlock button. Leave empty to show a static locked badge.</p>
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-top:8px;font-size:12px;">
                💡 <strong>Typical URLs:</strong> <code>/product/gold-membership/</code> &nbsp;|&nbsp; <code>/shop/</code> &nbsp;|&nbsp; <code>/?add-to-cart=35</code>
            </div>

            <h3 style="margin:18px 0 6px;font-size:14px;">Renewal URL</h3>
            <input name="renewal_url" type="url" value="<?php echo esc_attr( $rules['renewal_url'] ?? '' ); ?>" class="large-text" placeholder="https://yoursite.com/shop/">
            <p class="description">Shown when user has 0 slots remaining or plan is expired. Falls back to Unlock URL if empty.</p>
        </div>

        <!-- Trainer / QR -->
        <div class="credoq-card">
            <h2 class="credoq-section-title">Trainer Access</h2>
            <label><input name="qr_scan_enabled" type="checkbox" value="1" <?php checked( ! empty( $rules['qr_scan_enabled'] ) ); ?>>
            <strong>Members can scan QR &amp; mark attendance</strong></label>
            <p class="description">Users with this plan can mark check-in as valid or invalid.</p>
        </div>

        <!-- Content Restriction -->
        <div class="credoq-card">
            <h2 class="credoq-section-title">Content Restriction</h2>
            <p class="description" style="margin-bottom:16px;">Pages, products, and URLs only this plan's members can access.</p>

            <?php if ( ! empty( $all_pages ) ) : ?>
            <h4 style="margin:0 0 8px;">Restricted Pages</h4>
            <div style="display:flex;flex-wrap:wrap;gap:4px 18px;margin-bottom:16px;">
                <?php foreach ( $all_pages as $pg ) : ?>
                <label style="font-size:12px;display:flex;align-items:center;gap:4px;">
                    <input type="checkbox" name="restricted_pages[]" value="<?php echo (int)$pg->ID; ?>"
                        <?php checked( in_array( (int)$pg->ID, array_map( 'intval', (array)( $rules['restricted_pages'] ?? [] ) ), true ) ); ?>>
                    <?php echo esc_html( $pg->post_title ); ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $all_products ) ) : ?>
            <h4 style="margin:0 0 8px;">Restricted Products</h4>
            <div style="display:flex;flex-wrap:wrap;gap:4px 18px;margin-bottom:16px;">
                <?php foreach ( $all_products as $prod ) : ?>
                <label style="font-size:12px;display:flex;align-items:center;gap:4px;">
                    <input type="checkbox" name="restricted_products[]" value="<?php echo (int)$prod->get_id(); ?>"
                        <?php checked( in_array( (int)$prod->get_id(), array_map( 'intval', (array)( $rules['restricted_products'] ?? [] ) ), true ) ); ?>>
                    <?php echo esc_html( $prod->get_name() ); ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <h4 style="margin:0 0 6px;">Custom URLs</h4>
            <textarea name="restricted_urls" rows="4" class="large-text" placeholder="/members-only/&#10;/premium-content/"><?php echo esc_textarea( $rules['restricted_urls'] ?? '' ); ?></textarea>
            <p class="description">One per line. If current URL contains the string, non-members are blocked.</p>

            <h4 style="margin:14px 0 6px;">CSS Classes / IDs to Hide from Non-Members <span style="font-weight:400;">(comma-separated)</span></h4>
            <input name="hide_css_selectors" type="text" value="<?php echo esc_attr( $rules['hide_css_selectors'] ?? '' ); ?>" class="large-text" placeholder=".premium-section, #vip-area">
            <p class="description">Non-members see these elements hidden with a popup. Works with Elementor, Gutenberg, and any builder.</p>

            <h4 style="margin:14px 0 6px;">Restriction Message HTML</h4>
            <textarea name="restriction_html" rows="5" class="large-text" placeholder="<div>Please upgrade to access this content.</div>"><?php echo esc_textarea( $rules['restriction_html'] ?? '' ); ?></textarea>
            <p class="description">Custom HTML popup shown when access is denied.</p>
        </div>

        </div><!-- /left col -->
        <div>
            <div class="credoq-card" style="position:sticky;top:32px;">
                <h2 class="credoq-section-title">Publish</h2>
                <button type="submit" name="credoq_save_plan" value="1" class="button button-primary button-large" style="width:100%;">
                    <?php echo $id ? '💾 Update Plan' : '➕ Create Plan'; ?>
                </button>
                <?php if ( $id ) : ?>
                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => 'credoq-membership-plans', 'action' => 'delete', 'id' => $id ], admin_url( 'admin.php' ) ), 'credoq_delete_plan_' . $id ) ); ?>"
                   class="button" style="width:100%;margin-top:8px;text-align:center;color:#dc2626;border-color:#fecaca;"
                   onclick="return confirm('Delete this plan permanently?');">🗑 Delete Plan</a>
                <?php endif; ?>
            </div>
        </div>
        </div><!-- /grid -->
        </form>
        </div>
        <?php
    }
}
