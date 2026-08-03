<?php
namespace CredoqEvents\Admin;
defined( 'ABSPATH' ) || exit;
use CredoqEvents\Event_Booking_Repository;
use CredoqEvents\Event_Service;
use CredoqEvents\Event_Repository;

class Event_Bookings_Page {

    const PER_PAGE = 25;

    public static function render() : void {
        if ( ! current_user_can('manage_options') ) wp_die('Insufficient permissions.');

        if ( isset($_GET['cancel_booking']) && wp_verify_nonce($_GET['_wpnonce']??'','credoq_cancel_event_booking') ) {
            Event_Service::cancel(absint($_GET['cancel_booking']));
            echo '<div class="notice notice-success is-dismissible"><p>Booking cancelled.</p></div>';
        }

        $event_id = absint($_GET['event_id'] ?? 0);
        $filters  = ['event_id'=>$event_id,'status'=>sanitize_key($_GET['status']??'')];
        $paged    = max(1,absint($_GET['paged']??1));
        $offset   = ($paged-1)*self::PER_PAGE;
        $bookings = Event_Booking_Repository::paginated(self::PER_PAGE,$offset,$filters);
        $ev       = $event_id ? Event_Repository::find($event_id) : null;
        $events   = Event_Repository::all(['per_page'=>100]);
        ?>
        <div class="wrap credoq-admin-wrap">
        <div class="credoq-page-header"><div class="credoq-page-header-inner">
            <h1 class="credoq-page-title">
                <span class="dashicons dashicons-groups" style="font-size:28px;margin-right:8px;color:#4f46e5;"></span>
                Event Bookings<?php echo $ev ? ' — '.esc_html($ev->title) : ''; ?>
            </h1>
        </div></div>

        <div class="credoq-card" style="margin-bottom:16px;">
        <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="page" value="credoq-event-bookings">
            <div>
                <label style="display:block;font-size:11px;font-weight:700;color:#475569;margin-bottom:4px;">EVENT</label>
                <select name="event_id">
                    <option value="0">All Events</option>
                    <?php foreach ($events as $e): ?>
                    <option value="<?php echo intval($e->id); ?>" <?php selected($event_id,intval($e->id)); ?>><?php echo esc_html($e->title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:11px;font-weight:700;color:#475569;margin-bottom:4px;">STATUS</label>
                <select name="status">
                    <option value="">All</option>
                    <?php foreach (['confirmed','pending_payment','cancelled'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php selected($filters['status'],$s); ?>><?php echo ucwords(str_replace('_',' ',$s)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="button button-primary">Filter</button>
        </form>
        </div>

        <div class="credoq-card">
        <table class="wp-list-table widefat fixed striped credoq-table">
            <thead><tr><th>#</th><th>Attendee</th><th>Event</th><th>Qty</th><th>Status</th><th>WC Order</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($bookings)): ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8;">No bookings found.</td></tr>
            <?php else: foreach ($bookings as $b):
                $cls = $b->status==='confirmed'?'green':($b->status==='cancelled'?'red':'blue');
                $cancel_url = wp_nonce_url(add_query_arg(['cancel_booking'=>$b->id,'event_id'=>$event_id,'page'=>'credoq-event-bookings'],admin_url('admin.php')),'credoq_cancel_event_booking');
            ?>
                <tr>
                    <td><code>#<?php echo intval($b->id); ?></code></td>
                    <td><strong><?php echo esc_html($b->display_name ?: $b->guest_name ?: '—'); ?></strong><br><small style="color:#64748b;"><?php echo esc_html($b->user_email ?: $b->guest_email); ?></small></td>
                    <td><?php echo esc_html($b->event_title??'—'); ?></td>
                    <td><?php echo intval($b->quantity); ?></td>
                    <td><span class="credoq-badge credoq-badge-<?php echo esc_attr($cls); ?>"><?php echo esc_html(ucwords(str_replace('_',' ',$b->status))); ?></span></td>
                    <td><?php echo $b->wc_order_id ? '<a href="'.esc_url(get_edit_post_link($b->wc_order_id)).'" target="_blank">#'.intval($b->wc_order_id).'</a>' : '—'; ?></td>
                    <td><small><?php echo esc_html(date_i18n(get_option('date_format'),strtotime($b->created_at))); ?></small></td>
                    <td>
                        <?php if ($b->status !== 'cancelled'): ?>
                        <a href="<?php echo esc_url($cancel_url); ?>" class="button button-small button-link-delete" onclick="return confirm('Cancel this registration?');">Cancel</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        </div>
        <?php
    }
}
