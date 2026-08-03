<?php
/**
 * Bookings admin page — full-featured booking manager.
 *
 * Views dispatched via ?action=:
 *   (none)/list  — filterable table + calendar toggle + bulk actions
 *   calendar     — admin calendar with day-click popup + summary pills
 *   view         — booking detail (editable form_data, status, notes)
 *   edit         — same detail page in edit mode
 *   add          — manually create a new booking
 *
 * @package CredoqAppointments\Admin
 */

namespace CredoqAppointments\Admin;

use CredoqAppointments\Booking_Repository;
use CredoqAppointments\Booking_Service;
use CredoqAppointments\Appointment_Repository;
use CredoqAppointments\Staff_Repository;

defined( 'ABSPATH' ) || exit;

class Bookings_Page {

    const PER_PAGE = 20;
    const STATUSES = [
        'confirmed'       => ['label'=>'Confirmed',       'color'=>'#16a34a','bg'=>'#f0fdf4'],
        'pending'         => ['label'=>'Pending',         'color'=>'#d97706','bg'=>'#fffbeb'],
        'pending_payment' => ['label'=>'Pending Payment', 'color'=>'#7c3aed','bg'=>'#f5f3ff'],
        'cancelled'       => ['label'=>'Cancelled',       'color'=>'#dc2626','bg'=>'#fff1f2'],
        'completed'       => ['label'=>'Completed',       'color'=>'#475569','bg'=>'#f8fafc'],
        'rejected'        => ['label'=>'Rejected',        'color'=>'#9f1239','bg'=>'#fff1f2'],
    ];

    public static function render() : void {
        if ( ! current_user_can('manage_options') ) wp_die('Insufficient permissions.');

        self::handle_mutations();

        $action = sanitize_key( $_GET['action'] ?? 'list' );
        if ( $action === 'calendar' ) { self::render_calendar(); return; }
        if ( $action === 'view'     ) { self::render_view( absint($_GET['id'] ?? 0) ); return; }
        if ( $action === 'edit'     ) { self::render_edit( absint($_GET['id'] ?? 0) ); return; }
        if ( $action === 'add'      ) { self::render_add(); return; }
        self::render_list();
    }

    /* ════════════════════════════════════════════════════════════
       MUTATION HANDLERS
    ════════════════════════════════════════════════════════════ */

