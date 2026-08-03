<?php
namespace CredoqAppointments\Admin;
defined( 'ABSPATH' ) || exit;

use CredoqAppointments\Staff_Repository;

class Staff_Page {

    public static function render() : void {
        if ( ! current_user_can('manage_options') ) wp_die('Insufficient permissions.');

        if ( isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce']??'','credoq_del_staff') ) {
            Staff_Repository::delete(absint($_GET['delete']));
            echo '<div class="notice notice-success is-dismissible"><p>Staff member deleted.</p></div>';
        }

        if ( isset($_POST['submit']) && check_admin_referer('credoq_save_staff') ) {
            self::handle_save();
        }

        $editing = isset($_GET['edit']);
        $staff   = null;
        if ( $editing ) {
            $id    = absint($_GET['edit']);
            $staff = $id ? Staff_Repository::find($id) : null;
            if ( ! $staff ) $staff = (object)[
                'id'=>0,'user_id'=>0,'display_name'=>'','email'=>'','bio'=>'',
                'avatar_url'=>'','availability'=>'{}','special_dates'=>'[]','price_multiplier'=>1.00,
            ];
        }
        ?>
        <div class="wrap credoq-admin-wrap">
        <div class="credoq-page-header">
            <div class="credoq-page-header-inner">
                <h1 class="credoq-page-title">
                    <span class="dashicons dashicons-businessperson" style="font-size:28px;margin-right:8px;color:#4f46e5;"></span>
                    Staff
                </h1>
                <?php if ( ! $editing ) : ?>
                <a href="<?php echo esc_url(add_query_arg('edit','0',admin_url('admin.php?page=credoq-staff'))); ?>"
                   class="button button-primary">+ Add Staff</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        if ($editing) self::render_edit_form($staff);
        else          self::render_list();
        echo '</div>';
    }

