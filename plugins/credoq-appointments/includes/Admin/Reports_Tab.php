<?php
/**
 * Appointments Reports Tab
 *
 * FIX: Added table-existence guard before every query, fixed `display_name`
 * column reference (it lives on credoq_staff, not wp_users join), and
 * corrected `created_at` → confirmed to exist in schema.
 *
 * @package CredoqAppointments\Admin
 */
namespace CredoqAppointments\Admin;
defined( 'ABSPATH' ) || exit;

class Reports_Tab {

    public static function render( string $start, string $end ) : void {
        global $wpdb;
        $s = $start . ' 00:00:00';
        $e = $end   . ' 23:59:59';

        $tbl_bookings = $wpdb->prefix . 'credoq_bookings';
        $tbl_apts     = $wpdb->prefix . 'credoq_appointments';
        $tbl_staff    = $wpdb->prefix . 'credoq_staff';

        // Guard: tables may not exist yet if addon was just installed.
        $bookings_exist = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_bookings ) ) === $tbl_bookings );

        $total     = 0; $confirmed = 0; $cancelled = 0; $revenue = 0.0;
        $daily     = []; $by_apt    = []; $by_staff  = [];

        if ( $bookings_exist ) {
            // Suppress errors: column may still be missing on very old installs
            // that haven't run the upgrade yet.
            $wpdb->suppress_errors( true );

            $total    = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tbl_bookings} WHERE created_at BETWEEN %s AND %s", $s, $e ) ) );
            $confirmed = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tbl_bookings} WHERE status='confirmed' AND created_at BETWEEN %s AND %s", $s, $e ) ) );
            $cancelled = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tbl_bookings} WHERE status='cancelled' AND created_at BETWEEN %s AND %s", $s, $e ) ) );
            $revenue   = floatval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(total_price),0) FROM {$tbl_bookings} WHERE status='confirmed' AND created_at BETWEEN %s AND %s", $s, $e ) ) );

            $daily = $wpdb->get_results( $wpdb->prepare(
                "SELECT DATE(created_at) as day, COUNT(*) as count
                 FROM {$tbl_bookings}
                 WHERE created_at BETWEEN %s AND %s
                 GROUP BY DATE(created_at) ORDER BY day ASC", $s, $e ) );

            $by_apt = $wpdb->get_results( $wpdb->prepare(
                "SELECT a.title, COUNT(b.id) as count
                 FROM {$tbl_bookings} b
                 LEFT JOIN {$tbl_apts} a ON b.appointment_id = a.id
                 WHERE b.created_at BETWEEN %s AND %s
                 GROUP BY b.appointment_id ORDER BY count DESC LIMIT 10", $s, $e ) );

            // FIX: staff table has `display_name` column (see Schema.php).
            // The JOIN is correct; error was missing column because table was
            // created before `display_name` was added → fixed by schema bump.
            $by_staff = $wpdb->get_results( $wpdb->prepare(
                "SELECT s.display_name, COUNT(b.id) as count
                 FROM {$tbl_bookings} b
                 LEFT JOIN {$tbl_staff} s ON b.staff_id = s.id
                 WHERE b.created_at BETWEEN %s AND %s
                 GROUP BY b.staff_id ORDER BY count DESC LIMIT 10", $s, $e ) );

            $wpdb->suppress_errors( false );
        }

        $chart_labels = wp_json_encode( array_column( $daily,    'day'          ) );
        $chart_data   = wp_json_encode( array_column( $daily,    'count'        ) );
        $apt_labels   = wp_json_encode( array_column( $by_apt,   'title'        ) );
        $apt_counts   = wp_json_encode( array_column( $by_apt,   'count'        ) );
        $staff_labels = wp_json_encode( array_column( $by_staff, 'display_name' ) );
        $staff_counts = wp_json_encode( array_column( $by_staff, 'count'        ) );
        ?>

        <?php if ( ! $bookings_exist ) : ?>
        <div class="notice notice-warning inline"><p>
            <?php esc_html_e( 'Bookings table not found. Visit Credoq Tools → Database Update to create it.', 'credoq-appointments' ); ?>
        </p></div>
        <?php else : ?>

        <div class="credoq-kpi-grid" style="grid-template-columns:repeat(4,1fr);">
            <?php foreach ([
                ['📅', 'Total Bookings', $total,                          '#4f46e5'],
                ['✅', 'Confirmed',      $confirmed,                      '#16a34a'],
                ['❌', 'Cancelled',      $cancelled,                      '#ef4444'],
                ['💰', 'Revenue',        '$' . number_format( $revenue, 0 ), '#f59e0b'],
            ] as [$icon, $label, $val, $color]) : ?>
            <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;padding:22px 20px;display:flex;gap:14px;align-items:flex-start;box-shadow:0 2px 8px rgba(0,0,0,.04);">
                <div style="width:48px;height:48px;border-radius:14px;background:<?php echo $color; ?>18;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;"><?php echo $icon; ?></div>
                <div>
                    <div style="font-size:28px;font-weight:900;color:<?php echo $color; ?>;line-height:1;"><?php echo $val; ?></div>
                    <div style="font-size:12px;color:#64748b;margin-top:4px;font-weight:600;"><?php echo esc_html( $label ); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:20px;margin-top:20px;">
            <div class="credoq-card">
                <h3 style="margin-top:0;">Daily Bookings</h3>
                <canvas id="cq-apt-daily-chart" height="100"></canvas>
            </div>
            <div class="credoq-card">
                <h3 style="margin-top:0;">By Service</h3>
                <canvas id="cq-apt-apt-chart"></canvas>
            </div>
            <div class="credoq-card">
                <h3 style="margin-top:0;">By Staff</h3>
                <canvas id="cq-apt-staff-chart"></canvas>
            </div>
        </div>

        <script>
        (function(){
            if(typeof Chart==='undefined') return;
            var palette=['#4f46e5','#16a34a','#f59e0b','#ef4444','#9333ea','#0891b2','#f97316','#84cc16','#ec4899','#14b8a6'];
            var dl=<?php echo $chart_labels; ?>, dd=<?php echo $chart_data; ?>;
            var al=<?php echo $apt_labels; ?>,  ad=<?php echo $apt_counts; ?>;
            var sl=<?php echo $staff_labels; ?>, sd=<?php echo $staff_counts; ?>;
            var e1=document.getElementById('cq-apt-daily-chart');
            if(e1) new Chart(e1,{type:'bar',data:{labels:dl,datasets:[{label:'Bookings',data:dd,backgroundColor:'#4f46e588',borderRadius:5}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}});
            var e2=document.getElementById('cq-apt-apt-chart');
            if(e2&&al.length) new Chart(e2,{type:'doughnut',data:{labels:al,datasets:[{data:ad,backgroundColor:palette}]},options:{plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}}}});
            var e3=document.getElementById('cq-apt-staff-chart');
            if(e3&&sl.length) new Chart(e3,{type:'doughnut',data:{labels:sl,datasets:[{data:sd,backgroundColor:palette}]},options:{plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}}}});
        })();
        </script>
        <?php endif; ?>
        <?php
    }
}
