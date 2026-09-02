<?php
namespace CredoqEngine\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Unified Reports Page — all addon charts in one place.
 *
 * The Engine provides:
 *  - Date filter bar (Today / Week / Month / Year / Custom)
 *  - Tab header: Overview + one tab per installed addon
 *  - Chart.js is enqueued once
 *
 * Each addon registers its tab via the 'credoq_reports_tabs' filter:
 *
 *   add_filter( 'credoq_reports_tabs', function( $tabs ) {
 *       $tabs['my_addon'] = [
 *           'label'    => 'My Addon',
 *           'icon'     => 'dashicons-calendar-alt',  // dashicons class
 *           'callback' => [ My_Reports_Tab::class, 'render' ],
 *           // render( string $start_date, string $end_date ) : void
 *       ];
 *       return $tabs;
 *   });
 */
class Reports_Page {

    public static function render() : void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        // ── Enqueue Chart.js ──────────────────────────────────────────
        wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [], '4.4.0', true );

        // ── Date filter ───────────────────────────────────────────────
        $filter     = sanitize_text_field( $_GET['filter']     ?? 'month' );
        $start_date = sanitize_text_field( $_GET['start_date'] ?? '' );
        $end_date   = sanitize_text_field( $_GET['end_date']   ?? '' );

        [ 'start' => $start, 'end' => $end ] = self::resolve_date_range( $filter, $start_date, $end_date );

        $filter_labels = [
            'day'    => 'Today',
            'week'   => 'This Week',
            'month'  => 'This Month',
            'year'   => 'This Year',
            'custom' => 'Custom Range',
        ];

        // ── Active tab ────────────────────────────────────────────────
        $active_tab = sanitize_key( $_GET['tab'] ?? 'overview' );

        // ── Collect registered tabs from addons ───────────────────────
        $addon_tabs = apply_filters( 'credoq_reports_tabs', [] );

        ?>
        <div class="wrap credoq-admin-wrap">

        <!-- Page header -->
        <div class="credoq-page-header">
            <div class="credoq-page-header-inner">
                <h1 class="credoq-page-title">
                    <span class="dashicons dashicons-chart-bar" style="font-size:28px;margin-right:8px;color:#4f46e5;"></span>
                    <?php esc_html_e( 'Reports & Analytics', 'credoq-engine' ); ?>
                </h1>
                <?php if ( $filter === 'custom' && $start_date && $end_date ) : ?>
                <span class="credoq-badge credoq-badge-blue">
                    <?php echo esc_html( date_i18n( get_option('date_format'), strtotime($start) )
                        . ' — ' . date_i18n( get_option('date_format'), strtotime($end) ) ); ?>
                </span>
                <?php else : ?>
                <span class="credoq-badge credoq-badge-blue">
                    <?php echo esc_html( $filter_labels[$filter] ?? 'This Month' ); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Date filter bar -->
        <div class="credoq-card credoq-filter-card" style="margin-bottom:20px;">
            <form method="get" action="">
                <input type="hidden" name="page" value="credoq-reports">
                <input type="hidden" name="tab"  value="<?php echo esc_attr( $active_tab ); ?>">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span style="font-size:13px;font-weight:700;color:#475569;">Filter:</span>
                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                        <?php foreach ( $filter_labels as $val => $lbl ) : ?>
                        <button type="submit" name="filter" value="<?php echo esc_attr( $val ); ?>"
                                style="padding:6px 14px;border-radius:8px;border:1.5px solid;font-size:12px;font-weight:700;cursor:pointer;
                                       <?php echo $filter === $val
                                           ? 'background:#4f46e5;color:#fff;border-color:#4f46e5;'
                                           : 'background:#fff;color:#475569;border-color:#e2e8f0;'; ?>">
                            <?php echo esc_html( $lbl ); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php if ( $filter === 'custom' ) : ?>
                    <div style="display:flex;align-items:center;gap:8px;margin-left:12px;">
                        <input type="date" name="start_date" value="<?php echo esc_attr( $start_date ); ?>"
                               style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
                        <span style="color:#94a3b8;">—</span>
                        <input type="date" name="end_date" value="<?php echo esc_attr( $end_date ); ?>"
                               style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
                        <button type="submit" style="padding:6px 14px;background:#4f46e5;color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">Apply</button>
                    </div>
                    <?php endif; ?>
                    <!-- Export CSV -->
                    <div style="margin-left:auto;">
                        <a href="<?php echo esc_url( wp_nonce_url(
                            add_query_arg( [ 'page'=>'credoq-reports','export'=>'csv','tab'=>$active_tab,'filter'=>$filter,'start_date'=>$start,'end_date'=>$end ] ),
                            'credoq_export_report'
                        ) ); ?>" style="padding:6px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;font-weight:700;color:#475569;text-decoration:none;background:#fff;">
                            ↓ Export CSV
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Tab navigation -->
        <div class="credoq-reports-tabs" style="display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:24px;overflow-x:auto;">

            <?php // Overview is always first
            $all_tabs = array_merge( [ 'overview' => [
                'label' => __('Overview','credoq-engine'),
                'icon'  => 'dashicons-chart-area',
            ] ], $addon_tabs );

            foreach ( $all_tabs as $slug => $tab ) :
                $is_active = $active_tab === $slug;
                $tab_url = add_query_arg( [
                    'page'       => 'credoq-reports',
                    'tab'        => $slug,
                    'filter'     => $filter,
                    'start_date' => $start_date,
                    'end_date'   => $end_date,
                ] );
            ?>
            <a href="<?php echo esc_url( $tab_url ); ?>"
               style="display:flex;align-items:center;gap:6px;padding:10px 18px;font-size:13px;font-weight:700;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;
                      <?php echo $is_active
                          ? 'color:#4f46e5;border-bottom-color:#4f46e5;'
                          : 'color:#64748b;'; ?>">
                <?php if ( ! empty( $tab['icon'] ) ) : ?>
                <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
                <?php endif; ?>
                <?php echo esc_html( $tab['label'] ); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Tab content -->
        <div class="credoq-reports-content">
            <?php
            if ( $active_tab === 'overview' ) {
                self::render_overview( $start, $end );
            } elseif ( isset( $addon_tabs[ $active_tab ] ) ) {
                $cb = $addon_tabs[ $active_tab ]['callback'] ?? null;
                if ( $cb && is_callable( $cb ) ) {
                    call_user_func( $cb, $start, $end );
                } else {
                    echo '<div class="credoq-card"><p>This report tab has no renderer registered.</p></div>';
                }
            }
            ?>
        </div>

        </div><!-- .wrap -->
        <?php
    }

    /* ═══════════════════════════════════════════════════════════════════
       OVERVIEW TAB — aggregate KPIs from every installed addon
    ═══════════════════════════════════════════════════════════════════ */
    private static function render_overview( string $start, string $end ) : void {
        global $wpdb;
        $s = $start . ' 00:00:00';
        $e = $end   . ' 23:59:59';

        // Gather stats from each table if it exists
        $stats = [];
        $tables = [
            'members'     => [ 'credoq_user_memberships', 'purchase_date', 'Active Members' ],
            'bookings'    => [ 'credoq_bookings',          'created_at',   'Appointments'   ],
            'events'      => [ 'credoq_event_bookings',    'created_at',   'Event Bookings' ],
            'attendance'  => [ 'credoq_attendance',        'check_in_at',  'Check-ins'      ],
            'seats'       => [ 'credoq_seat_bookings',     'created_at',   'Seat Bookings'  ],
        ];
        $kpi_icons = [
            'members'    => '🏅',
            'bookings'   => '📅',
            'events'     => '🎟',
            'attendance' => '✅',
            'seats'      => '💺',
        ];

        $kpi_colors = [
            'members'    => '#4f46e5',
            'bookings'   => '#16a34a',
            'events'     => '#9333ea',
            'attendance' => '#f59e0b',
            'seats'      => '#0891b2',
        ];

        $chart_datasets = [];
        $chart_labels_set = false;
        $chart_labels = [];

        foreach ( $tables as $key => [ $table, $date_col, $label ] ) {
            $tbl = $wpdb->prefix . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) continue;

            // Suppress DB errors: table may exist but column was added in a later
            // schema version. The schema version bump forces dbDelta to add it.
            // Until the admin visits and upgrade runs, we show 0 gracefully.
            $wpdb->suppress_errors( true );

            $total = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tbl} WHERE {$date_col} BETWEEN %s AND %s AND status NOT IN ('cancelled','refunded','failed','pending_payment')", $s, $e ) ) );

            $daily = $wpdb->get_results( $wpdb->prepare(
                "SELECT DATE({$date_col}) as day, COUNT(*) as cnt
                 FROM {$tbl} WHERE {$date_col} BETWEEN %s AND %s AND status NOT IN ('cancelled','refunded','failed','pending_payment')
                 GROUP BY DATE({$date_col}) ORDER BY day ASC", $s, $e ) );

            $wpdb->suppress_errors( false );

            $day_map = [];
            foreach ( $daily as $d ) $day_map[ $d->day ] = intval( $d->cnt );

            // Build chart labels from first dataset
            if ( ! $chart_labels_set && ! empty( $daily ) ) {
                $cur = strtotime( $start );
                $end_ts = strtotime( $end );
                while ( $cur <= $end_ts ) {
                    $chart_labels[] = date( 'Y-m-d', $cur );
                    $cur += 86400;
                }
                $chart_labels_set = true;
            }

            $data_points = array_map( fn($l) => $day_map[$l] ?? 0, $chart_labels );

            $stats[ $key ] = [
                'label'  => $label,
                'total'  => $total,
                'icon'   => $kpi_icons[ $key ],
                'color'  => $kpi_colors[ $key ],
                'data'   => $data_points,
            ];

            $chart_datasets[] = [
                'label'           => $label,
                'data'            => $data_points,
                'borderColor'     => $kpi_colors[ $key ],
                'backgroundColor' => $kpi_colors[ $key ] . '22',
                'tension'         => 0.3,
                'fill'            => false,
                'pointRadius'     => 2,
            ];
        }

        ?>

        <!-- KPI Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px;">
            <?php foreach ( $stats as $key => $s_data ) : ?>
            <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;padding:22px 20px;display:flex;gap:14px;align-items:flex-start;box-shadow:0 2px 8px rgba(0,0,0,.04);">
                <div style="width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;background:<?php echo esc_attr($s_data['color']); ?>18;">
                    <?php echo $s_data['icon']; ?>
                </div>
                <div>
                    <div style="font-size:30px;font-weight:900;color:<?php echo esc_attr($s_data['color']); ?>;line-height:1;"><?php echo $s_data['total']; ?></div>
                    <div style="font-size:12px;color:#64748b;margin-top:4px;font-weight:600;"><?php echo esc_html($s_data['label']); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ( empty($stats) ) : ?>
            <div style="grid-column:1/-1;text-align:center;padding:48px;color:#94a3b8;">
                No addon data available yet. Install and activate Credoq addons to see analytics here.
            </div>
            <?php endif; ?>
        </div>

        <!-- Combined trend chart -->
        <?php if ( ! empty( $chart_datasets ) ) : ?>
        <div class="credoq-card" style="margin-bottom:28px;">
            <h3 style="margin:0 0 16px;font-size:15px;font-weight:800;color:#1e293b;">Activity Trend</h3>
            <canvas id="credoq-overview-chart" height="70"></canvas>
        </div>

        <!-- Per-addon mini charts row -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;">
            <?php foreach ( $stats as $key => $s_data ) : ?>
            <div class="credoq-card">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                    <span style="font-size:20px;"><?php echo $s_data['icon']; ?></span>
                    <h4 style="margin:0;font-size:13px;font-weight:800;color:#1e293b;"><?php echo esc_html($s_data['label']); ?></h4>
                    <span style="margin-left:auto;font-size:22px;font-weight:900;color:<?php echo esc_attr($s_data['color']); ?>;"><?php echo $s_data['total']; ?></span>
                </div>
                <canvas id="cq-mini-<?php echo esc_attr($key); ?>" height="60"></canvas>
            </div>
            <?php endforeach; ?>
        </div>

        <script>
        (function(){
            if(typeof Chart==='undefined') return;
            Chart.defaults.font.family = '-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif';
            Chart.defaults.font.size   = 11;

            var labels  = <?php echo wp_json_encode( $chart_labels ); ?>;
            var datasets = <?php echo wp_json_encode( $chart_datasets ); ?>;

            // Main trend chart
            var ctx = document.getElementById('credoq-overview-chart');
            if(ctx) {
                new Chart(ctx.getContext('2d'), {
                    type: 'line',
                    data: { labels: labels, datasets: datasets },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
                        scales: {
                            x: { grid: { color: '#f1f5f9' }, ticks: { maxTicksLimit: 10 } },
                            y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } }
                        }
                    }
                });
            }

            // Mini charts
            var allStats = <?php echo wp_json_encode( array_map( fn($s) => [
                'key'   => array_search($s, $stats),
                'color' => $s['color'],
                'data'  => $s['data'],
            ], $stats ) ); ?>;
            var statKeys = <?php echo wp_json_encode( array_keys( $stats ) ); ?>;
            statKeys.forEach(function(key, i) {
                var el = document.getElementById('cq-mini-' + key);
                if (!el) return;
                new Chart(el.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{ data: datasets[i] ? datasets[i].data : [], backgroundColor: datasets[i] ? datasets[i].borderColor + '88' : '#4f46e5', borderRadius: 4 }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { grid: { display: false }, ticks: { maxTicksLimit: 6 } },
                            y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f8fafc' } }
                        }
                    }
                });
            });
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    /* ═══════════════════════════════════════════════════════════════════
       DATE RANGE RESOLVER
    ═══════════════════════════════════════════════════════════════════ */
    private static function resolve_date_range( string $filter, string $custom_start, string $custom_end ) : array {
        $today = wp_date( 'Y-m-d' );
        switch ( $filter ) {
            case 'day':
                return [ 'start' => $today, 'end' => $today ];
            case 'week':
                return [
                    'start' => wp_date( 'Y-m-d', strtotime( 'monday this week' ) ),
                    'end'   => wp_date( 'Y-m-d', strtotime( 'sunday this week' ) ),
                ];
            case 'year':
                return [
                    'start' => wp_date( 'Y-01-01' ),
                    'end'   => wp_date( 'Y-12-31' ),
                ];
            case 'custom':
                $s = sanitize_text_field( $custom_start ) ?: wp_date( 'Y-m-01' );
                $e = sanitize_text_field( $custom_end   ) ?: $today;
                return [ 'start' => $s, 'end' => $e ];
            default: // month
                return [
                    'start' => wp_date( 'Y-m-01' ),
                    'end'   => wp_date( 'Y-m-t' ),
                ];
        }
    }
}