    private static function handle_save() : void {
        $avail = [];
        foreach (['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $d) {
            $avail[$d] = [
                'closed' => empty($_POST["avail_{$d}_enabled"]),
                'hours'  => [],
            ];
            if ( ! empty($_POST["avail_{$d}_enabled"]) ) {
                $starts      = (array)($_POST["avail_{$d}_start"]??[]);
                $ends        = (array)($_POST["avail_{$d}_end"]??[]);
                $break_starts= (array)($_POST["avail_{$d}_break_start"]??[]);
                $break_ends  = (array)($_POST["avail_{$d}_break_end"]??[]);
                foreach ($starts as $i => $s) {
                    if (!empty($s) && !empty($ends[$i])) {
                        $hour = ['start'=>sanitize_text_field($s),'end'=>sanitize_text_field($ends[$i])];
                        if (!empty($break_starts[$i])) {
                            $hour['break_start'] = sanitize_text_field($break_starts[$i]);
                            $hour['break_end']   = sanitize_text_field($break_ends[$i]??'');
                        }
                        $avail[$d]['hours'][] = $hour;
                    }
                }
            }
        }
        // Special dates
        $special = [];
        $sp_dates  = (array)($_POST['sp_date']  ?? []);
        $sp_closed = (array)($_POST['sp_closed'] ?? []);
        $sp_start  = (array)($_POST['sp_start']  ?? []);
        $sp_end    = (array)($_POST['sp_end']    ?? []);
        $sp_price  = (array)($_POST['sp_price']  ?? []);
        $sp_note   = (array)($_POST['sp_note']   ?? []);
        foreach ($sp_dates as $i => $date) {
            if (!$date) continue;
            $row = ['date'=>sanitize_text_field($date),'closed'=>!empty($sp_closed[$i])];
            if (empty($sp_closed[$i]) && !empty($sp_start[$i])) {
                $row['hours'] = [['start'=>sanitize_text_field($sp_start[$i]),'end'=>sanitize_text_field($sp_end[$i]??'')]];
            }
            if (isset($sp_price[$i]) && $sp_price[$i] !== '') {
                $row['price'] = round(floatval($sp_price[$i]), 2);
            }
            if (!empty($sp_note[$i])) {
                $row['note'] = sanitize_text_field($sp_note[$i]);
            }
            $special[] = $row;
        }
        $data = [
            'id'              => absint($_POST['staff_id']),
            'user_id'         => absint($_POST['user_id']??0),
            'display_name'    => sanitize_text_field($_POST['display_name']??''),
            'email'           => sanitize_email($_POST['email']??''),
            'bio'             => wp_kses_post($_POST['bio']??''),
            'avatar_url'      => esc_url_raw($_POST['avatar_url']??''),
            'availability'    => $avail,
            'special_dates'   => $special,
            'price_multiplier'=> floatval($_POST['price_multiplier']??1),
        ];
        Staff_Repository::save($data);
        echo '<div class="notice notice-success is-dismissible"><p>Staff member saved.</p></div>';
    }

    private static function render_list() : void {
        $all = Staff_Repository::all();
        ?>
        <div class="credoq-card">
        <table class="wp-list-table widefat fixed striped credoq-table">
            <thead><tr>
                <th>#</th><th>Name</th><th>Email</th><th>Price Multiplier</th><th>Linked User</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php if (empty($all)) : ?>
                <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">No staff members yet.</td></tr>
            <?php else: foreach ($all as $s) :
                $del = wp_nonce_url(add_query_arg(['delete'=>$s->id],admin_url('admin.php?page=credoq-staff')),'credoq_del_staff');
            ?>
                <tr>
                    <td><code>#<?php echo intval($s->id); ?></code></td>
                    <td><strong><?php echo esc_html($s->display_name); ?></strong></td>
                    <td><?php echo esc_html($s->email); ?></td>
                    <td><?php echo floatval($s->price_multiplier); ?>×</td>
                    <td><?php echo $s->user_id ? '#'.intval($s->user_id) : '—'; ?></td>
                    <td>
                        <a href="<?php echo esc_url(add_query_arg('edit',$s->id,admin_url('admin.php?page=credoq-staff'))); ?>" class="button button-small">Edit</a>
                        <a href="<?php echo esc_url($del); ?>" class="button button-small button-link-delete" onclick="return confirm('Delete this staff member?');">Delete</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    private static function render_edit_form( object $staff ) : void {
        $avail   = json_decode($staff->availability??'{}', true) ?: [];
        $special = json_decode($staff->special_dates??'[]', true) ?: [];
        $days    = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        $all_users = get_users(['fields'=>['ID','display_name','user_email'],'number'=>200]);
        ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=credoq-staff')); ?>" class="button" style="margin-bottom:16px;">&larr; Back</a>
        <form method="post">
        <?php wp_nonce_field('credoq_save_staff'); ?>
        <input type="hidden" name="staff_id" value="<?php echo intval($staff->id); ?>">

        <div class="credoq-settings-grid">
        <div class="credoq-card">
            <h2 class="credoq-section-title">Staff Info</h2>
            <table class="form-table">
                <tr><th>Display Name</th><td><input type="text" name="display_name" value="<?php echo esc_attr($staff->display_name); ?>" class="regular-text" required></td></tr>
                <tr><th>Email</th><td><input type="email" name="email" value="<?php echo esc_attr($staff->email); ?>" class="regular-text"></td></tr>
                <tr><th>Avatar URL</th><td><input type="url" name="avatar_url" value="<?php echo esc_attr($staff->avatar_url??''); ?>" class="regular-text"></td></tr>
                <tr><th>Price Multiplier</th><td><input type="number" step="0.01" name="price_multiplier" value="<?php echo floatval($staff->price_multiplier??1); ?>" class="small-text" min="0.1" max="10"><p class="description">1.0 = base price. 1.5 = +50%</p></td></tr>
                <tr><th>Linked WP User</th><td>
                    <select name="user_id">
                        <option value="0">— None —</option>
                        <?php foreach ($all_users as $u) : ?>
                        <option value="<?php echo intval($u->ID); ?>" <?php selected(intval($staff->user_id), intval($u->ID)); ?>>
                            <?php echo esc_html($u->display_name.' ('.$u->user_email.')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td></tr>
                <tr><th>Bio</th><td><?php wp_editor($staff->bio??'','staff_bio',['textarea_name'=>'bio','media_buttons'=>false,'textarea_rows'=>4]); ?></td></tr>
            </table>
        </div>

        <div class="credoq-card">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
                <div style="background:#10b981;border-radius:10px;width:44px;height:44px;display:flex;align-items:center;justify-content:center;">
                    <span class="dashicons dashicons-list-view" style="color:#fff;font-size:20px;width:20px;height:20px;"></span>
                </div>
                <div>
                    <h2 class="credoq-section-title" style="margin:0 0 2px;">Weekly Schedule</h2>
                    <div style="font-size:13px;color:#64748b;">Working hours and breaks per day</div>
                </div>
            </div>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:2px solid #f1f5f9;">
                        <th style="text-align:left;padding:8px 10px;font-size:12px;color:#64748b;font-weight:600;width:100px;">Day</th>
                        <th style="text-align:left;padding:8px 10px;font-size:12px;color:#64748b;font-weight:600;width:80px;">Working</th>
                        <th style="text-align:left;padding:8px 10px;font-size:12px;color:#64748b;font-weight:600;">Start</th>
                        <th style="text-align:left;padding:8px 10px;font-size:12px;color:#64748b;font-weight:600;">End</th>
                        <th style="text-align:left;padding:8px 10px;font-size:12px;color:#64748b;font-weight:600;">Break Start</th>
                        <th style="text-align:left;padding:8px 10px;font-size:12px;color:#64748b;font-weight:600;">Break End</th>
                    </tr>
                </thead>
                <tbody>
            <?php foreach ($days as $day) :
                $dd          = $avail[$day] ?? ['closed'=>true,'hours'=>[]];
                $enabled     = empty($dd['closed']);
                $hours       = $dd['hours'] ?: [['start'=>'09:00','end'=>'17:00','break_start'=>'','break_end'=>'']];
                $h           = $hours[0];
                $opacity     = $enabled ? '1' : '0.4';
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px 10px;font-size:14px;color:<?php echo $enabled ? '#1e293b' : '#94a3b8'; ?>;font-weight:<?php echo $enabled ? '600' : '400'; ?>;">
                        <?php echo ucfirst($day); ?>
                    </td>
                    <td style="padding:12px 10px;">
                        <input type="checkbox"
                               name="avail_<?php echo $day; ?>_enabled"
                               value="1"
                               <?php checked($enabled); ?>
                               style="position:absolute;opacity:0;width:0;height:0;">
                        <span class="cq-day-toggle-track"
                              data-day="<?php echo esc_attr($day); ?>"
                              style="display:inline-block;width:40px;height:22px;border-radius:11px;position:relative;cursor:pointer;background:<?php echo $enabled ? '#10b981' : '#cbd5e1'; ?>;transition:background .2s;">
                            <span class="cq-day-toggle-knob" style="position:absolute;top:3px;left:<?php echo $enabled ? '21px' : '3px'; ?>;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:left .2s;"></span>
                        </span>
                    </td>
                    <td style="padding:12px 10px;opacity:<?php echo $opacity; ?>">
                        <input type="time" name="avail_<?php echo $day; ?>_start[]"
                               value="<?php echo esc_attr($h['start']??'09:00'); ?>"
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:110px;">
                    </td>
                    <td style="padding:12px 10px;opacity:<?php echo $opacity; ?>">
                        <input type="time" name="avail_<?php echo $day; ?>_end[]"
                               value="<?php echo esc_attr($h['end']??'17:00'); ?>"
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:110px;">
                    </td>
                    <td style="padding:12px 10px;opacity:<?php echo $opacity; ?>">
                        <input type="time" name="avail_<?php echo $day; ?>_break_start[]"
                               value="<?php echo esc_attr($h['break_start']??''); ?>"
                               placeholder="--:--"
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:110px;">
                    </td>
                    <td style="padding:12px 10px;opacity:<?php echo $opacity; ?>">
                        <input type="time" name="avail_<?php echo $day; ?>_break_end[]"
                               value="<?php echo esc_attr($h['break_end']??''); ?>"
                               placeholder="--:--"
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:110px;">
                    </td>
                </tr>
            <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="credoq-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="background:#7c3aed;border-radius:10px;width:44px;height:44px;display:flex;align-items:center;justify-content:center;">
                        <span class="dashicons dashicons-tag" style="color:#fff;font-size:20px;width:20px;height:20px;"></span>
                    </div>
                    <div>
                        <h2 class="credoq-section-title" style="margin:0 0 2px;">Special Dates &amp; Holidays</h2>
                        <div style="font-size:13px;color:#64748b;">Holidays, custom hours, or custom price</div>
                    </div>
                </div>
                <button type="button" onclick="credoqAddSpecialDate()" class="button button-primary" style="border-radius:20px;padding:6px 18px;">
                    + Add Date
                </button>
            </div>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="text-align:left;padding:10px 12px;font-size:11px;color:#475569;font-weight:700;letter-spacing:.05em;text-transform:uppercase;">DATE</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;color:#475569;font-weight:700;letter-spacing:.05em;text-transform:uppercase;">WORKING?</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;color:#475569;font-weight:700;letter-spacing:.05em;text-transform:uppercase;">START</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;color:#475569;font-weight:700;letter-spacing:.05em;text-transform:uppercase;">END</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;color:#475569;font-weight:700;letter-spacing:.05em;text-transform:uppercase;">SPECIAL DATE PRICE</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;color:#475569;font-weight:700;letter-spacing:.05em;text-transform:uppercase;">NOTE</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="cq-special-dates">
            <?php foreach ($special as $i => $sd) :
                $is_closed = !empty($sd['closed']);
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:10px 12px;">
                        <input type="date" name="sp_date[]" value="<?php echo esc_attr($sd['date']??''); ?>"
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
                    </td>
                    <td style="padding:10px 12px;">
                        <select name="sp_closed[]" onchange="credoqSpToggle(this)"
                                style="padding:7px 12px;border:2px solid <?php echo $is_closed ? '#e2e8f0' : '#10b981'; ?>;border-radius:8px;font-size:13px;color:<?php echo $is_closed ? '#94a3b8' : '#10b981'; ?>;font-weight:600;">
                            <option value=""  <?php selected(!$is_closed); ?>>Working</option>
                            <option value="1" <?php selected($is_closed); ?>>Closed</option>
                        </select>
                    </td>
                    <td style="padding:10px 12px;">
                        <input type="time" name="sp_start[]" value="<?php echo esc_attr($sd['hours'][0]['start']??''); ?>"
                               <?php echo $is_closed ? 'disabled' : ''; ?>
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;<?php echo $is_closed ? 'opacity:.35;' : ''; ?>">
                    </td>
                    <td style="padding:10px 12px;">
                        <input type="time" name="sp_end[]" value="<?php echo esc_attr($sd['hours'][0]['end']??''); ?>"
                               <?php echo $is_closed ? 'disabled' : ''; ?>
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;<?php echo $is_closed ? 'opacity:.35;' : ''; ?>">
                    </td>
                    <td style="padding:10px 12px;">
                        <input type="number" name="sp_price[]" value="<?php echo isset($sd['price']) ? esc_attr($sd['price']) : ''; ?>"
                               step="0.01" min="0" placeholder="1.00"
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;width:110px;">
                    </td>
                    <td style="padding:10px 12px;">
                        <input type="text" name="sp_note[]" value="<?php echo esc_attr($sd['note']??''); ?>"
                               placeholder="Optional"
                               style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;width:130px;">
                    </td>
                    <td style="padding:10px 12px;">
                        <button type="button" onclick="this.closest('tr').remove()"
                                style="background:#fef2f2;color:#ef4444;border:1px solid #fecaca;border-radius:8px;padding:6px 14px;font-size:13px;cursor:pointer;font-weight:600;">
                            delete
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </div>

        <p class="submit">
            <input type="submit" name="submit" class="button button-primary button-large"
                   value="<?php echo $staff->id ? 'Update Staff' : 'Create Staff'; ?>">
        </p>
        </form>
        <script>
        // ── Weekly schedule toggle ──────────────────────────────────────
        // Pure JS toggle: clicking the visual span directly flips the
        // hidden checkbox AND updates all row visuals without relying on
        // the browser's synthetic .click() / onchange on a hidden input
        // (which is unreliable across browsers when display:none).
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.cq-day-toggle-track').forEach(function (track) {
                track.addEventListener('click', function () {
                    var day = this.dataset.day;
                    var cb  = document.querySelector('input[name="avail_' + day + '_enabled"]');
                    if (!cb) return;
                    cb.checked = !cb.checked;
                    credoqToggleDay(cb, day);
                });
            });
        });

