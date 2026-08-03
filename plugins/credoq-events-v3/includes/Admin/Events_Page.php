<?php
namespace CredoqEvents\Admin;
defined( 'ABSPATH' ) || exit;
use CredoqEvents\Event_Repository;
class Events_Page {

    public static function render() : void {
        if ( ! current_user_can('manage_options') ) wp_die('Insufficient permissions.');

        if ( isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce']??'','credoq_del_event') ) {
            Event_Repository::delete(absint($_GET['delete']));
            echo '<div class="notice notice-success is-dismissible"><p>Event deleted.</p></div>';
        }

        if ( isset($_POST['submit']) && check_admin_referer('credoq_save_event') ) {
            self::handle_save();
        }

        $editing = isset($_GET['edit']);
        $ev = null;
        if ($editing) {
            $id = absint($_GET['edit']);
            $ev = $id ? Event_Repository::find($id) : null;
            if (!$ev) $ev = (object)['id'=>0,'title'=>'','description'=>'','start_datetime'=>'',
                'end_datetime'=>'','location'=>'','capacity'=>0,'price'=>'0.00','wc_product_id'=>0,
                'staff_id'=>0,'accent_color'=>'#4f46e5','image_url'=>'','zoom_link'=>'',
                'google_meet_link'=>'','credit_deduct_enabled'=>0,'credit_deduct_amount'=>1,'status'=>'published'];
        }
        wp_enqueue_style('wp-color-picker'); wp_enqueue_script('wp-color-picker');
        ?>
        <div class="wrap credoq-admin-wrap">
        <div class="credoq-page-header"><div class="credoq-page-header-inner">
            <h1 class="credoq-page-title">
                <span class="dashicons dashicons-tickets-alt" style="font-size:28px;margin-right:8px;color:#4f46e5;"></span>Events
            </h1>
            <?php if (!$editing): ?><a href="<?php echo esc_url(add_query_arg('edit','0',admin_url('admin.php?page=credoq-events'))); ?>" class="button button-primary">+ Add Event</a><?php endif; ?>
        </div></div>
        <?php $editing ? self::render_form($ev) : self::render_list(); echo '</div>'; ?>
        <?php
    }

    private static function handle_save() : void {
        $data = [
            'id'                   => absint($_POST['event_id']),
            'title'                => sanitize_text_field($_POST['title']??''),
            'description'          => wp_kses_post($_POST['description']??''),
            'start_datetime'       => sanitize_text_field($_POST['start_datetime']??''),
            'end_datetime'         => sanitize_text_field($_POST['end_datetime']??''),
            'location'             => sanitize_text_field($_POST['location']??''),
            'capacity'             => absint($_POST['capacity']??0),
            'price'                => floatval($_POST['price']??0),
            'wc_product_id'        => absint($_POST['wc_product_id']??0),
            'staff_id'             => absint($_POST['staff_id']??0),
            'accent_color'         => sanitize_hex_color($_POST['accent_color']??'#4f46e5'),
            'image_url'            => esc_url_raw($_POST['image_url']??''),
            'zoom_link'            => esc_url_raw($_POST['zoom_link']??''),
            'google_meet_link'     => esc_url_raw($_POST['google_meet_link']??''),
            'credit_deduct_enabled'=> isset($_POST['credit_deduct_enabled'])?1:0,
            'credit_deduct_amount' => absint($_POST['credit_deduct_amount']??1),
            'status'               => in_array($_POST['status']??'',['published','draft','cancelled'])?sanitize_key($_POST['status']):'published',
        ];
        Event_Repository::save($data);
        echo '<div class="notice notice-success is-dismissible"><p>Event saved.</p></div>';
    }

