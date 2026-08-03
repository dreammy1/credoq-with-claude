<?php
/**
 * Events Reports Tab
 *
 * FIX: Added table-existence guard. Fixed eb.quantity reference — `quantity`
 * IS a column on credoq_event_bookings (Schema.php line confirms it), but the
 * table may have been created before the column was added. Schema bump forces
 * dbDelta to add it. Also fixed eb.created_at alias ambiguity.
 *
 * @package CredoqEvents\Admin
 */
namespace CredoqEvents\Admin;
defined( 'ABSPATH' ) || exit;

class Reports_Tab {

    public static function render( string $start, string $end ) : void {
        global $wpdb;
        $s = $start . ' 00:00:00';
        $e = $end   . ' 23:59:59';

        $tbl_eb     = $wpdb->prefix . 'credoq_event_bookings';
        $tbl_events = $wpdb->prefix . 'credoq_events';

        $eb_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_eb ) ) === $tbl_eb );

        $total = 0; $confirmed = 0; $revenue = 0.0; $top_events = [];

        if ( $eb_exists ) {
            $wpdb->suppress_errors( true );

            $total     = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tbl_eb} WHERE created_at BETWEEN %s AND %s", $s, $e ) ) );
            $confirmed = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tbl_eb} WHERE status='confirmed' AND created_at BETWEEN %s AND %s", $s, $e ) ) );

            // FIX: use explicit table alias for `created_at` to avoid ambiguity,
            // and qualify `quantity` on the bookings table side.
            $revenue = floatval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(e.price * eb.quantity), 0)
                 FROM {$tbl_eb} eb
                 JOIN {$tbl_events} e ON eb.event_id = e.id
                 WHERE eb.status = 'confirmed'
                   AND eb.created_at BETWEEN %s AND %s", $s, $e ) ) );

            $top_events = $wpdb->get_results( $wpdb->prepare(
                "SELECT e.title, COUNT(eb.id) as count
                 FROM {$tbl_eb} eb
                 LEFT JOIN {$tbl_events} e ON eb.event_id = e.id
                 WHERE eb.created_at BETWEEN %s AND %s
                 GROUP BY eb.event_id ORDER BY count DESC LIMIT 8", $s, $e ) );

            $wpdb->suppress_errors( false );
        }

        $el = wp_json_encode( array_column( $top_events, 'title' ) );
        $ed = wp_json_encode( array_column( $top_events, 'count' ) );
        ?>

        <?php if ( ! $eb_exists ) : ?>
        <div class="notice notice-warning inline"><p>
            <?php esc_html_e( 'Event bookings table not found. Visit Credoq Tools → Database Update to create it.', 'credoq-events' ); ?>
        </p></div>
        <?php else : ?>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">
            <?php foreach ( [
                ['🎟', 'Registrations', $total,                             '#9333ea'],
                ['✅', 'Confirmed',     $confirmed,                         '#16a34a'],
                ['💰', 'Revenue',       '$' . number_format( $revenue, 0 ), '#f59e0b'],
            ] as [$ic, $lb, $vl, $col] ) : ?>
            <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;padding:22px 20px;display:flex;gap:14px;align-items:flex-start;box-shadow:0 2px 8px rgba(0,0,0,.04);">
                <div style="width:48px;height:48px;border-radius:14px;background:<?php echo $col; ?>18;display:flex;align-items:center;justify-content:center;font-size:24px;"><?php echo $ic; ?></div>
                <div>
                    <div style="font-size:28px;font-weight:900;color:<?php echo $col; ?>;line-height:1;"><?php echo $vl; ?></div>
                    <div style="font-size:12px;color:#64748b;margin-top:4px;font-weight:600;"><?php echo esc_html( $lb ); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="credoq-card">
            <h3 style="margin-top:0;">Top Events</h3>
            <?php if ( empty( $top_events ) ) : ?>
            <p style="color:#64748b;">No event registrations in this period.</p>
            <?php else : ?>
            <canvas id="cq-ev-chart" height="80"></canvas>
            <script>
            (function(){
                if(typeof Chart==='undefined') return;
                var el=document.getElementById('cq-ev-chart');
                if(!el) return;
                new Chart(el,{
                    type:'bar',
                    data:{labels:<?php echo $el; ?>,datasets:[{label:'Registrations',data:<?php echo $ed; ?>,backgroundColor:'#9333ea88',borderRadius:5}]},
                    options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}
                });
            })();
            </script>
            <?php endif; ?>
        </div>

        <?php endif; ?>
        <?php
    }
}