        function credoqToggleDay(cb, day) {
            var on   = cb.checked;
            var row  = cb.closest('tr');
            var track = row.querySelector('.cq-day-toggle-track');
            var knob  = track ? track.querySelector('.cq-day-toggle-knob') : null;
            if (track) track.style.background = on ? '#10b981' : '#cbd5e1';
            if (knob)  knob.style.left = on ? '21px' : '3px';
            row.cells[0].style.color      = on ? '#1e293b' : '#94a3b8';
            row.cells[0].style.fontWeight = on ? '600' : '400';
            [2,3,4,5].forEach(function(ci){ row.cells[ci].style.opacity = on ? '1' : '0.4'; });
        }

        // Special Dates: Working/Closed select colours + enable/disable time inputs.
        function credoqSpToggle(sel) {
            var isClosed = sel.value === '1';
            sel.style.borderColor = isClosed ? '#e2e8f0' : '#10b981';
            sel.style.color       = isClosed ? '#94a3b8' : '#10b981';
            var row = sel.closest('tr');
            ['sp_start[]','sp_end[]'].forEach(function(name){
                var inp = row.querySelector('input[name="'+name+'"]');
                if (!inp) return;
                inp.disabled = isClosed;
                inp.style.opacity = isClosed ? '0.35' : '1';
            });
        }

