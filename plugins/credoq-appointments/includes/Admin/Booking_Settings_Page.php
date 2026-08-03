<?php
namespace CredoqAppointments\Admin;
defined( 'ABSPATH' ) || exit;

class Booking_Settings_Page {

    public static function render() : void {
        if ( ! current_user_can('manage_options') ) wp_die('Insufficient permissions.');

        if ( isset($_POST['submit']) && check_admin_referer('credoq_booking_settings') ) {
            $settings = [
                'booking_mode'              => in_array($_POST['booking_mode']??'',['auto','manual']) ? sanitize_key($_POST['booking_mode']) : 'auto',
                'currency'                  => sanitize_text_field($_POST['currency']??'USD'),
                'cancel_policy'             => wp_kses_post($_POST['cancel_policy']??''),
                'terms_url'                 => esc_url_raw($_POST['terms_url']??''),
                'show_price_breakdown'      => isset($_POST['show_price_breakdown']) ? 1 : 0,
                'show_staff_selector'       => isset($_POST['show_staff_selector']) ? 1 : 0,
                'show_notes_field'          => isset($_POST['show_notes_field']) ? 1 : 0,
                'require_login'             => isset($_POST['require_login']) ? 1 : 0,
                'enable_waiting_list'       => isset($_POST['enable_waiting_list']) ? 1 : 0,
                // Email settings
                'email_from_name'           => sanitize_text_field($_POST['email_from_name']??''),
                'email_from_address'        => sanitize_email($_POST['email_from_address']??''),
                'email_accent_color'        => sanitize_hex_color($_POST['email_accent_color']??'#4f46e5'),
                'email_logo_url'            => esc_url_raw($_POST['email_logo_url']??''),
                'email_admin_bcc'           => isset($_POST['email_admin_bcc']) ? 1 : 0,
                // Template toggles + subjects
                'email_confirm_enabled'     => isset($_POST['email_confirm_enabled']) ? 1 : 0,
                'email_confirm_subject'     => sanitize_text_field($_POST['email_confirm_subject']??''),
                'email_confirm_body'        => wp_kses_post($_POST['email_confirm_body']??''),
                'email_cancel_enabled'      => isset($_POST['email_cancel_enabled']) ? 1 : 0,
                'email_cancel_subject'      => sanitize_text_field($_POST['email_cancel_subject']??''),
                'email_cancel_body'         => wp_kses_post($_POST['email_cancel_body']??''),
                'email_reminder_enabled'    => isset($_POST['email_reminder_enabled']) ? 1 : 0,
                'email_reminder_subject'    => sanitize_text_field($_POST['email_reminder_subject']??''),
                'email_reminder_body'       => wp_kses_post($_POST['email_reminder_body']??''),
                'email_pending_enabled'     => isset($_POST['email_pending_enabled']) ? 1 : 0,
                'email_pending_subject'     => sanitize_text_field($_POST['email_pending_subject']??''),
                'email_pending_body'        => wp_kses_post($_POST['email_pending_body']??''),
            ];
            update_option('credoq_booking_settings', $settings);
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }

        $s = get_option('credoq_booking_settings', []);

        $defaults = [
            'booking_mode'           => 'auto',
            'currency'               => 'USD',
            'email_from_name'        => get_bloginfo('name'),
            'email_from_address'     => get_option('admin_email'),
            'email_accent_color'     => '#4f46e5',
            'email_confirm_subject'  => 'Booking Confirmed – {appointment} on {date}',
            'email_cancel_subject'   => 'Booking Cancelled – {appointment}',
            'email_reminder_subject' => 'Reminder: {appointment} tomorrow at {time}',
            'email_pending_subject'  => 'Booking Received – {appointment}',
        ];
        foreach ($defaults as $k => $v) {
            if (!isset($s[$k])) $s[$k] = $v;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        $tab = sanitize_key($_GET['stab'] ?? 'general');
        $tabs = ['general'=>'General','email'=>'Email Templates','widget'=>'Widget Settings'];
        ?>
        <div class="wrap credoq-admin-wrap">
        <div class="credoq-page-header">
            <div class="credoq-page-header-inner">
                <h1 class="credoq-page-title">
                    <span class="dashicons dashicons-admin-generic" style="font-size:28px;margin-right:8px;color:#4f46e5;"></span>
                    Booking Settings
                </h1>
            </div>
        </div>

        <!-- Sub-tabs -->
        <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
            <?php foreach ($tabs as $slug => $label) : ?>
            <a href="<?php echo esc_url(add_query_arg(['page'=>'credoq-booking-settings','stab'=>$slug],admin_url('admin.php'))); ?>"
               class="nav-tab <?php echo $tab===$slug?'nav-tab-active':''; ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="post">
        <?php wp_nonce_field('credoq_booking_settings'); ?>

        <?php if ($tab === 'general') : ?>
        <div class="credoq-settings-grid">
            <div class="credoq-card">
                <h2 class="credoq-section-title">Booking Behaviour</h2>
                <table class="form-table">
                    <tr><th>Confirmation Mode</th><td>
                        <select name="booking_mode">
                            <option value="auto"   <?php selected($s['booking_mode'],'auto');   ?>>Auto-confirm</option>
                            <option value="manual" <?php selected($s['booking_mode'],'manual'); ?>>Manual approval</option>
                        </select>
                    </td></tr>
                    <tr><th>Currency</th><td><input type="text" name="currency" value="<?php echo esc_attr($s['currency']); ?>" class="small-text" maxlength="3"></td></tr>
                    <tr><th>Terms & Conditions URL</th><td><input type="url" name="terms_url" value="<?php echo esc_attr($s['terms_url']??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Require Login</th><td><label><input type="checkbox" name="require_login" value="1" <?php checked($s['require_login']??0,1); ?>> Guests must be logged in to book</label></td></tr>
                    <tr><th>Enable Waiting List</th><td><label><input type="checkbox" name="enable_waiting_list" value="1" <?php checked($s['enable_waiting_list']??0,1); ?>> Show waiting list option when slot is full</label></td></tr>
                    <tr><th>Show Staff Selector</th><td><label><input type="checkbox" name="show_staff_selector" value="1" <?php checked($s['show_staff_selector']??0,1); ?>>Allow client to choose staff member</label></td></tr>
                    <tr><th>Show Notes Field</th><td><label><input type="checkbox" name="show_notes_field" value="1" <?php checked($s['show_notes_field']??0,1); ?>>Show client notes textarea</label></td></tr>
                    <tr><th>Show Price Breakdown</th><td><label><input type="checkbox" name="show_price_breakdown" value="1" <?php checked($s['show_price_breakdown']??0,1); ?>>Show itemised price in widget</label></td></tr>
                </table>
            </div>
            <div class="credoq-card">
                <h2 class="credoq-section-title">Cancellation Policy</h2>
                <?php wp_editor($s['cancel_policy']??'','cancel_policy',['textarea_name'=>'cancel_policy','media_buttons'=>false,'textarea_rows'=>6]); ?>
            </div>
        </div>

        <?php elseif ($tab === 'email') :
            $email_types = [
                'confirm'  => 'Booking Confirmed',
                'cancel'   => 'Booking Cancelled',
                'reminder' => 'Day-Before Reminder',
                'pending'  => 'Booking Received (Pending)',
            ];
        ?>
        <div class="credoq-card" style="margin-bottom:20px;">
            <h2 class="credoq-section-title">From Address</h2>
            <table class="form-table">
                <tr><th>From Name</th><td><input type="text" name="email_from_name" value="<?php echo esc_attr($s['email_from_name']); ?>" class="regular-text"></td></tr>
                <tr><th>From Email</th><td><input type="email" name="email_from_address" value="<?php echo esc_attr($s['email_from_address']); ?>" class="regular-text"></td></tr>
                <tr><th>Accent Color</th><td><input type="text" name="email_accent_color" value="<?php echo esc_attr($s['email_accent_color']); ?>" class="credoq-color-picker"></td></tr>
                <tr><th>Logo URL</th><td><input type="url" name="email_logo_url" value="<?php echo esc_attr($s['email_logo_url']??''); ?>" class="regular-text"></td></tr>
                <tr><th>BCC Admin</th><td><label><input type="checkbox" name="email_admin_bcc" value="1" <?php checked($s['email_admin_bcc']??0,1); ?>> Send copy to site admin</label></td></tr>
            </table>
            <p class="description" style="margin-top:8px;">Tokens: {name} {appointment} {date} {time} {location} {staff} {price} {site_name} {cancel_link} {booking_id}</p>
        </div>
        <?php foreach ($email_types as $et_key => $et_label) : ?>
        <div class="credoq-card" style="margin-bottom:16px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h2 class="credoq-section-title" style="margin:0;"><?php echo esc_html($et_label); ?></h2>
                <label><input type="checkbox" name="email_<?php echo $et_key; ?>_enabled" value="1" <?php checked($s["email_{$et_key}_enabled"]??0,1); ?>> Enabled</label>
            </div>
            <table class="form-table">
                <tr><th>Subject</th><td><input type="text" name="email_<?php echo $et_key; ?>_subject" value="<?php echo esc_attr($s["email_{$et_key}_subject"]??''); ?>" class="large-text"></td></tr>
                <tr><th>Body</th><td><?php wp_editor($s["email_{$et_key}_body"]??'', "email_{$et_key}_body", ['textarea_name'=>"email_{$et_key}_body",'media_buttons'=>false,'textarea_rows'=>8]); ?></td></tr>
            </table>
        </div>
        <?php endforeach; ?>

        <?php elseif ($tab === 'widget') : ?>
        <div class="credoq-card">
            <h2 class="credoq-section-title">Widget Shortcode</h2>
            <p>Place this shortcode on any page to show the booking widget:</p>
            <code style="background:#f1f5f9;padding:10px 16px;border-radius:8px;display:block;font-size:14px;margin:8px 0;">[credoq_booking_form]</code>
            <p>With a specific service pre-selected:</p>
            <code style="background:#f1f5f9;padding:10px 16px;border-radius:8px;display:block;font-size:14px;margin:8px 0;">[credoq_booking_form appointment_id="5"]</code>
            <p>User's personal schedule / calendar:</p>
            <code style="background:#f1f5f9;padding:10px 16px;border-radius:8px;display:block;font-size:14px;margin:8px 0;">[credoq_my_schedule]</code>
        </div>
        <?php endif; ?>

        <p class="submit">
            <input type="submit" name="submit" class="button button-primary button-large" value="Save Settings">
        </p>
        </form>
        </div>
        <script>jQuery(function($){ $('.credoq-color-picker').wpColorPicker(); });</script>
        <?php
    }
}
