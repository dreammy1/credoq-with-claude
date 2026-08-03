<?php
namespace CredoqAppointments\Admin;
defined( 'ABSPATH' ) || exit;

use CredoqAppointments\Appointment_Repository;
use CredoqAppointments\Staff_Repository;

class Appointments_Page {

    public static function render() : void {
        if ( ! current_user_can('manage_options') ) wp_die('Insufficient permissions.');

        if ( isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'credoq_delete_apt') ) {
            Appointment_Repository::delete( absint($_GET['delete']) );
            echo '<div class="notice notice-success is-dismissible"><p>Service deleted.</p></div>';
        }

        if ( isset($_POST['submit']) && check_admin_referer('credoq_save_apt') ) {
            self::handle_save();
        }

        $editing = isset($_GET['edit']);
        $apt     = null;
        if ( $editing ) {
            $id  = absint($_GET['edit']);
            $apt = $id ? Appointment_Repository::find($id) : null;
            if ( ! $apt ) $apt = (object)[
                'id'=>0,'title'=>'','location'=>'','description'=>'',
                'duration'=>60,'slot_interval'=>60,'max_bookings'=>1,
                'base_price'=>'0.00','wc_product_id'=>0,'staff_ids'=>'[]',
                'availability'=>'{}','allow_multi_booking'=>0,'multi_price_mode'=>'per_session',
                'multi_day_rate'=>'0.00','capacity_mode'=>'per_staff','capacity_value'=>1,
                'min_schedules'=>1,'max_schedules'=>1,'credit_deduct_enabled'=>0,
                'credit_deduct_amount'=>1,'booking_settings'=>'{}','accent_color'=>'#4f46e5','image_url'=>'',
            ];
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        ?>
        <div class="wrap credoq-admin-wrap">
        <div class="credoq-page-header">
            <div class="credoq-page-header-inner">
                <h1 class="credoq-page-title">
                    <span class="dashicons dashicons-calendar-alt" style="font-size:28px;margin-right:8px;color:#4f46e5;"></span>
                    <?php esc_html_e('Services / Appointments','credoq-appointments'); ?>
                </h1>
                <?php if ( ! $editing ) : ?>
                <a href="<?php echo esc_url(add_query_arg('edit','0',admin_url('admin.php?page=credoq-appointments'))); ?>"
                   class="button button-primary">+ Add New Service</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        if ( $editing ) self::render_edit_form( $apt );
        else            self::render_list();
        echo '</div>';
    }

    private static function handle_save() : void {
        $avail = [];
        foreach ( ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $d ) {
            $avail[$d] = [
                'closed' => empty($_POST["avail_{$d}_enabled"]),
                'hours'  => [],
            ];
            if ( ! empty($_POST["avail_{$d}_enabled"]) ) {
                $starts = (array)($_POST["avail_{$d}_start"] ?? []);
                $ends   = (array)($_POST["avail_{$d}_end"]   ?? []);
                foreach ( $starts as $i => $s ) {
                    if ( ! empty($s) && ! empty($ends[$i]) ) {
                        $avail[$d]['hours'][] = [
                            'start' => sanitize_text_field($s),
                            'end'   => sanitize_text_field($ends[$i]),
                        ];
                    }
                }
            }
        }
        $staff_ids = array_map('intval', (array)($_POST['staff_ids'] ?? []));
        $data = [
            'id'                   => absint($_POST['apt_id']),
            'title'                => sanitize_text_field($_POST['title'] ?? ''),
            'location'             => sanitize_text_field($_POST['location'] ?? ''),
            'description'          => wp_kses_post($_POST['description'] ?? ''),
            'duration'             => absint($_POST['duration'] ?? 60),
            'slot_interval'        => absint($_POST['slot_interval'] ?? 60),
            'max_bookings'         => absint($_POST['max_bookings'] ?? 1),
            'base_price'           => floatval($_POST['base_price'] ?? 0),
            'wc_product_id'        => absint($_POST['wc_product_id'] ?? 0),
            'staff_ids'            => $staff_ids,
            'availability'         => $avail,
            'allow_multi_booking'  => isset($_POST['allow_multi_booking']) ? 1 : 0,
            'multi_price_mode'     => in_array($_POST['multi_price_mode']??'', ['per_session','per_day_rate']) ? sanitize_key($_POST['multi_price_mode']) : 'per_session',
            'multi_day_rate'       => floatval($_POST['multi_day_rate'] ?? 0),
            'capacity_mode'        => in_array($_POST['capacity_mode']??'', ['per_staff','shared']) ? sanitize_key($_POST['capacity_mode']) : 'per_staff',
            'capacity_value'       => absint($_POST['capacity_value'] ?? 1),
            'min_schedules'        => absint($_POST['min_schedules'] ?? 1),
            'max_schedules'        => absint($_POST['max_schedules'] ?? 1),
            'credit_deduct_enabled'=> isset($_POST['credit_deduct_enabled']) ? 1 : 0,
            'credit_deduct_amount' => absint($_POST['credit_deduct_amount'] ?? 1),
            'accent_color'         => sanitize_hex_color($_POST['accent_color'] ?? '#4f46e5'),
            'image_url'            => esc_url_raw($_POST['image_url'] ?? ''),
        ];

        // Visual Seats addon settings live inside the booking_settings JSON
        // blob so this table never needs an ALTER for addon-owned fields.
        // Only touched when the addon is active, so nothing is lost/reset
        // when it's deactivated — existing keys (special_dates, weekend_price,
        // etc.) are preserved.
        if ( class_exists( '\CredoqSeats\Repositories\Plan_Repository' ) ) {
            $apt_id_for_settings = absint( $_POST['apt_id'] ?? 0 );
            $existing_apt        = $apt_id_for_settings ? Appointment_Repository::find( $apt_id_for_settings ) : null;
            $bk_settings          = $existing_apt ? ( json_decode( $existing_apt->booking_settings ?? '{}', true ) ?: [] ) : [];
            $bk_settings['visual_seats_enabled'] = isset( $_POST['visual_seats_enabled'] ) ? 1 : 0;
            $bk_settings['seat_plan_id']          = absint( $_POST['seat_plan_id'] ?? 0 );
            $data['booking_settings'] = $bk_settings;
        }
        Appointment_Repository::save($data);
        echo '<div class="notice notice-success is-dismissible"><p>Service saved.</p></div>';
    }