        // Add a new Special Date row.
        function credoqAddSpecialDate() {
            var row = '<tr style="border-bottom:1px solid #f1f5f9;">'
                + '<td style="padding:10px 12px;"><input type="date" name="sp_date[]" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;"></td>'
                + '<td style="padding:10px 12px;"><select name="sp_closed[]" onchange="credoqSpToggle(this)" style="padding:7px 12px;border:2px solid #10b981;border-radius:8px;font-size:13px;color:#10b981;font-weight:600;">'
                +     '<option value="">Working</option><option value="1">Closed</option>'
                + '</select></td>'
                + '<td style="padding:10px 12px;"><input type="time" name="sp_start[]" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;"></td>'
                + '<td style="padding:10px 12px;"><input type="time" name="sp_end[]" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;"></td>'
                + '<td style="padding:10px 12px;"><input type="number" name="sp_price[]" step="0.01" min="0" placeholder="1.00" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;width:110px;"></td>'
                + '<td style="padding:10px 12px;"><input type="text" name="sp_note[]" placeholder="Optional" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;width:130px;"></td>'
                + '<td style="padding:10px 12px;"><button type="button" onclick="this.closest(\'tr\').remove()" style="background:#fef2f2;color:#ef4444;border:1px solid #fecaca;border-radius:8px;padding:6px 14px;font-size:13px;cursor:pointer;font-weight:600;">delete</button></td>'
                + '</tr>';
            document.getElementById('cq-special-dates').insertAdjacentHTML('beforeend', row);
        }
        </script>
        <?php
    }
}