    private static function render_list() : void {
        $events = Event_Repository::all(['per_page'=>50]);
        ?>
        <div class="credoq-card">
        <table class="wp-list-table widefat fixed striped credoq-table">
            <thead><tr><th>#</th><th>Event</th><th>Date</th><th>Capacity</th><th>Booked</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($events)): ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">No events yet.</td></tr>
            <?php else: foreach ($events as $ev):
                $booked = Event_Repository::booked_count(intval($ev->id));
                $del = wp_nonce_url(add_query_arg(['delete'=>$ev->id],admin_url('admin.php?page=credoq-events')),'credoq_del_event');
                $start = date_i18n(get_option('date_format').' H:i',strtotime($ev->start_datetime));
                $cap_display = $ev->capacity > 0 ? "{$booked}/{$ev->capacity}" : "{$booked}/∞";
                $status_cls = $ev->status==='published'?'green':($ev->status==='cancelled'?'red':'gray');
            ?>
                <tr>
                    <td><code>#<?php echo intval($ev->id); ?></code></td>
                    <td>
                        <strong><?php echo esc_html($ev->title); ?></strong>
                        <?php if($ev->location): ?><br><small style="color:#64748b;">📍 <?php echo esc_html($ev->location); ?></small><?php endif; ?>
                    </td>
                    <td><small><?php echo esc_html($start); ?></small></td>
                    <td><?php echo esc_html($cap_display); ?></td>
                    <td><?php echo intval($booked); ?></td>
                    <td><?php echo $ev->price>0?'$'.number_format($ev->price,2):'Free'; ?></td>
                    <td><span class="credoq-badge credoq-badge-<?php echo esc_attr($status_cls); ?>"><?php echo esc_html(ucfirst($ev->status)); ?></span></td>
                    <td>
                        <a href="<?php echo esc_url(add_query_arg('edit',$ev->id,admin_url('admin.php?page=credoq-events'))); ?>" class="button button-small">Edit</a>
                        <a href="<?php echo esc_url(add_query_arg(['page'=>'credoq-event-bookings','event_id'=>$ev->id],admin_url('admin.php'))); ?>" class="button button-small">Attendees</a>
                        <a href="<?php echo esc_url($del); ?>" class="button button-small button-link-delete" onclick="return confirm('Delete this event?');">Delete</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    private static function render_form(object $ev): void {
        ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=credoq-events')); ?>" class="button" style="margin-bottom:16px;">&larr; Back</a>
        <form method="post">
        <?php wp_nonce_field('credoq_save_event'); ?>
        <input type="hidden" name="event_id" value="<?php echo intval($ev->id); ?>">
        <div class="credoq-settings-grid">

        <div class="credoq-card">
            <h2 class="credoq-section-title">Event Details</h2>
            <table class="form-table">
                <tr><th>Title</th><td><input type="text" name="title" value="<?php echo esc_attr($ev->title); ?>" class="regular-text" required></td></tr>
                <tr><th>Description</th><td><?php wp_editor($ev->description??'','ev_desc',['textarea_name'=>'description','media_buttons'=>false,'textarea_rows'=>5]); ?></td></tr>
                <tr><th>Start Date & Time</th><td><input type="datetime-local" name="start_datetime" value="<?php echo esc_attr(str_replace(' ','T',$ev->start_datetime??'')); ?>" class="regular-text" required></td></tr>
                <tr><th>End Date & Time</th><td><input type="datetime-local" name="end_datetime" value="<?php echo esc_attr(str_replace(' ','T',$ev->end_datetime??'')); ?>" class="regular-text"></td></tr>
                <tr><th>Location</th><td><input type="text" name="location" value="<?php echo esc_attr($ev->location); ?>" class="regular-text" placeholder="Venue name or address"></td></tr>
                <tr><th>Zoom Link</th><td><input type="url" name="zoom_link" value="<?php echo esc_attr($ev->zoom_link??''); ?>" class="regular-text"></td></tr>
                <tr><th>Google Meet Link</th><td><input type="url" name="google_meet_link" value="<?php echo esc_attr($ev->google_meet_link??''); ?>" class="regular-text"></td></tr>
                <tr><th>Image URL</th><td><input type="url" name="image_url" value="<?php echo esc_attr($ev->image_url??''); ?>" class="regular-text"></td></tr>
                <tr><th>Accent Color</th><td><input type="text" name="accent_color" value="<?php echo esc_attr($ev->accent_color??'#4f46e5'); ?>" class="credoq-color-picker"></td></tr>
                <tr><th>Status</th><td>
                    <select name="status">
                        <option value="published" <?php selected($ev->status,'published'); ?>>Published</option>
                        <option value="draft"     <?php selected($ev->status,'draft');     ?>>Draft</option>
                        <option value="cancelled" <?php selected($ev->status,'cancelled'); ?>>Cancelled</option>
                    </select>
                </td></tr>
            </table>
        </div>

        <div class="credoq-card">
            <h2 class="credoq-section-title">Capacity & Pricing</h2>
            <table class="form-table">
                <tr><th>Capacity</th><td><input type="number" name="capacity" value="<?php echo intval($ev->capacity); ?>" min="0" class="small-text"><p class="description">0 = unlimited</p></td></tr>
                <tr><th>Price</th><td><input type="number" step="0.01" name="price" value="<?php echo esc_attr($ev->price); ?>" class="small-text"></td></tr>
                <tr><th>WC Product ID</th><td><input type="number" name="wc_product_id" value="<?php echo intval($ev->wc_product_id); ?>" class="medium-text" placeholder="0 = no WC"><p class="description">0 = free / manual</p></td></tr>
                <tr><th>Credit Deduction</th><td><label><input type="checkbox" name="credit_deduct_enabled" value="1" <?php checked($ev->credit_deduct_enabled,1); ?>> Enable membership credit deduction</label></td></tr>
                <tr><th>Credits per Ticket</th><td><input type="number" name="credit_deduct_amount" value="<?php echo intval($ev->credit_deduct_amount); ?>" min="1" class="small-text"></td></tr>
            </table>
        </div>

        </div>

        <?php self::render_seat_plan_card( $ev ); ?>

        </div>
        <p class="submit"><input type="submit" name="submit" class="button button-primary button-large" value="<?php echo $ev->id?'Update Event':'Create Event'; ?>"></p>
        </form>
        <script>jQuery(function($){ $('.credoq-color-picker').wpColorPicker(); });</script>
        <?php
    }

    /**
     * AUDIT-FEATURE (Events + Seats, point 1): the "Connect to a service →
     * Connect to Credoq Events" flow lives entirely in the Seat Plan
     * Builder (Credoq Visual Seats Pro admin), so a connection made there
     * was previously invisible from the Events side — an admin editing an
     * event had no way to tell whether it had a seat plan attached at all.
     * Read-only by design: the connection itself is still managed from the
     * Seat Plan Builder, this just surfaces it here.
     */
    private static function render_seat_plan_card( object $ev ) : void {
        if ( ! $ev->id || ! class_exists( '\CredoqSeats\Repositories\Plan_Repository' ) ) return;

        $plans = \CredoqSeats\Repositories\Plan_Repository::find_for_connection( 'event', (int) $ev->id );
        ?>
        <div class="credoq-card">
            <h2 class="credoq-section-title">Seat Map</h2>
            <?php if ( empty( $plans ) ) : ?>
                <p class="description">No seat plan is connected to this event. Connect one from Credoq Visual Seats Pro → Seat Plans → (a plan) → Connect to a service → Credoq Events.</p>
            <?php else : ?>
                <table class="form-table">
                    <?php foreach ( $plans as $plan ) : ?>
                    <tr>
                        <th><?php echo esc_html( $plan->name ); ?></th>
                        <td>
                            <?php echo (int) $plan->total_seats; ?> seat(s)
                            &middot; <?php echo esc_html( ucfirst( $plan->status ) ); ?>
                            <?php if ( 'published' !== $plan->status ) : ?>
                                <span style="color:#b32d2e;">(not published — seat map won't render on the registration form until this plan is published)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php if ( count( $plans ) > 1 ) : ?>
                    <p class="description" style="color:#b32d2e;">This event has more than one connected seat plan. A seat_map form field can't tell which one applies — connect only one plan per event, or set the field's plan explicitly.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