    private static function render_list() : void {
        $total = Appointment_Repository::count();
        $per   = 20;
        $page  = max(1, absint($_GET['paged'] ?? 1));
        $apts  = Appointment_Repository::all($per, ($page-1)*$per);
        $edit  = admin_url('admin.php?page=credoq-appointments&edit=');
        ?>
        <div class="credoq-card">
        <table class="wp-list-table widefat fixed striped credoq-table">
            <thead><tr>
                <th style="width:40px;">#</th>
                <th>Title</th><th>Duration</th><th>Max/Slot</th>
                <th>Price</th><th>WC Product</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php if ( empty($apts) ) : ?>
                <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">No services yet. Create your first.</td></tr>
            <?php else: foreach ($apts as $a) :
                $del = wp_nonce_url(add_query_arg(['delete'=>$a->id], admin_url('admin.php?page=credoq-appointments')), 'credoq_delete_apt');
            ?>
                <tr>
                    <td><code>#<?php echo intval($a->id); ?></code></td>
                    <td>
                        <strong><?php echo esc_html($a->title); ?></strong>
                        <?php if ($a->location) : ?><br><small style="color:#64748b;"><?php echo esc_html($a->location); ?></small><?php endif; ?>
                    </td>
                    <td><?php echo intval($a->duration); ?> min</td>
                    <td><?php echo intval($a->max_bookings); ?></td>
                    <td><?php echo $a->base_price > 0 ? '$'.number_format($a->base_price,2) : '<span style="color:#94a3b8;">Free</span>'; ?></td>
                    <td><?php echo $a->wc_product_id ? '#'.intval($a->wc_product_id) : '—'; ?></td>
                    <td>
                        <a href="<?php echo esc_url($edit.$a->id); ?>" class="button button-small">Edit</a>
                        <a href="<?php echo esc_url($del); ?>" class="button button-small button-link-delete"
                           onclick="return confirm('Delete this service?');">Delete</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
        $pages = ceil($total / $per);
        if ($pages > 1) {
            echo '<div style="padding:12px;">';
            echo paginate_links(['base'=>add_query_arg('paged','%#%'),'format'=>'','total'=>$pages,'current'=>$page,'prev_text'=>'&laquo;','next_text'=>'&raquo;']);
            echo '</div>';
        }
        echo '</div>';
    }