    private static function handle_mutations() : void {
        global $wpdb;
        $table = $wpdb->prefix . 'credoq_bookings';

        // ── CSV import ───────────────────────────────────────────
        if ( isset($_POST['credoq_import_bookings']) ) {
            check_admin_referer('credoq_import_bookings');
            $msg = self::handle_csv_import();
            add_action('admin_notices', function() use ($msg) { echo "<div class='notice notice-success is-dismissible'><p>".esc_html($msg)."</p></div>"; });
        }

        // ── CSV export ───────────────────────────────────────────
        if ( isset($_GET['action']) && $_GET['action'] === 'export_csv' ) {
            check_admin_referer('credoq_export_bookings');
            self::export_csv();
            exit;
        }

        // ── Single quick actions (confirm/cancel/delete) ─────────
        if ( isset($_GET['action'], $_GET['id'], $_GET['_wpnonce']) ) {
            $act = sanitize_key($_GET['action']);
            $id  = absint($_GET['id']);
            if ( wp_verify_nonce($_GET['_wpnonce'], 'credoq_booking_action') ) {
                if ($act === 'confirm')  Booking_Service::confirm($id);
                elseif ($act === 'cancel')   Booking_Service::cancel($id);
                elseif ($act === 'delete') { Booking_Repository::delete($id); }
                wp_safe_redirect( add_query_arg(['page'=>'credoq-bookings','done'=>$act], admin_url('admin.php')) );
                exit;
            }
        }

        // ── Bulk actions ─────────────────────────────────────────
        if ( isset($_POST['credoq_bulk'], $_POST['ids']) ) {
            check_admin_referer('credoq_bookings_bulk');
            $bulk = sanitize_key($_POST['credoq_bulk']);
            $ids  = array_filter( array_map('absint', (array)$_POST['ids']) );
            if ( $ids ) {
                $ph = implode(',', array_fill(0, count($ids), '%d'));
                if ($bulk === 'delete') {
                    $wpdb->query( $wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$ph})", $ids) );
                } elseif ( isset(self::STATUSES[str_replace('status_','',$bulk)]) ) {
                    $s = str_replace('status_','',$bulk);
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$table} SET status=%s, updated_at=%s WHERE id IN ({$ph})",
                        array_merge([$s, current_time('mysql')], $ids)
                    ));
                }
            }
            wp_safe_redirect( add_query_arg(['page'=>'credoq-bookings','bulk_done'=>1], admin_url('admin.php')) );
            exit;
        }

        // ── Save edit / add ──────────────────────────────────────
        if ( isset($_POST['credoq_save_booking']) ) {
            $id = absint($_POST['booking_id'] ?? 0);
            check_admin_referer('credoq_save_booking_'.$id);
            self::save_booking($id);
            wp_safe_redirect( add_query_arg(['page'=>'credoq-bookings','action'=>'view','id'=>$id,'saved'=>1], admin_url('admin.php')) );
            exit;
        }
        if ( isset($_POST['credoq_add_booking']) ) {
            check_admin_referer('credoq_add_booking');
            $new_id = self::create_booking();
            wp_safe_redirect( add_query_arg(['page'=>'credoq-bookings','action'=>'view','id'=>$new_id,'saved'=>1], admin_url('admin.php')) );
            exit;
        }
    }

    /* ════════════════════════════════════════════════════════════
       LIST VIEW
    ════════════════════════════════════════════════════════════ */

    private static function render_list() : void {
        global $wpdb;
        $paged   = max(1, absint($_GET['paged'] ?? 1));
        $offset  = ($paged - 1) * self::PER_PAGE;
        $filters = self::get_filters();
        $total    = Booking_Repository::count_with_filters($filters);
        $bookings = Booking_Repository::paginated($filters, self::PER_PAGE, $offset);
        $apts     = Appointment_Repository::all(200, 0);
        $staff    = Staff_Repository::all(200, 0);
        $settings = get_option('credoq_engine_settings', []);
        $currency = $settings['currency'] ?? 'USD';

        // Status counts for chips
        $status_counts = [];
        foreach ( $wpdb->get_results("SELECT status, COUNT(*) c FROM {$wpdb->prefix}credoq_bookings GROUP BY status") as $r ) {
            $status_counts[$r->status] = (int)$r->c;
        }
        $today = date('Y-m-d');

        ?>
        <div class="wrap credoq-admin-wrap">
        <?php self::page_header('list', $total); ?>
        <?php self::notices(); ?>

        <!-- Filter bar -->
        <div class="credoq-card" style="margin-bottom:16px;">
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="page" value="credoq-bookings">
            <input type="search" name="s" value="<?php echo esc_attr($_GET['s']??''); ?>"
                placeholder="Search email / name…"
                style="padding:7px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;min-width:200px;">
            <select name="apt_id" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
                <option value="0">All Services</option>
                <?php foreach ($apts as $a) : ?><option value="<?php echo (int)$a->id; ?>" <?php selected($filters['appointment_id'],(int)$a->id); ?>><?php echo esc_html($a->title); ?></option><?php endforeach; ?>
            </select>
            <select name="staff_id" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
                <option value="0">All Staff</option>
                <?php foreach ($staff as $s) : ?><option value="<?php echo (int)$s->id; ?>" <?php selected($filters['staff_id'],(int)$s->id); ?>><?php echo esc_html($s->display_name); ?></option><?php endforeach; ?>
            </select>
            <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
            <span style="color:#94a3b8;line-height:34px;">—</span>
            <input type="date" name="date_to"   value="<?php echo esc_attr($filters['date_to']); ?>"   style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
            <button type="submit" class="button button-primary" style="border-radius:8px;">Filter</button>
            <a href="<?php echo esc_url(admin_url('admin.php?page=credoq-bookings')); ?>" class="button" style="border-radius:8px;">Clear</a>
        </form>
        <!-- Status chips -->
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px;">
            <?php
            $chip_url = function($s) { $a = array_merge($_GET,['page'=>'credoq-bookings']); if($s){$a['status']=$s;}else{unset($a['status']);} return add_query_arg($a,admin_url('admin.php')); };
            $cur_status = $filters['status'] ?? '';
            ?>
            <a href="<?php echo esc_url($chip_url('')); ?>" class="cq-chip<?php echo !$cur_status?' cq-chip-active':''; ?>">All <span><?php echo (int)array_sum($status_counts); ?></span></a>
            <?php foreach (self::STATUSES as $key => $meta) :
                $c = $status_counts[$key] ?? 0; ?>
            <a href="<?php echo esc_url($chip_url($key)); ?>"
               class="cq-chip<?php echo $cur_status===$key?' cq-chip-active':''; ?>"
               style="<?php echo $cur_status===$key?"background:{$meta['bg']};color:{$meta['color']};border-color:{$meta['color']};":""; ?>">
               <?php echo esc_html($meta['label']); ?> <span><?php echo (int)$c; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        </div>

        <?php if (empty($bookings)) : ?>
            <div class="credoq-card"><p style="color:#94a3b8;margin:0;">No bookings found.</p></div>
        <?php else : ?>

        <form method="post">
        <?php wp_nonce_field('credoq_bookings_bulk'); ?>
        <div class="credoq-card" style="padding:0;overflow:hidden;">
            <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1.5px solid #f1f5f9;">
                <select name="credoq_bulk" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
                    <option value="">Bulk actions</option>
                    <?php foreach (self::STATUSES as $k => $m) : ?><option value="status_<?php echo $k; ?>">Mark <?php echo esc_html($m['label']); ?></option><?php endforeach; ?>
                    <option value="delete">Delete</option>
                </select>
                <button type="submit" class="button" onclick="return confirm('Apply to selected?')">Apply</button>
                <!-- Import -->
                <div style="margin-left:auto;display:flex;gap:8px;">
                    <label class="button" style="cursor:pointer;">
                        📥 Import CSV
                        <input type="file" name="csv_file" accept=".csv" style="display:none;"
                               onchange="this.closest('form').querySelector('[name=credoq_import_bookings]').click();">
                    </label>
                    <?php wp_nonce_field('credoq_import_bookings','credoq_import_bookings_nonce'); ?>
                    <button name="credoq_import_bookings" value="1" style="display:none;"></button>
                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array_merge($_GET,['page'=>'credoq-bookings','action'=>'export_csv']),admin_url('admin.php')),'credoq_export_bookings')); ?>" class="button">📤 Export CSV</a>
                    <a href="<?php echo esc_url(add_query_arg(['page'=>'credoq-bookings','action'=>'add'],admin_url('admin.php'))); ?>" class="button button-primary">+ New Booking</a>
                </div>
            </div>
            <table class="widefat" style="border:none;font-size:13px;">
            <thead><tr style="background:#f8fafc;">
                <th style="width:30px;padding:10px 12px;"><input type="checkbox" onclick="document.querySelectorAll('.cq-cb').forEach(c=>c.checked=this.checked)"></th>
                <th style="padding:10px 12px;">#</th>
                <th style="padding:10px 12px;">Client</th>
                <th style="padding:10px 12px;">Service</th>
                <th style="padding:10px 12px;">Staff</th>
                <th style="padding:10px 12px;">Date</th>
                <th style="padding:10px 12px;">Time</th>
                <th style="padding:10px 12px;">Price</th>
                <th style="padding:10px 12px;">Status</th>
                <th style="padding:10px 12px;">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($bookings as $b) :
                $meta    = self::STATUSES[$b->status] ?? ['label'=>ucfirst($b->status),'color'=>'#64748b','bg'=>'#f8fafc'];
                $date    = $b->selected_date;
                $is_past   = $date < $today;
                $is_today  = $date === $today;
                $row_pill_style = $is_today ? 'background:#eff6ff;' : ($is_past ? 'background:#fff5f5;' : '');
                $view_url = add_query_arg(['page'=>'credoq-bookings','action'=>'view','id'=>$b->id], admin_url('admin.php'));
                $edit_url = add_query_arg(['page'=>'credoq-bookings','action'=>'edit','id'=>$b->id], admin_url('admin.php'));
                $del_url  = wp_nonce_url(add_query_arg(['page'=>'credoq-bookings','action'=>'delete','id'=>$b->id],admin_url('admin.php')),'credoq_booking_action');
            ?>
            <tr style="border-bottom:1px solid #f1f5f9;<?php echo $row_pill_style; ?>">
                <td style="padding:10px 12px;"><input type="checkbox" class="cq-cb" name="ids[]" value="<?php echo (int)$b->id; ?>"></td>
                <td style="padding:10px 12px;color:#94a3b8;">#<?php echo (int)$b->id; ?></td>
                <td style="padding:10px 12px;">
                    <strong><?php echo esc_html($b->user_name ?: $b->guest_name ?: '—'); ?></strong>
                    <?php if ($b->user_email) : ?><br><span style="font-size:11px;color:#64748b;"><?php echo esc_html($b->user_email); ?></span><?php endif; ?>
                </td>
                <td style="padding:10px 12px;"><?php echo esc_html($b->apt_title ?: '—'); ?></td>
                <td style="padding:10px 12px;"><?php echo esc_html($b->staff_name ?: '—'); ?></td>
                <td style="padding:10px 12px;">
                    <?php if ($is_today) : ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:99px;padding:2px 8px;font-size:11px;font-weight:700;">● Today</span>
                    <?php elseif ($is_past) : ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;background:#fff1f2;color:#b91c1c;border:1px solid #fecaca;border-radius:99px;padding:2px 8px;font-size:11px;font-weight:700;">● Past</span>
                    <?php else : ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:99px;padding:2px 8px;font-size:11px;font-weight:700;">● Upcoming</span>
                    <?php endif; ?>
                    <div style="font-size:12px;color:#475569;margin-top:2px;"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($date))); ?></div>
                </td>
                <td style="padding:10px 12px;"><?php echo esc_html(date_i18n('H:i', strtotime($b->selected_time))); ?></td>
                <td style="padding:10px 12px;font-weight:600;"><?php echo $b->total_price > 0 ? esc_html($currency.' '.number_format((float)$b->total_price,2)) : '<span style="color:#94a3b8;">Free</span>'; ?></td>
                <td style="padding:10px 12px;">
                    <span style="display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;background:<?php echo esc_attr($meta['bg']); ?>;color:<?php echo esc_attr($meta['color']); ?>;">
                        <?php echo esc_html($meta['label']); ?>
                    </span>
                </td>
                <td style="padding:10px 12px;white-space:nowrap;">
                    <a href="<?php echo esc_url($view_url); ?>" title="View" style="text-decoration:none;margin-right:4px;font-size:15px;">👁</a>
                    <a href="<?php echo esc_url($edit_url); ?>" title="Edit" style="text-decoration:none;margin-right:4px;font-size:15px;">✏️</a>
                    <a href="<?php echo esc_url($del_url); ?>" title="Delete" style="text-decoration:none;font-size:15px;"
                       onclick="return confirm('Delete booking #<?php echo (int)$b->id; ?>?');">🗑</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
        </div>
        </form>

        <?php $pages = (int)ceil($total / self::PER_PAGE);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links(['base'=>add_query_arg('paged','%#%'),'format'=>'','total'=>$pages,'current'=>$paged]);
            echo '</div></div>';
        } ?>
        <?php endif; ?>
        </div>
        <?php self::shared_styles(); ?>
        <?php
    }

    /* ════════════════════════════════════════════════════════════
       CALENDAR VIEW
    ════════════════════════════════════════════════════════════ */

    private static function render_calendar() : void {
        global $wpdb;
        $year  = absint($_GET['cal_year']  ?? date('Y'));
        $month = absint($_GET['cal_month'] ?? date('n'));
        if ($month < 1 || $month > 12) $month = date('n');

        // Fetch all bookings in the displayed month
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to   = date('Y-m-t', strtotime($from));
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id, b.selected_date, b.selected_time, b.status, b.total_price,
                    b.guest_name, b.guest_email, b.user_id,
                    a.title AS apt_title, s.display_name AS staff_name,
                    u.display_name AS user_name, u.user_email
             FROM {$wpdb->prefix}credoq_bookings b
             LEFT JOIN {$wpdb->prefix}credoq_appointments a ON b.appointment_id = a.id
             LEFT JOIN {$wpdb->prefix}credoq_staff s ON b.staff_id = s.id
             LEFT JOIN {$wpdb->users} u ON b.user_id = u.ID
             WHERE b.selected_date BETWEEN %s AND %s
             ORDER BY b.selected_date ASC, b.selected_time ASC", $from, $to
        ));

        // Group by date
        $by_date = [];
        foreach ($rows as $r) { $by_date[$r->selected_date][] = $r; }

        $today     = date('Y-m-d');
        $prev_url  = add_query_arg(['page'=>'credoq-bookings','action'=>'calendar','cal_year'=>$month===1?$year-1:$year,'cal_month'=>$month===1?12:$month-1], admin_url('admin.php'));
        $next_url  = add_query_arg(['page'=>'credoq-bookings','action'=>'calendar','cal_year'=>$month===12?$year+1:$year,'cal_month'=>$month===12?1:$month+1], admin_url('admin.php'));
        $month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

        $first_dow = (int)date('N', strtotime($from)); // 1=Mon, 7=Sun
        $days_in   = (int)date('t', strtotime($from));
        $settings  = get_option('credoq_engine_settings', []);
        $currency  = $settings['currency'] ?? 'USD';
        ?>
        <div class="wrap credoq-admin-wrap">
        <?php self::page_header('calendar', count($rows)); ?>
        <?php self::notices(); ?>

        <!-- Calendar nav -->
        <div class="credoq-card" style="padding:14px 20px;">
            <div style="display:flex;align-items:center;gap:16px;">
                <a href="<?php echo esc_url($prev_url); ?>" class="button">‹</a>
                <h2 style="margin:0;font-size:20px;font-weight:700;"><?php echo esc_html($month_names[$month].' '.$year); ?></h2>
                <a href="<?php echo esc_url($next_url); ?>" class="button">›</a>
                <a href="<?php echo esc_url(add_query_arg(['page'=>'credoq-bookings','action'=>'calendar','cal_year'=>date('Y'),'cal_month'=>date('n')],admin_url('admin.php'))); ?>"
                   class="button" style="margin-left:4px;">Today</a>
                <div style="margin-left:auto;display:flex;gap:10px;font-size:11px;">
                    <span style="background:#fff1f2;color:#b91c1c;border:1px solid #fecaca;border-radius:99px;padding:2px 10px;font-weight:700;">● Past</span>
                    <span style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:99px;padding:2px 10px;font-weight:700;">● Today</span>
                    <span style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:99px;padding:2px 10px;font-weight:700;">● Upcoming</span>
                </div>
            </div>
        </div>

        <!-- Calendar grid -->
        <div class="credoq-card" style="padding:0;overflow:hidden;">
        <div style="display:grid;grid-template-columns:repeat(7,1fr);border-bottom:2px solid #e2e8f0;">
            <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d) : ?>
                <div style="padding:10px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;"><?php echo $d; ?></div>
            <?php endforeach; ?>
        </div>
        <div style="display:grid;grid-template-columns:repeat(7,1fr);">
            <?php
            // Empty cells before first day
            for ($e = 1; $e < $first_dow; $e++) {
                echo '<div style="min-height:110px;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;background:#fafafa;"></div>';
            }
            for ($d = 1; $d <= $days_in; $d++) {
                $iso      = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $is_today  = $iso === $today;
                $is_past   = $iso < $today;
                $is_future = $iso > $today;
                $bookings_day = $by_date[$iso] ?? [];
                $total_bkgs   = count($bookings_day);
                $total_rev    = array_sum(array_column($bookings_day, 'total_price'));

                if ($is_today) $bg = '#eff6ff'; elseif ($is_past) $bg = '#fff8f8'; else $bg = '#fff';
                $border_color = $is_today ? '#3b82f6' : '#f1f5f9';

                echo '<div style="min-height:110px;border-right:1px solid '.$border_color.';border-bottom:1px solid '.$border_color.';background:'.$bg.';padding:6px;position:relative;cursor:pointer;" onclick="cqCalDayClick('.json_encode($iso).', '.json_encode(array_map(fn($b)=>['id'=>(int)$b->id,'time'=>substr($b->selected_time,0,5),'status'=>$b->status,'apt'=>$b->apt_title,'client'=>$b->user_name?:$b->guest_name,'email'=>$b->user_email?:$b->guest_email,'price'=>(float)$b->total_price], $bookings_day)).')">';
                echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">';
                echo '<span style="font-size:13px;font-weight:'.($is_today?'800':'600').';color:'.($is_today?'#1d4ed8':($is_past?'#94a3b8':'#1e293b')).';'.($is_today?'background:#dbeafe;padding:1px 6px;border-radius:99px;':'').'">'.$d.'</span>';
                if ($total_bkgs > 0) echo '<span style="font-size:10px;font-weight:700;background:'.($is_today?'#3b82f6':($is_past?'#dc2626':'#16a34a')).';color:#fff;border-radius:99px;padding:1px 7px;">'.$total_bkgs.'</span>';
                echo '</div>';

                // Show up to 3 booking pills
                $shown = 0;
                foreach ($bookings_day as $bk) {
                    if ($shown >= 3) break;
                    $m = self::STATUSES[$bk->status] ?? ['label'=>ucfirst($bk->status),'color'=>'#64748b','bg'=>'#f1f5f9'];
                    $name = $bk->user_name ?: $bk->guest_name ?: 'Guest';
                    echo '<div style="font-size:10px;padding:2px 5px;border-radius:4px;background:'.esc_attr($m['bg']).';color:'.esc_attr($m['color']).';margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="'.esc_attr($name.' – '.$bk->apt_title).'">'.esc_html(substr($name,0,14)).' '.esc_html(substr($bk->selected_time,0,5)).'</div>';
                    $shown++;
                }
                if ($total_bkgs > 3) echo '<div style="font-size:10px;color:#94a3b8;padding:1px 4px;">+'.($total_bkgs-3).' more</div>';
                if ($total_rev > 0) echo '<div style="font-size:10px;color:#64748b;margin-top:4px;font-weight:600;">'.$currency.' '.number_format($total_rev,2).'</div>';
                echo '</div>';
            }
            // Trailing empties
            $last_dow = (int)date('N', strtotime(sprintf('%04d-%02d-%02d', $year, $month, $days_in)));
            for ($e = $last_dow + 1; $e <= 7; $e++) {
                echo '<div style="min-height:110px;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;background:#fafafa;"></div>';
            }
            ?>
        </div>
        </div>

        <!-- Day popup (hidden) -->
        <div id="cq-day-popup" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.4);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:14px;min-width:380px;max-width:520px;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.2);max-height:80vh;overflow-y:auto;position:relative;">
                <button onclick="document.getElementById('cq-day-popup').style.display='none';" style="position:absolute;top:12px;right:12px;background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;">✕</button>
                <h3 id="cq-popup-date" style="margin:0 0 16px;font-size:16px;font-weight:700;"></h3>
                <div id="cq-popup-body"></div>
            </div>
        </div>

        <script>
        function cqCalDayClick(iso, bookings) {
            var popup = document.getElementById('cq-day-popup');
            var dateEl = document.getElementById('cq-popup-date');
            var bodyEl  = document.getElementById('cq-popup-body');
            // Format the date label
            var d = new Date(iso + 'T00:00:00');
            dateEl.textContent = d.toLocaleDateString(undefined, {weekday:'long',year:'numeric',month:'long',day:'numeric'});

            if (!bookings.length) {
                bodyEl.innerHTML = '<p style="color:#94a3b8;text-align:center;">No bookings this day.</p>';
            } else {
                var STATUS_COLORS = <?php echo json_encode(array_map(fn($m)=>['color'=>$m['color'],'bg'=>$m['bg'],'label'=>$m['label']], self::STATUSES)); ?>;
                var html = '<div style="display:flex;flex-direction:column;gap:10px;">';
                bookings.forEach(function(b) {
                    var m = STATUS_COLORS[b.status] || {color:'#64748b',bg:'#f1f5f9',label:b.status};
                    var price = b.price > 0 ? '<?php echo esc_js($currency); ?> ' + b.price.toFixed(2) : 'Free';
                    var viewUrl = '<?php echo esc_js(admin_url('admin.php?page=credoq-bookings&action=view&id=')); ?>' + b.id;
                    html += '<div style="border:1.5px solid #e2e8f0;border-radius:10px;padding:12px;">';
                    html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">';
                    html +=   '<strong style="font-size:13px;">' + (b.client || 'Guest') + '</strong>';
                    html +=   '<span style="background:'+m.bg+';color:'+m.color+';border-radius:99px;padding:2px 8px;font-size:11px;font-weight:700;">'+m.label+'</span>';
                    html += '</div>';
                    html += '<div style="font-size:12px;color:#64748b;">🕐 '+b.time+' &nbsp; 📋 '+b.apt+' &nbsp; 💰 '+price+'</div>';
                    if (b.email) html += '<div style="font-size:11px;color:#94a3b8;margin-top:3px;">'+b.email+'</div>';
                    html += '<div style="margin-top:10px;"><a href="'+viewUrl+'" class="button button-primary" style="font-size:12px;padding:4px 14px;border-radius:6px;">View Details →</a></div>';
                    html += '</div>';
                });
                html += '</div>';
                bodyEl.innerHTML = html;
            }
            popup.style.display = 'flex';
        }
        document.getElementById('cq-day-popup').addEventListener('click', function(e){
            if (e.target === this) this.style.display = 'none';
        });
        </script>

        </div>
        <?php self::shared_styles(); ?>
        <?php
    }

    /* ════════════════════════════════════════════════════════════
       VIEW / EDIT
    ════════════════════════════════════════════════════════════ */

    private static function render_view( int $id ) : void {
        $b = self::load_booking($id);
        if (!$b) { echo '<div class="wrap credoq-admin-wrap"><div class="credoq-card"><p>Booking not found.</p></div></div>'; return; }
        self::render_booking_form($b, 'view');
    }

    private static function render_edit( int $id ) : void {
        $b = self::load_booking($id);
        if (!$b) { echo '<div class="wrap credoq-admin-wrap"><div class="credoq-card"><p>Booking not found.</p></div></div>'; return; }
        self::render_booking_form($b, 'edit');
    }

    private static function render_booking_form( object $b, string $mode ) : void {
        $apts  = Appointment_Repository::all(200,0);
        $staff = Staff_Repository::all(200,0);
        $settings = get_option('credoq_engine_settings',[]);
        $currency = $settings['currency'] ?? 'USD';
        $meta   = self::STATUSES[$b->status] ?? ['label'=>ucfirst($b->status),'color'=>'#64748b','bg'=>'#f1f5f9'];
        $is_edit = $mode === 'edit';
        $form_data = json_decode($b->form_data ?: '{}', true) ?: [];
        ?>
        <div class="wrap credoq-admin-wrap">
        <div class="credoq-page-header"><div class="credoq-page-header-inner">
            <h1 class="credoq-page-title">
                <a href="<?php echo esc_url(admin_url('admin.php?page=credoq-bookings')); ?>" style="color:#94a3b8;text-decoration:none;margin-right:6px;">&larr;</a>
                Booking #<?php echo (int)$b->id; ?>
                <span style="display:inline-block;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;background:<?php echo esc_attr($meta['bg']); ?>;color:<?php echo esc_attr($meta['color']); ?>;margin-left:10px;"><?php echo esc_html($meta['label']); ?></span>
            </h1>
            <div style="display:flex;gap:8px;">
                <?php if ($is_edit) : ?>
                <button form="cq-booking-form" type="submit" class="button button-primary">💾 Save Changes</button>
                <a href="<?php echo esc_url(add_query_arg(['page'=>'credoq-bookings','action'=>'view','id'=>$b->id],admin_url('admin.php'))); ?>" class="button">Cancel</a>
                <?php else : ?>
                <a href="<?php echo esc_url(add_query_arg(['page'=>'credoq-bookings','action'=>'edit','id'=>$b->id],admin_url('admin.php'))); ?>" class="button button-primary">✏️ Edit</a>
                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page'=>'credoq-bookings','action'=>'delete','id'=>$b->id],admin_url('admin.php')),'credoq_booking_action')); ?>"
                   class="button" onclick="return confirm('Delete this booking?');">🗑 Delete</a>
                <?php endif; ?>
            </div>
        </div></div>

        <?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Booking saved.</p></div><?php endif; ?>

        <form id="cq-booking-form" method="post">
        <?php wp_nonce_field('credoq_save_booking_'.$b->id); ?>
        <input type="hidden" name="booking_id" value="<?php echo (int)$b->id; ?>">

        <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">
        <div>
            <!-- Customer Info -->
            <div class="credoq-card">
                <h2 class="credoq-section-title">Customer</h2>
                <table class="form-table">
                    <tr><th>Name</th><td><?php if ($is_edit) : ?><input type="text" name="guest_name" value="<?php echo esc_attr($b->guest_name); ?>" class="regular-text"><?php else : echo esc_html($b->user_name ?: $b->guest_name ?: '—'); ?><?php endif; ?></td></tr>
                    <tr><th>Email</th><td><?php if ($is_edit) : ?><input type="email" name="guest_email" value="<?php echo esc_attr($b->guest_email ?: $b->user_email); ?>" class="regular-text"><?php else : echo esc_html($b->user_email ?: $b->guest_email ?: '—'); ?><?php endif; ?></td></tr>
                </table>
            </div>

            <!-- Booking Details -->
            <div class="credoq-card">
                <h2 class="credoq-section-title">Appointment</h2>
                <table class="form-table">
                    <tr><th>Service</th><td>
                        <?php if ($is_edit) : ?>
                        <select name="appointment_id">
                            <?php foreach ($apts as $a) : ?><option value="<?php echo (int)$a->id; ?>" <?php selected($b->appointment_id,$a->id); ?>><?php echo esc_html($a->title); ?></option><?php endforeach; ?>
                        </select>
                        <?php else : echo esc_html($b->apt_title ?: '—'); endif; ?>
                    </td></tr>
                    <tr><th>Staff</th><td>
                        <?php if ($is_edit) : ?>
                        <select name="staff_id">
                            <option value="0">— Any —</option>
                            <?php foreach ($staff as $s) : ?><option value="<?php echo (int)$s->id; ?>" <?php selected($b->staff_id,$s->id); ?>><?php echo esc_html($s->display_name); ?></option><?php endforeach; ?>
                        </select>
                        <?php else : echo esc_html($b->staff_name ?: '—'); endif; ?>
                    </td></tr>
                    <tr><th>Date</th><td><?php if ($is_edit) : ?><input type="date" name="selected_date" value="<?php echo esc_attr($b->selected_date); ?>"><?php else : echo esc_html(date_i18n(get_option('date_format'),strtotime($b->selected_date))); endif; ?></td></tr>
                    <tr><th>Time</th><td><?php if ($is_edit) : ?><input type="time" name="selected_time" value="<?php echo esc_attr(substr($b->selected_time,0,5)); ?>"><?php else : echo esc_html(date_i18n('H:i',strtotime($b->selected_time))); endif; ?></td></tr>
                    <tr><th>Status</th><td>
                        <?php if ($is_edit) : ?>
                        <select name="status">
                            <?php foreach (self::STATUSES as $k=>$m) : ?><option value="<?php echo $k; ?>" <?php selected($b->status,$k); ?>><?php echo esc_html($m['label']); ?></option><?php endforeach; ?>
                        </select>
                        <?php else : echo esc_html($meta['label']); endif; ?>
                    </td></tr>
                    <tr><th>Price</th><td><?php if ($is_edit) : ?><input type="number" name="total_price" step="0.01" min="0" value="<?php echo esc_attr($b->total_price); ?>" class="small-text"><?php else : echo esc_html($currency.' '.number_format((float)$b->total_price,2)); endif; ?></td></tr>
                </table>
            </div>

            <!-- Form Data (customer answers) -->
            <?php if (!empty($form_data)) : ?>
            <div class="credoq-card">
                <h2 class="credoq-section-title">Submitted Form Data</h2>
                <table class="form-table">
                <?php foreach ($form_data as $key => $val) :
                    if (in_array($key, ['recaptcha_token','g-recaptcha-response','nonce','__addon_total','__wc_total','__grand_total'], true)) continue;
                    $display = is_array($val) ? implode(', ', array_map('strval', $val)) : (string)$val;
                    if (strpos($display, 'data:image') === 0) $display = '(signature)';
                    $label = ucwords(str_replace(['_','-'],' ',$key));
                ?>
                    <tr>
                        <th style="width:180px;"><?php echo esc_html($label); ?></th>
                        <td>
                            <?php if ($is_edit) : ?>
                                <?php if (strlen($display) > 80 || strpos($display, "\n") !== false) : ?>
                                    <textarea name="form_data[<?php echo esc_attr($key); ?>]" rows="3" class="large-text"><?php echo esc_textarea($display); ?></textarea>
                                <?php else : ?>
                                    <input type="text" name="form_data[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($display); ?>" class="regular-text">
                                <?php endif; ?>
                            <?php else : ?>
                                <?php echo esc_html($display); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right column: summary + notes -->
        <div>
            <div class="credoq-card">
                <h2 class="credoq-section-title">Summary</h2>
                <table class="credoq-table" style="width:100%;">
                    <tr><td style="color:#64748b;">WC Order</td><td style="text-align:right;"><?php echo $b->wc_order_id ? '<a href="'.esc_url(admin_url('post.php?post='.(int)$b->wc_order_id.'&action=edit')).'">Order #'.(int)$b->wc_order_id.' →</a>' : '—'; ?></td></tr>
                    <tr><td style="color:#64748b;">Credits deducted</td><td style="text-align:right;"><?php echo (int)$b->credit_deducted ?: '—'; ?></td></tr>
                    <tr><td style="color:#64748b;">Created</td><td style="text-align:right;"><?php echo esc_html(mysql2date(get_option('date_format').' '.get_option('time_format'),$b->created_at)); ?></td></tr>
                    <?php if (!empty($b->group_id)) : ?>
                    <tr><td style="color:#64748b;">Multi-booking</td><td style="text-align:right;font-family:monospace;font-size:11px;"><?php echo esc_html(substr($b->group_id,0,12)).'…'; ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <div class="credoq-card">
                <h2 class="credoq-section-title">Internal Notes</h2>
                <?php if ($is_edit) : ?>
                    <textarea name="notes" rows="5" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:13px;"><?php echo esc_textarea($b->notes ?? ''); ?></textarea>
                <?php else : ?>
                    <p style="color:<?php echo empty($b->notes)?'#94a3b8':'#1e293b'; ?>;white-space:pre-wrap;margin:0;"><?php echo esc_html($b->notes ?: 'No notes.'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        </div>
        </form>
        </div>
        <?php self::shared_styles(); ?>
        <?php
    }

    /* ════════════════════════════════════════════════════════════
       ADD NEW BOOKING
    ════════════════════════════════════════════════════════════ */

    private static function render_add() : void {
        $apts   = Appointment_Repository::all(200,0);
        $staff  = Staff_Repository::all(200,0);
        $users  = get_users(['number'=>200,'fields'=>['ID','display_name','user_email']]);
        ?>
        <div class="wrap credoq-admin-wrap">
        <div class="credoq-page-header"><div class="credoq-page-header-inner">
            <h1 class="credoq-page-title">
                <a href="<?php echo esc_url(admin_url('admin.php?page=credoq-bookings')); ?>" style="color:#94a3b8;text-decoration:none;margin-right:6px;">&larr;</a>
                New Booking
            </h1>
        </div></div>
        <form method="post">
        <?php wp_nonce_field('credoq_add_booking'); ?>
        <div class="credoq-card" style="max-width:640px;">
            <h2 class="credoq-section-title">Booking Details</h2>
            <table class="form-table">
                <tr><th>Service <span style="color:#dc2626;">*</span></th><td>
                    <select name="appointment_id" required>
                        <option value="0">— Select service —</option>
                        <?php foreach ($apts as $a) : ?><option value="<?php echo (int)$a->id; ?>"><?php echo esc_html($a->title); ?></option><?php endforeach; ?>
                    </select>
                </td></tr>
                <tr><th>Staff</th><td>
                    <select name="staff_id">
                        <option value="0">— Any —</option>
                        <?php foreach ($staff as $s) : ?><option value="<?php echo (int)$s->id; ?>"><?php echo esc_html($s->display_name); ?></option><?php endforeach; ?>
                    </select>
                </td></tr>
                <tr><th>Date <span style="color:#dc2626;">*</span></th><td><input type="date" name="selected_date" value="<?php echo date('Y-m-d'); ?>" required></td></tr>
                <tr><th>Time <span style="color:#dc2626;">*</span></th><td><input type="time" name="selected_time" value="09:00" required></td></tr>
                <tr><th>Status</th><td>
                    <select name="status">
                        <?php foreach (self::STATUSES as $k=>$m) : ?><option value="<?php echo $k; ?>" <?php selected($k,'confirmed'); ?>><?php echo esc_html($m['label']); ?></option><?php endforeach; ?>
                    </select>
                </td></tr>
                <tr><th>Customer</th><td>
                    <select name="user_id">
                        <option value="0">— Guest / manual —</option>
                        <?php foreach ($users as $u) : ?><option value="<?php echo (int)$u->ID; ?>"><?php echo esc_html($u->display_name.' ('.$u->user_email.')'); ?></option><?php endforeach; ?>
                    </select>
                </td></tr>
                <tr><th>Guest Name</th><td><input type="text" name="guest_name" class="regular-text" placeholder="Full name"></td></tr>
                <tr><th>Guest Email</th><td><input type="email" name="guest_email" class="regular-text" placeholder="email@example.com"></td></tr>
                <tr><th>Price</th><td><input type="number" name="total_price" step="0.01" min="0" value="0.00" class="small-text"></td></tr>
                <tr><th>Notes</th><td><textarea name="notes" rows="4" class="large-text"></textarea></td></tr>
            </table>
            <p>
                <button type="submit" name="credoq_add_booking" value="1" class="button button-primary button-large">Create Booking</button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=credoq-bookings')); ?>" class="button button-large">Cancel</a>
            </p>
        </div>
        </form>
        </div>
        <?php self::shared_styles(); ?>
        <?php
    }

    /* ════════════════════════════════════════════════════════════
       HELPERS
    ════════════════════════════════════════════════════════════ */

    private static function load_booking( int $id ) : ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT b.*, a.title AS apt_title, s.display_name AS staff_name,
                    u.display_name AS user_name, u.user_email
             FROM {$wpdb->prefix}credoq_bookings b
             LEFT JOIN {$wpdb->prefix}credoq_appointments a ON b.appointment_id = a.id
             LEFT JOIN {$wpdb->prefix}credoq_staff s ON b.staff_id = s.id
             LEFT JOIN {$wpdb->users} u ON b.user_id = u.ID
             WHERE b.id = %d", $id
        ) );
    }

    private static function get_filters() : array {
        return [
            'status'         => sanitize_text_field($_GET['status']    ?? ''),
            'appointment_id' => absint($_GET['apt_id']     ?? 0),
            'staff_id'       => absint($_GET['staff_id']   ?? 0),
            'date_from'      => sanitize_text_field($_GET['date_from'] ?? ''),
            'date_to'        => sanitize_text_field($_GET['date_to']   ?? ''),
            's'              => sanitize_text_field($_GET['s']         ?? ''),
        ];
    }

    private static function save_booking( int $id ) : void {
        global $wpdb;
        $table = $wpdb->prefix . 'credoq_bookings';

        $data = [
            'appointment_id' => absint($_POST['appointment_id'] ?? 0),
            'staff_id'       => absint($_POST['staff_id']       ?? 0),
            'guest_name'     => sanitize_text_field($_POST['guest_name']  ?? ''),
            'guest_email'    => sanitize_email($_POST['guest_email']      ?? ''),
            'selected_date'  => sanitize_text_field($_POST['selected_date'] ?? ''),
            'selected_time'  => sanitize_text_field($_POST['selected_time'] ?? ''),
            'status'         => sanitize_key($_POST['status'] ?? 'pending'),
            'total_price'    => round(floatval($_POST['total_price'] ?? 0), 2),
            'notes'          => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
        ];

        // Update the editable form_data fields
        $form_data = [];
        if (!empty($_POST['form_data']) && is_array($_POST['form_data'])) {
            $existing = json_decode($wpdb->get_var($wpdb->prepare("SELECT form_data FROM {$table} WHERE id=%d",$id)) ?: '{}', true) ?: [];
            foreach ($_POST['form_data'] as $k => $v) {
                $existing[sanitize_key($k)] = is_array($v) ? array_map('sanitize_text_field', $v) : sanitize_text_field(wp_unslash((string)$v));
            }
            $form_data = $existing;
        }
        if ($form_data) $data['form_data'] = wp_json_encode($form_data);

        $wpdb->update($table, $data, ['id'=>$id]);
        do_action('credoq_booking_saved', $id, $data);
    }

    private static function create_booking() : int {
        $apts = Appointment_Repository::all(200,0);
        $apt_id = absint($_POST['appointment_id'] ?? 0);
        $apt = $apt_id ? Appointment_Repository::find($apt_id) : null;

        $data = [
            'appointment_id' => $apt_id,
            'staff_id'       => absint($_POST['staff_id']      ?? 0),
            'user_id'        => absint($_POST['user_id']       ?? 0),
            'guest_name'     => sanitize_text_field($_POST['guest_name']   ?? ''),
            'guest_email'    => sanitize_email($_POST['guest_email']        ?? ''),
            'selected_date'  => sanitize_text_field($_POST['selected_date'] ?? date('Y-m-d')),
            'selected_time'  => sanitize_text_field($_POST['selected_time'] ?? '09:00'),
            'duration'       => $apt ? (int)$apt->duration : 60,
            'status'         => sanitize_key($_POST['status']  ?? 'confirmed'),
            'total_price'    => round(floatval($_POST['total_price'] ?? 0), 2),
            'notes'          => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
            'form_data'      => '{}',
            'created_at'     => current_time('mysql'),
        ];

        return Booking_Repository::insert($data);
    }

    private static function handle_csv_import() : string {
        if ( empty($_FILES['csv_file']['tmp_name']) ) return 'No file uploaded.';
        $fp = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fp) return 'Could not open file.';

        $header  = null;
        $count   = 0;
        global $wpdb;
        while (($row = fgetcsv($fp)) !== false) {
            if (!$header) { $header = array_map('trim', $row); continue; }
            $mapped = array_combine($header, $row);
            if (empty($mapped['selected_date']) || empty($mapped['appointment_id'])) continue;
            $data = [
                'appointment_id' => absint($mapped['appointment_id'] ?? 0),
                'staff_id'       => absint($mapped['staff_id']       ?? 0),
                'user_id'        => absint($mapped['user_id']        ?? 0),
                'guest_name'     => sanitize_text_field($mapped['guest_name']  ?? ''),
                'guest_email'    => sanitize_email($mapped['guest_email']       ?? ''),
                'selected_date'  => sanitize_text_field($mapped['selected_date'] ?? ''),
                'selected_time'  => sanitize_text_field($mapped['selected_time'] ?? '09:00'),
                'status'         => sanitize_key($mapped['status']   ?? 'confirmed'),
                'total_price'    => round(floatval($mapped['total_price'] ?? 0), 2),
                'duration'       => absint($mapped['duration']        ?? 60),
                'notes'          => sanitize_textarea_field($mapped['notes'] ?? ''),
                'form_data'      => '{}',
                'created_at'     => current_time('mysql'),
            ];
            $wpdb->insert($wpdb->prefix.'credoq_bookings', $data);
            $count++;
        }
        fclose($fp);
        return "Imported {$count} booking(s) successfully.";
    }

    private static function export_csv() : void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT b.*, a.title AS apt_title, s.display_name AS staff_name
             FROM {$wpdb->prefix}credoq_bookings b
             LEFT JOIN {$wpdb->prefix}credoq_appointments a ON b.appointment_id=a.id
             LEFT JOIN {$wpdb->prefix}credoq_staff s ON b.staff_id=s.id
             ORDER BY b.id DESC"
        );
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="credoq-bookings-'.gmdate('Y-m-d').'.csv"');
        $out = fopen('php://output','w');
        fputcsv($out, ['id','appointment_id','apt_title','staff_id','staff_name','user_id','guest_name','guest_email','selected_date','selected_time','duration','status','total_price','credit_deducted','wc_order_id','group_id','notes','created_at']);
        foreach ($rows as $r) {
            fputcsv($out, [$r->id,$r->appointment_id,$r->apt_title,$r->staff_id,$r->staff_name,$r->user_id,$r->guest_name,$r->guest_email,$r->selected_date,$r->selected_time,$r->duration,$r->status,$r->total_price,$r->credit_deducted,$r->wc_order_id,$r->group_id,$r->notes??'',$r->created_at]);
        }
        fclose($out);
    }

    private static function page_header( string $view, int $count ) : void {
        $is_list = $view === 'list';
        $is_cal  = $view === 'calendar';
        $list_url = admin_url('admin.php?page=credoq-bookings');
        $cal_url  = admin_url('admin.php?page=credoq-bookings&action=calendar');
        ?>
        <div class="credoq-page-header"><div class="credoq-page-header-inner">
            <h1 class="credoq-page-title">
                <span class="dashicons dashicons-calendar-alt" style="font-size:26px;margin-right:8px;color:#4f46e5;"></span>
                Bookings
            </h1>
            <div style="display:flex;gap:8px;align-items:center;">
                <span style="font-size:12px;color:#64748b;"><?php echo (int)$count; ?> record(s)</span>
                <a href="<?php echo esc_url($list_url); ?>" class="button<?php echo $is_list?' button-primary':''; ?>">☰ List</a>
                <a href="<?php echo esc_url($cal_url); ?>"  class="button<?php echo $is_cal ?' button-primary':''; ?>">📅 Calendar</a>
                <a href="<?php echo esc_url(add_query_arg(['page'=>'credoq-bookings','action'=>'add'],admin_url('admin.php'))); ?>" class="button button-primary">+ New</a>
            </div>
        </div></div>
        <?php
    }

    private static function notices() : void {
        if (!empty($_GET['done'])) { $a=sanitize_key($_GET['done']); echo "<div class='notice notice-success is-dismissible'><p>Booking ".esc_html($a)."d.</p></div>"; }
        if (!empty($_GET['bulk_done'])) echo "<div class='notice notice-success is-dismissible'><p>Bulk action completed.</p></div>";
        if (!empty($_GET['saved']))     echo "<div class='notice notice-success is-dismissible'><p>Saved.</p></div>";
    }

    private static function shared_styles() : void { ?>
        <style>
        .cq-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 12px;border-radius:99px;font-size:12px;font-weight:700;background:#f1f5f9;color:#64748b;text-decoration:none;border:1.5px solid transparent;}
        .cq-chip span{opacity:.7;}
        .cq-chip:hover{background:#e2e8f0;}
        .cq-chip-active{border-color:currentColor;}
        </style>
    <?php }
}