    private static function render_edit_form( object $apt ) : void {
        $avail     = json_decode($apt->availability ?? '{}', true) ?: [];
        $staff_all = Staff_Repository::all();
        $sel_staff = json_decode($apt->staff_ids ?? '[]', true) ?: [];
        $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        $bk_settings = json_decode($apt->booking_settings ?? '{}', true) ?: [];
        ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=credoq-appointments')); ?>" class="button" style="margin-bottom:16px;">&larr; Back</a>
        <form method="post">
        <?php wp_nonce_field('credoq_save_apt'); ?>
        <input type="hidden" name="apt_id" value="<?php echo intval($apt->id); ?>">

        <div class="credoq-settings-grid">

        <!-- Basic -->
        <div class="credoq-card">
            <h2 class="credoq-section-title">Service Details</h2>
            <table class="form-table">
                <tr><th>Title</th><td><input type="text" name="title" value="<?php echo esc_attr($apt->title); ?>" class="regular-text" required></td></tr>
                <tr><th>Location</th><td><input type="text" name="location" value="<?php echo esc_attr($apt->location); ?>" class="regular-text"></td></tr>
                <tr><th>Description</th><td><?php wp_editor($apt->description??'','apt_description',['textarea_name'=>'description','media_buttons'=>false,'textarea_rows'=>4]); ?></td></tr>
                <tr><th>Accent Color</th><td><input type="text" name="accent_color" value="<?php echo esc_attr($apt->accent_color??'#4f46e5'); ?>" class="credoq-color-picker"></td></tr>
                <tr><th>Image URL</th><td><input type="url" name="image_url" value="<?php echo esc_attr($apt->image_url??''); ?>" class="regular-text"></td></tr>
            </table>
        </div>

        <!-- Timing -->
        <div class="credoq-card">
            <h2 class="credoq-section-title">Timing & Capacity</h2>
            <table class="form-table">
                <tr><th>Duration (min)</th><td><input type="number" name="duration" value="<?php echo intval($apt->duration); ?>" min="1" class="small-text"></td></tr>
                <tr><th>Slot Interval (min)</th><td><input type="number" name="slot_interval" value="<?php echo intval($apt->slot_interval); ?>" min="1" class="small-text"><p class="description">Gap between slot start times.</p></td></tr>
                <tr><th>Max Bookings per Slot</th><td><input type="number" name="max_bookings" value="<?php echo intval($apt->max_bookings); ?>" min="1" class="small-text"></td></tr>
                <tr><th>Capacity Mode</th><td>
                    <select name="capacity_mode">
                        <option value="per_staff" <?php selected($apt->capacity_mode,'per_staff'); ?>>Per Staff</option>
                        <option value="shared"    <?php selected($apt->capacity_mode,'shared'); ?>>Shared Pool</option>
                    </select>
                </td></tr>
                <tr><th>Capacity Value</th><td><input type="number" name="capacity_value" value="<?php echo intval($apt->capacity_value); ?>" min="1" class="small-text"></td></tr>
            </table>
        </div>

        <!-- Pricing -->
        <div class="credoq-card">
            <h2 class="credoq-section-title">Pricing & WooCommerce</h2>
            <table class="form-table">
                <tr><th>Base Price</th><td><input type="number" step="0.01" name="base_price" value="<?php echo esc_attr($apt->base_price); ?>" class="small-text"> <code><?php echo get_option('woocommerce_currency','USD'); ?></code></td></tr>
                <tr><th>WC Product ID</th><td><input type="number" name="wc_product_id" value="<?php echo intval($apt->wc_product_id); ?>" class="medium-text" placeholder="0 = no WC"><p class="description">0 = free / manual confirm</p></td></tr>
                <tr><th>Multi-Booking</th><td>
                    <label><input type="checkbox" name="allow_multi_booking" value="1" <?php checked($apt->allow_multi_booking,1); ?>> Allow booking multiple sessions</label>
                </td></tr>
                <tr><th>Multi Price Mode</th><td>
                    <select name="multi_price_mode">
                        <option value="per_session" <?php selected($apt->multi_price_mode,'per_session'); ?>>Per Session (base price × sessions)</option>
                        <option value="per_day_rate" <?php selected($apt->multi_price_mode,'per_day_rate'); ?>>Fixed Day Rate</option>
                    </select>
                </td></tr>
                <tr><th>Day Rate Price</th><td><input type="number" step="0.01" name="multi_day_rate" value="<?php echo esc_attr($apt->multi_day_rate); ?>" class="small-text"></td></tr>
                <tr><th>Min Sessions</th><td><input type="number" name="min_schedules" value="<?php echo intval($apt->min_schedules); ?>" min="1" class="small-text"></td></tr>
                <tr><th>Max Sessions</th><td><input type="number" name="max_schedules" value="<?php echo intval($apt->max_schedules); ?>" min="1" class="small-text"></td></tr>
            </table>
        </div>

        <!-- Credit Deduction -->
        <div class="credoq-card">
            <h2 class="credoq-section-title">Membership Credit</h2>
            <table class="form-table">
                <tr><th>Deduct Credits</th><td><label><input type="checkbox" name="credit_deduct_enabled" value="1" <?php checked($apt->credit_deduct_enabled,1); ?>> Enable credit deduction</label></td></tr>
                <tr><th>Credits per Booking</th><td><input type="number" name="credit_deduct_amount" value="<?php echo intval($apt->credit_deduct_amount); ?>" min="1" class="small-text"></td></tr>
            </table>
        </div>

        <?php if ( class_exists( '\CredoqSeats\Repositories\Plan_Repository' ) ) : ?>
        <!-- Visual Seats (provided by the Credoq Visual Seats Pro addon) -->
        <div class="credoq-card">
            <h2 class="credoq-section-title">Visual Seats</h2>
            <table class="form-table">
                <tr>
                    <th>Enable seat map</th>
                    <td>
                        <label><input type="checkbox" name="visual_seats_enabled" value="1" <?php checked( ! empty( $bk_settings['visual_seats_enabled'] ) ); ?>> Replace the plain quantity/slot capacity with a visual seat map on this service</label>
                    </td>
                </tr>
                <tr>
                    <th>Seat plan</th>
                    <td>
                        <select name="seat_plan_id">
                            <option value="0">— Select a published plan —</option>
                            <?php foreach ( \CredoqSeats\Repositories\Plan_Repository::published() as $plan ) : ?>
                                <option value="<?php echo (int) $plan->id; ?>" <?php selected( (int) ( $bk_settings['seat_plan_id'] ?? 0 ), (int) $plan->id ); ?>>
                                    <?php echo esc_html( $plan->name ); ?> (<?php echo (int) $plan->total_seats; ?> seats)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Manage plans under Credoq → Visual Seats → Seat Plans. The plan's seat count becomes this service's hard capacity ceiling.</p>
                    </td>
                </tr>
            </table>
        </div>
        <?php endif; ?>
        <div class="credoq-card">
            <h2 class="credoq-section-title">Assigned Staff</h2>
            <p class="description">Leave all unchecked to allow all staff.</p>
            <?php foreach ($staff_all as $s) : ?>
            <label style="display:block;margin-bottom:6px;">
                <input type="checkbox" name="staff_ids[]" value="<?php echo intval($s->id); ?>" <?php echo in_array(intval($s->id),$sel_staff) ? 'checked' : ''; ?>>
                <?php echo esc_html($s->display_name); ?> <?php echo $s->email ? '(' . esc_html($s->email) . ')' : ''; ?>
            </label>
            <?php endforeach; ?>
            <?php if (empty($staff_all)) : ?>
                <p style="color:#94a3b8;">No staff yet. <a href="<?php echo esc_url(admin_url('admin.php?page=credoq-staff&edit=0')); ?>">Add staff first.</a></p>
            <?php endif; ?>
        </div>

        <!-- Weekly Availability -->
        <div class="credoq-card" style="grid-column:1/-1;">
            <h2 class="credoq-section-title">Weekly Availability</h2>
            <p class="description">Set the working hours for this service. Staff overrides take precedence.</p>
            <table class="form-table">
            <?php foreach ($days as $day) :
                $day_data = $avail[$day] ?? ['closed' => true, 'hours' => []];
                $enabled  = empty($day_data['closed']);
                $hours    = $day_data['hours'] ?: [['start'=>'09:00','end'=>'17:00']];
            ?>
            <tr>
                <th style="width:120px;">
                    <label>
                        <input type="checkbox" name="avail_<?php echo $day; ?>_enabled" value="1" <?php checked($enabled); ?>>
                        <?php echo ucfirst($day); ?>
                    </label>
                </th>
                <td>
                    <?php foreach ($hours as $i => $h) : ?>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                        <input type="time" name="avail_<?php echo $day; ?>_start[]" value="<?php echo esc_attr($h['start']??'09:00'); ?>" style="padding:4px 8px;border:1.5px solid #e2e8f0;border-radius:6px;">
                        <span>—</span>
                        <input type="time" name="avail_<?php echo $day; ?>_end[]" value="<?php echo esc_attr($h['end']??'17:00'); ?>" style="padding:4px 8px;border:1.5px solid #e2e8f0;border-radius:6px;">
                    </div>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </table>
        </div>

        </div><!-- .credoq-settings-grid -->
        <p class="submit">
            <input type="submit" name="submit" class="button button-primary button-large"
                   value="<?php echo $apt->id ? 'Update Service' : 'Create Service'; ?>">
        </p>
        </form>
        <script>jQuery(function($){ $('.credoq-color-picker').wpColorPicker(); });</script>
        <?php
    }
}
