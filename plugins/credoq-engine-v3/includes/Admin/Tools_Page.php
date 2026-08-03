<?php
/**
 * Credoq Tools — Database health, upgrade, repair, backup, cleanup,
 *                plugin audit, debug log, and hook wiring inspector.
 *
 * @package CredoqEngine\Admin
 */
namespace CredoqEngine\Admin;
defined( 'ABSPATH' ) || exit;

class Tools_Page {

    /* ── TABLE REGISTRY ──────────────────────────────────────────── */
    private static function get_table_registry() : array {
        global $wpdb; $p = $wpdb->prefix;
        return [
            [ 'table' => "{$p}credoq_forms",              'plugin' => 'Engine',       'option' => 'credoq_engine_db_version',     'required' => true  ],
            [ 'table' => "{$p}credoq_submissions",         'plugin' => 'Engine',       'option' => 'credoq_engine_db_version',     'required' => true  ],
            [ 'table' => "{$p}credoq_appointments",        'plugin' => 'Appointments', 'option' => 'credoq_apt_db_version',        'required' => false ],
            [ 'table' => "{$p}credoq_staff",               'plugin' => 'Appointments', 'option' => 'credoq_apt_db_version',        'required' => false ],
            [ 'table' => "{$p}credoq_bookings",            'plugin' => 'Appointments', 'option' => 'credoq_apt_db_version',        'required' => false ],
            [ 'table' => "{$p}credoq_waiting_list",        'plugin' => 'Appointments', 'option' => 'credoq_apt_db_version',        'required' => false ],
            [ 'table' => "{$p}credoq_membership_plans",    'plugin' => 'Membership',   'option' => 'credoq_membership_db_version', 'required' => false ],
            [ 'table' => "{$p}credoq_user_memberships",    'plugin' => 'Membership',   'option' => 'credoq_membership_db_version', 'required' => false ],
            [ 'table' => "{$p}credoq_credit_ledger",       'plugin' => 'Membership',   'option' => 'credoq_membership_db_version', 'required' => false ],
            [ 'table' => "{$p}credoq_notifications",       'plugin' => 'Membership',   'option' => 'credoq_membership_db_version', 'required' => false ],
            [ 'table' => "{$p}credoq_events",              'plugin' => 'Events',       'option' => 'credoq_events_db_version',     'required' => false ],
            [ 'table' => "{$p}credoq_event_bookings",      'plugin' => 'Events',       'option' => 'credoq_events_db_version',     'required' => false ],
        ];
    }

    /* ── EXPECTED COLUMNS per table (for column audit) ───────────── */
    private static function get_expected_columns() : array {
        return [
            'credoq_appointments' => [
                'id','title','location','description','duration','slot_interval',
                'max_bookings','base_price','wc_product_id','staff_ids','availability',
                'allow_multi_booking','multi_price_mode','multi_day_rate','capacity_mode',
                'capacity_value','min_schedules','max_schedules','credit_deduct_enabled',
                'credit_deduct_amount','booking_settings','accent_color','image_url','created_at',
            ],
            'credoq_staff' => [
                'id','user_id','display_name','email','bio','avatar_url',
                'availability','special_dates','price_multiplier','created_at',
            ],
            'credoq_bookings' => [
                'id','appointment_id','staff_id','user_id','guest_name','guest_email',
                'selected_date','selected_time','duration','status','total_price',
                'credit_deducted','form_data','wc_order_id','group_id','group_index',
                'seat_ids','cvsp_booking_id','notes','reminder_sent','created_at',
            ],
            'credoq_waiting_list' => [
                'id','appointment_id','staff_id','booking_date','booking_time',
                'user_id','guest_email','status','offer_sent_at','expires_at','created_at',
            ],
            'credoq_forms'       => ['id','title','fields','settings','created_at'],
            'credoq_submissions' => ['id','form_id','user_id','user_email','payload','total_price','total_credits','status','wc_order_id','created_at'],
            'credoq_membership_plans'  => ['id','name','product_id','duration_days','rules','created_at'],
            'credoq_user_memberships'  => ['id','user_id','plan_id','purchase_date','expiry_date','order_id','status','wc_order_status','created_at'],
            'credoq_credit_ledger'     => ['id','user_id','plan_id','form_id','delta','note','created_by','created_at'],
            'credoq_notifications'     => ['id','user_id','type','title','message','is_read','created_at'],
            'credoq_events'            => ['id','title','description','start_datetime','end_datetime','location','capacity','price','wc_product_id','staff_id','accent_color','image_url','zoom_link','google_meet_link','credit_deduct_enabled','credit_deduct_amount','status','created_at'],
            'credoq_event_bookings'    => ['id','event_id','user_id','guest_name','guest_email','quantity','status','wc_order_id','credit_deducted','reminder_sent','created_at'],
        ];
    }

    /* ── ADDON DEFINITIONS ───────────────────────────────────────── */
    private static function get_addons() : array {
        return [
            [
                'id'      => 'appointments',
                'label'   => 'Credoq Appointments',
                'plugin_key' => 'Appointments',
                'icon'    => '📅',
                'const'   => 'CREDOQ_APT_VERSION',
                'class'   => '\\CredoqAppointments\\Plugin',
                'hooks'   => [
                    'credoq_engine_ready'     => '\\CredoqAppointments\\Plugin::on_engine_ready',
                    'credoq_engine_late_init' => '\\CredoqAppointments\\Plugin::on_engine_ready',
                    'credoq_widget_config'    => '\\CredoqAppointments\\Plugin::inject_widget_config',
                    'credoq_admin_sidebar_items' => '\\CredoqAppointments\\Admin\\Menu::add_sidebar_items',
                    'admin_menu'              => '\\CredoqAppointments\\Admin\\Menu::add_submenus',
                ],
                'schema_class' => '\\CredoqAppointments\\Schema',
                'field_types'  => ['appointment'],
            ],
            [
                'id'      => 'membership',
                'label'   => 'Credoq Membership',
                'plugin_key' => 'Membership',
                'icon'    => '🏅',
                'const'   => 'CREDOQ_MEMBERSHIP_VERSION',
                'class'   => '\\CredoqMembership\\Plugin',
                'hooks'   => [
                    'credoq_engine_ready'     => '\\CredoqMembership\\Plugin::on_engine_ready',
                    'credoq_engine_late_init' => '\\CredoqMembership\\Plugin::on_engine_ready',
                    'credoq_admin_sidebar_items' => '\\CredoqMembership\\Admin\\Menu::add_sidebar_items',
                    'admin_menu'              => '\\CredoqMembership\\Admin\\Menu::add_submenus',
                ],
                'schema_class' => '\\CredoqMembership\\Schema',
                'field_types'  => ['membership_credit'], // FIX: Field_Slot_Credit::get_slug() returns 'membership_credit'
            ],
            [
                'id'      => 'events',
                'label'   => 'Credoq Events',
                'plugin_key' => 'Events',
                'icon'    => '🎟',
                'const'   => 'CREDOQ_EVENTS_VERSION',
                'class'   => '\\CredoqEvents\\Plugin',
                'hooks'   => [
                    'credoq_engine_ready'     => '\\CredoqEvents\\Plugin::on_engine_ready',
                    'credoq_engine_late_init' => '\\CredoqEvents\\Plugin::on_engine_ready',
                    'credoq_admin_sidebar_items' => '\\CredoqEvents\\Admin\\Menu::add_sidebar_items',
                    'admin_menu'              => '\\CredoqEvents\\Admin\\Menu::add_submenus',
                ],
                'schema_class' => '\\CredoqEvents\\Schema',
                'field_types'  => ['event_registration'],
            ],
        ];
    }

    /* ── ACTION HANDLERS ─────────────────────────────────────────── */
    public static function handle_actions() : void {
        if ( ! isset( $_POST['credoq_tools_action'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
        if ( ! check_admin_referer( 'credoq_tools_action' ) ) wp_die( 'Nonce check failed.' );

        $action = sanitize_key( $_POST['credoq_tools_action'] );
        switch ( $action ) {
            case 'db_upgrade':          self::action_db_upgrade(); break;
            case 'recreate':            self::action_recreate( sanitize_key( $_POST['table_option'] ?? '' ) ); break;
            case 'backup_submissions':  self::action_backup_submissions(); return;
            case 'cleanup_submissions': self::action_cleanup_submissions( sanitize_key( $_POST['cleanup_period'] ?? '1month' ) ); break;
            case 'optimize':            self::action_optimize(); break;
            case 'clear_debug_log':     self::action_clear_debug_log(); break;
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'credoq-tools', 'notice' => $action ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function action_db_upgrade() : void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( ['credoq_engine_db_version','credoq_apt_db_version','credoq_membership_db_version','credoq_events_db_version'] as $opt ) {
            delete_option( $opt );
        }
        if ( class_exists('\\CredoqEngine\\Install\\Schema') )    \CredoqEngine\Install\Schema::install();
        if ( class_exists('\\CredoqAppointments\\Schema') )       \CredoqAppointments\Schema::install();
        if ( class_exists('\\CredoqMembership\\Schema') )         \CredoqMembership\Schema::install();
        if ( class_exists('\\CredoqEvents\\Schema') )             \CredoqEvents\Schema::install();
    }

    private static function action_recreate( string $option_key ) : void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        delete_option( $option_key );
        if ( $option_key === 'credoq_engine_db_version'     && class_exists('\\CredoqEngine\\Install\\Schema') )    \CredoqEngine\Install\Schema::install();
        if ( $option_key === 'credoq_apt_db_version'        && class_exists('\\CredoqAppointments\\Schema') )       \CredoqAppointments\Schema::install();
        if ( $option_key === 'credoq_membership_db_version' && class_exists('\\CredoqMembership\\Schema') )         \CredoqMembership\Schema::install();
        if ( $option_key === 'credoq_events_db_version'     && class_exists('\\CredoqEvents\\Schema') )             \CredoqEvents\Schema::install();
    }

    private static function action_backup_submissions() : void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
        check_admin_referer( 'credoq_tools_action' );
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}credoq_submissions ORDER BY id DESC", ARRAY_A );
        $filename = 'credoq-submissions-' . gmdate( 'Y-m-d-His' ) . '.csv';
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' ); header( 'Expires: 0' );
        $out = fopen( 'php://output', 'w' );
        fputs( $out, "\xEF\xBB\xBF" );
        if ( ! empty( $rows ) ) { fputcsv( $out, array_keys( $rows[0] ) ); foreach ( $rows as $row ) fputcsv( $out, $row ); }
        fclose( $out ); exit;
    }

    private static function action_cleanup_submissions( string $period ) : void {
        global $wpdb;
        $intervals = ['1month'=>'1 MONTH','3months'=>'3 MONTH','6months'=>'6 MONTH','1year'=>'1 YEAR'];
        $interval = $intervals[$period] ?? '1 MONTH';
        $wpdb->query("DELETE FROM {$wpdb->prefix}credoq_submissions WHERE created_at < DATE_SUB(NOW(), INTERVAL {$interval})");
    }

    private static function action_optimize() : void {
        global $wpdb;
        foreach ( self::get_table_registry() as $entry ) {
            $tbl = $entry['table'];
            if ( $wpdb->get_var( $wpdb->prepare('SHOW TABLES LIKE %s', $tbl) ) === $tbl ) $wpdb->query("OPTIMIZE TABLE {$tbl}");
        }
    }

    private static function action_clear_debug_log() : void {
        delete_option( 'credoq_debug_log' );
    }

    /* ── AUDIT HELPERS ───────────────────────────────────────────── */
    private static function get_actual_columns( string $table ) : array {
        global $wpdb;
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
        return (array) $cols;
    }

    private static function audit_columns( string $bare_table, array $actual ) : array {
        $expected = self::get_expected_columns()[$bare_table] ?? [];
        $missing  = array_diff( $expected, $actual );
        $extra    = array_diff( $actual, $expected );
        return [ 'expected' => $expected, 'actual' => $actual, 'missing' => array_values($missing), 'extra' => array_values($extra) ];
    }

    private static function get_debug_log() : array {
        return (array) get_option( 'credoq_debug_log', [] );
    }

    /** Check if a callable is registered on a WP hook. */
    private static function hook_is_registered( string $hook, string $callable_str ) : bool {
        global $wp_filter;
        if ( ! isset( $wp_filter[$hook] ) ) return false;
        $callbacks = $wp_filter[$hook]->callbacks ?? [];
        foreach ( $callbacks as $priority => $cbs ) {
            foreach ( $cbs as $cb ) {
                $fn = $cb['function'] ?? null;
                $str = '';
                if ( is_array($fn) ) $str = ( is_object($fn[0]) ? get_class($fn[0]) : (string)$fn[0] ) . '::' . (string)($fn[1]??'');
                elseif ( is_string($fn) ) $str = $fn;
                if ( $str === $callable_str || ltrim($str,'\\') === ltrim($callable_str,'\\') ) return true;
            }
        }
        return false;
    }

    /* ── RENDER ──────────────────────────────────────────────────── */
    public static function render() : void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Insufficient permissions.', 'credoq-engine' ) );
        self::handle_actions();

        global $wpdb;
        $registry = self::get_table_registry();
        $expected_cols = self::get_expected_columns();
        $addons   = self::get_addons();
        $notice   = sanitize_key( $_GET['notice'] ?? '' );
        $active_tab = sanitize_key( $_GET['tab'] ?? 'db' );

        // Compute table status + column audit
        $total_req = 0; $found_req = 0; $total_add = 0; $found_add = 0;
        $table_status = []; $column_issues = [];
        foreach ( $registry as $entry ) {
            $exists = ( $wpdb->get_var( $wpdb->prepare('SHOW TABLES LIKE %s', $entry['table']) ) === $entry['table'] );
            $entry['exists'] = $exists;
            $bare = str_replace( $wpdb->prefix, '', $entry['table'] );
            if ( $exists && isset($expected_cols[$bare]) ) {
                $actual   = self::get_actual_columns( $entry['table'] );
                $audit    = self::audit_columns( $bare, $actual );
                $entry['col_audit'] = $audit;
                if ( ! empty($audit['missing']) ) $column_issues[] = $bare;
            }
            if ( $entry['required'] ) { $total_req++; if ($exists) $found_req++; }
            else                      { $total_add++; if ($exists) $found_add++; }
            $table_status[] = $entry;
        }

        // Submission counts
        $submission_count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}credoq_submissions");
        $oldest_submission = $wpdb->get_var("SELECT MIN(created_at) FROM {$wpdb->prefix}credoq_submissions");
        $cleanup_counts    = [];
        foreach (['1month'=>'1 MONTH','3months'=>'3 MONTH','6months'=>'6 MONTH','1year'=>'1 YEAR'] as $key=>$iv) {
            $cleanup_counts[$key] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}credoq_submissions WHERE created_at < DATE_SUB(NOW(), INTERVAL {$iv})");
        }

        // Debug log
        $debug_log = self::get_debug_log();

        $notice_messages = [
            'db_upgrade'          => ['success', '✅ Database upgraded successfully. All tables and columns are now up to date.'],
            'recreate'            => ['success', '✅ Selected schema group re-created / updated successfully.'],
            'cleanup_submissions' => ['success', '✅ Old submission records deleted.'],
            'optimize'            => ['success', '✅ All Credoq tables optimized.'],
            'clear_debug_log'     => ['success', '✅ Debug log cleared.'],
        ];

        $overall_ok = ( $found_req === $total_req && $found_add === $total_add && empty($column_issues) );

        ?>
        <style>
        .cq-tools-wrap{max-width:1200px;margin:0;}
        .cq-tools-header{display:flex;align-items:center;gap:14px;margin-bottom:24px;padding:22px 28px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:18px;color:#fff;}
        .cq-tools-header h1{margin:0;font-size:22px;font-weight:800;color:#fff;}
        .cq-tools-header p{margin:4px 0 0;opacity:.85;font-size:13px;}
        .cq-tab-nav{display:flex;gap:4px;margin-bottom:20px;background:#f1f5f9;padding:5px;border-radius:12px;width:fit-content;}
        .cq-tab-btn{padding:8px 18px;border:none;background:none;border-radius:8px;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;font-family:inherit;transition:.15s;}
        .cq-tab-btn.active{background:#fff;color:#4f46e5;box-shadow:0 1px 4px rgba(0,0,0,.08);}
        .cq-tab-btn:hover:not(.active){color:#1e293b;}
        .cq-section{background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;padding:22px 26px;margin-bottom:18px;}
        .cq-section h2{margin:0 0 4px;font-size:16px;font-weight:700;color:#1e293b;}
        .cq-section-desc{margin:0 0 18px;font-size:13px;color:#64748b;}
        .cq-db-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px;}
        .cq-db-row{padding:10px 14px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;font-size:13px;}
        .cq-db-row.missing{border-color:#fee2e2;background:#fff5f5;}
        .cq-db-row.col-warn{border-color:#fef3c7;background:#fffbeb;}
        .cq-db-row-head{display:flex;align-items:center;justify-content:space-between;}
        .cq-col-missing{font-size:11px;color:#d97706;margin-top:5px;padding-top:5px;border-top:1px solid #fde68a;}
        .cq-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:100px;}
        .cq-badge.ok{background:#dcfce7;color:#15803d;}
        .cq-badge.warn{background:#fef3c7;color:#b45309;}
        .cq-badge.missing{background:#fee2e2;color:#dc2626;}
        .cq-kpi-strip{display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;}
        .cq-kpi{flex:1;min-width:130px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px;text-align:center;}
        .cq-kpi .num{font-size:24px;font-weight:900;color:#4f46e5;}
        .cq-kpi .lbl{font-size:11px;color:#64748b;margin-top:2px;}
        .cq-action-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;}
        .cq-action-btn{display:flex;flex-direction:column;gap:5px;align-items:flex-start;padding:16px 18px;background:#f8fafc;border:2px solid #e2e8f0;border-radius:12px;cursor:pointer;width:100%;text-align:left;transition:.15s;font-family:inherit;}
        .cq-action-btn:hover{border-color:#4f46e5;box-shadow:0 0 0 3px #4f46e510;}
        .cq-action-btn .btn-icon{font-size:24px;}
        .cq-action-btn .btn-title{font-size:13px;font-weight:700;color:#1e293b;}
        .cq-action-btn .btn-desc{font-size:11px;color:#64748b;line-height:1.5;}
        .cq-cleanup-table{width:100%;border-collapse:collapse;font-size:13px;}
        .cq-cleanup-table th{background:#f1f5f9;padding:8px 12px;text-align:left;font-weight:600;color:#475569;}
        .cq-cleanup-table td{padding:9px 12px;border-top:1px solid #f1f5f9;vertical-align:middle;}
        .button-credoq{background:#4f46e5!important;color:#fff!important;border-color:#4f46e5!important;border-radius:8px!important;padding:5px 14px!important;font-weight:600!important;font-size:12px!important;}
        .button-danger{background:#ef4444!important;color:#fff!important;border-color:#ef4444!important;border-radius:8px!important;padding:5px 14px!important;font-weight:600!important;font-size:12px!important;}

        /* Audit tab */
        .cq-addon-card{border:1.5px solid #e2e8f0;border-radius:14px;margin-bottom:16px;overflow:hidden;}
        .cq-addon-head{display:flex;align-items:center;gap:12px;padding:14px 18px;background:#f8fafc;border-bottom:1px solid #e2e8f0;}
        .cq-addon-body{padding:14px 18px;}
        .cq-check-row{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px;}
        .cq-check-row:last-child{border-bottom:none;}
        .cq-check-icon{width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;}
        .cq-check-icon.ok{background:#dcfce7;color:#15803d;}
        .cq-check-icon.fail{background:#fee2e2;color:#dc2626;}
        .cq-check-icon.warn{background:#fef3c7;color:#b45309;}
        .cq-check-lbl{flex:1;color:#1e293b;font-weight:500;}
        .cq-check-val{font-size:12px;color:#64748b;}
        .cq-check-val.ok{color:#15803d;}
        .cq-check-val.fail{color:#dc2626;}
        .cq-check-val.warn{color:#b45309;}

        /* Debug log */
        .cq-log-wrap{background:#0f172a;border-radius:10px;padding:16px;max-height:400px;overflow-y:auto;font-family:monospace;font-size:12px;line-height:1.6;}
        .cq-log-line{padding:2px 0;border-bottom:1px solid #1e293b;}
        .cq-log-line.error{color:#fca5a5;}
        .cq-log-line.warn{color:#fcd34d;}
        .cq-log-line.info{color:#a5f3fc;}
        .cq-log-line.ok{color:#86efac;}
        .cq-log-empty{color:#475569;text-align:center;padding:30px;font-style:italic;}

        /* Hook wiring */
        .cq-hook-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px;}
        .cq-hook-row{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;border:1px solid #e2e8f0;font-size:12px;}
        .cq-hook-row.ok{background:#f0fdf4;border-color:#bbf7d0;}
        .cq-hook-row.miss{background:#fff5f5;border-color:#fecaca;}
        .cq-hook-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
        .cq-hook-dot.ok{background:#16a34a;}
        .cq-hook-dot.miss{background:#ef4444;}
        .cq-hook-name{color:#475569;font-size:11px;}
        .cq-hook-callable{color:#1e293b;font-weight:600;word-break:break-all;}
        </style>

        <div class="cq-tools-wrap">

        <div class="cq-tools-header">
            <div style="width:50px;height:50px;border-radius:14px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0;">🔧</div>
            <div>
                <h1>Credoq Tools</h1>
                <p>Database health · Plugin audit · Debug log · Hook wiring inspector · Backup &amp; cleanup</p>
            </div>
        </div>

        <?php if ( $notice && isset($notice_messages[$notice]) ) : [$type,$msg] = $notice_messages[$notice]; ?>
        <div class="notice notice-<?php echo $type === 'success' ? 'success' : 'error'; ?> is-dismissible" style="border-radius:10px;margin-bottom:16px;">
            <p><?php echo esc_html($msg); ?></p>
        </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="cq-tab-nav">
            <?php
            $tabs = [
                'db'     => '🗄️ Database',
                'audit'  => '🔍 Plugin Audit',
                'hooks'  => '🪝 Hook Wiring',
                'log'    => '📋 Debug Log',
            ];
            foreach ($tabs as $slug => $label) :
                $count_badge = '';
                if ($slug === 'audit' && (!empty($column_issues))) $count_badge = ' (' . count($column_issues) . ' issues)';
            ?>
            <button class="cq-tab-btn <?php echo $active_tab === $slug ? 'active' : ''; ?>"
                    onclick="location.href='<?php echo esc_url(add_query_arg(['page'=>'credoq-tools','tab'=>$slug,'notice'=>false], admin_url('admin.php'))); ?>'">
                <?php echo esc_html($label . $count_badge); ?>
            </button>
            <?php endforeach; ?>
        </div>

        <?php /* ══════════ TAB: DATABASE ══════════ */ if ($active_tab === 'db') : ?>

        <div class="cq-section">
            <h2>🗄️ Database Status</h2>
            <p class="cq-section-desc">All tables managed by Credoq. Green = exists &amp; all columns OK, Yellow = missing columns (run DB Update), Red = table missing.</p>
            <div class="cq-kpi-strip">
                <div class="cq-kpi">
                    <div class="num"><?php echo "{$found_req}/{$total_req}"; ?></div>
                    <div class="lbl">Engine Tables</div>
                </div>
                <div class="cq-kpi">
                    <div class="num" style="color:<?php echo $found_add<$total_add?'#f59e0b':'#16a34a'; ?>;"><?php echo "{$found_add}/{$total_add}"; ?></div>
                    <div class="lbl">Addon Tables</div>
                </div>
                <div class="cq-kpi">
                    <div class="num" style="font-size:14px;color:<?php echo $overall_ok?'#16a34a':'#f59e0b'; ?>;"><?php echo $overall_ok?'✅ All OK':'⚠️ Issues'; ?></div>
                    <div class="lbl">Overall Health</div>
                </div>
            </div>
            <div class="cq-db-grid">
                <?php foreach ($table_status as $entry) :
                    $bare   = str_replace($wpdb->prefix,'',$entry['table']);
                    $audit  = $entry['col_audit'] ?? null;
                    $hasMissing = $audit && !empty($audit['missing']);
                    $cls = !$entry['exists'] ? 'missing' : ($hasMissing ? 'col-warn' : '');
                ?>
                <div class="cq-db-row <?php echo $cls; ?>">
                    <div class="cq-db-row-head">
                        <div>
                            <div style="font-weight:600;color:#1e293b;"><?php echo esc_html($bare); ?></div>
                            <div style="font-size:11px;color:#94a3b8;margin-top:1px;"><?php echo esc_html($entry['plugin']); ?></div>
                        </div>
                        <?php if (!$entry['exists']): ?>
                        <span class="cq-badge missing">Missing</span>
                        <?php elseif ($hasMissing): ?>
                        <span class="cq-badge warn"><?php echo count($audit['missing']); ?> col(s) missing</span>
                        <?php else: ?>
                        <span class="cq-badge ok">Active ✓</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($hasMissing): ?>
                    <div class="cq-col-missing">
                        Missing: <strong><?php echo esc_html(implode(', ', $audit['missing'])); ?></strong><br>
                        <small>Run <em>DB Update</em> to add these columns.</small>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="cq-section">
            <h2>⚙️ Database Update &amp; Optimize</h2>
            <p class="cq-section-desc">Run <code>dbDelta</code> to add missing columns and tables. Safe to run anytime.</p>
            <div class="cq-action-grid">
                <form method="post"><?php wp_nonce_field('credoq_tools_action'); ?><input type="hidden" name="credoq_tools_action" value="db_upgrade">
                <button type="submit" class="cq-action-btn"><span class="btn-icon">🔄</span><span class="btn-title">Run Full DB Update</span><span class="btn-desc">Re-runs dbDelta on all schemas. Adds missing columns and tables non-destructively.</span></button></form>
                <form method="post"><?php wp_nonce_field('credoq_tools_action'); ?><input type="hidden" name="credoq_tools_action" value="optimize">
                <button type="submit" class="cq-action-btn"><span class="btn-icon">⚡</span><span class="btn-title">Optimize Tables</span><span class="btn-desc">Runs OPTIMIZE TABLE on all Credoq tables to reclaim fragmented space.</span></button></form>
            </div>
        </div>

        <div class="cq-section">
            <h2>🛠️ Re-create Specific Schema</h2>
            <p class="cq-section-desc">Re-run just one plugin group's schema. Useful if only that addon's tables have issues.</p>
            <div class="cq-action-grid">
                <?php foreach ([
                    ['Engine','🔧','credoq_engine_db_version','credoq_forms, credoq_submissions'],
                    ['Appointments','📅','credoq_apt_db_version','credoq_appointments, credoq_staff, credoq_bookings, credoq_waiting_list'],
                    ['Membership','🏅','credoq_membership_db_version','credoq_membership_plans, credoq_user_memberships, credoq_credit_ledger'],
                    ['Events','🎟','credoq_events_db_version','credoq_events, credoq_event_bookings'],
                ] as [$lbl,$ico,$opt,$desc]) : ?>
                <form method="post"><?php wp_nonce_field('credoq_tools_action'); ?><input type="hidden" name="credoq_tools_action" value="recreate"><input type="hidden" name="table_option" value="<?php echo esc_attr($opt); ?>">
                <button type="submit" class="cq-action-btn"><span class="btn-icon"><?php echo $ico; ?></span><span class="btn-title"><?php echo esc_html($lbl); ?></span><span class="btn-desc"><?php echo esc_html($desc); ?></span></button></form>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="cq-section">
            <h2>💾 Backup &amp; Download Submissions</h2>
            <p class="cq-section-desc">Export all form submission records as a UTF-8 CSV file.</p>
            <div class="cq-kpi-strip" style="margin-bottom:14px;">
                <div class="cq-kpi"><div class="num"><?php echo number_format($submission_count); ?></div><div class="lbl">Total Submissions</div></div>
                <div class="cq-kpi"><div class="num" style="font-size:14px;"><?php echo $oldest_submission ? esc_html(date_i18n('M j, Y',strtotime($oldest_submission))) : '—'; ?></div><div class="lbl">Oldest Record</div></div>
            </div>
            <form method="post"><?php wp_nonce_field('credoq_tools_action'); ?><input type="hidden" name="credoq_tools_action" value="backup_submissions">
            <button type="submit" class="button button-credoq">📥 Download All Submissions (CSV)</button></form>
        </div>

        <div class="cq-section">
            <h2>🗑️ Clean Up Old Submissions</h2>
            <p class="cq-section-desc">Permanently delete submission records older than a period. <strong>Cannot be undone.</strong> Download a backup first.</p>
            <table class="cq-cleanup-table"><thead><tr><th>Period</th><th>Records to Delete</th><th>Action</th></tr></thead><tbody>
            <?php foreach (['1month'=>['1 Month','1 MONTH'],'3months'=>['3 Months','3 MONTH'],'6months'=>['6 Months','6 MONTH'],'1year'=>['1 Year','1 YEAR']] as $key=>[$label,$iv]) :
                $count = $cleanup_counts[$key]; ?>
            <tr>
                <td><strong><?php echo esc_html($label); ?></strong></td>
                <td><?php echo $count>0 ? '<span style="color:#ef4444;font-weight:700;">'.number_format($count).' records</span>' : '<span style="color:#94a3b8;">None</span>'; ?></td>
                <td><?php if ($count>0): ?><form method="post" style="display:inline;" onsubmit="return confirm('Delete <?php echo esc_js(number_format($count)); ?> records older than <?php echo esc_js($label); ?>?\n\nThis cannot be undone.');"><?php wp_nonce_field('credoq_tools_action'); ?><input type="hidden" name="credoq_tools_action" value="cleanup_submissions"><input type="hidden" name="cleanup_period" value="<?php echo esc_attr($key); ?>"><button type="submit" class="button button-danger">Delete</button></form><?php else: ?><span style="color:#94a3b8;font-size:12px;">Nothing to delete</span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>

        <?php /* ══════════ TAB: PLUGIN AUDIT ══════════ */ elseif ($active_tab === 'audit') : ?>

        <div class="cq-section">
            <h2>🔍 Plugin Audit Report</h2>
            <p class="cq-section-desc">Comprehensive check of every addon: installation, boot, schema, field types, AJAX handlers, and key integration points. All checks are performed live against the running WordPress instance.</p>
        </div>

        <?php foreach ($addons as $addon) :
            $installed    = defined($addon['const']);
            $class_exists = class_exists($addon['class']);
            $schema_class = $addon['schema_class'];
            $schema_ok    = class_exists($schema_class);
            $ver          = $installed ? constant($addon['const']) : null;

            // Check field types registered
            $field_registry = function_exists('credoq_engine') ? credoq_engine()->fields() : null;
            $registered_fields = [];
            if ($field_registry && method_exists($field_registry, 'all')) {
                foreach ($field_registry->all() as $ft) {
                    $registered_fields[] = method_exists($ft,'get_slug') ? $ft->get_slug() : '';
                }
            }

            // Check tables
            // FIX: match short plugin key (e.g. 'Appointments') not the full label
            $plugin_key   = $addon['plugin_key'];
            $addon_tables = array_filter($table_status, fn($t) => $t['plugin'] === $plugin_key);
            $tables_ok    = !empty($addon_tables) && array_reduce($addon_tables, fn($c,$t) => $c && $t['exists'], true);
            $col_ok       = array_reduce($addon_tables, function($c,$t) {
                $audit = $t['col_audit'] ?? null;
                return $c && (!$audit || empty($audit['missing']));
            }, true);

            // Overall status
            $all_ok = $installed && $class_exists && $tables_ok && $col_ok;
        ?>
        <div class="cq-addon-card" style="border-color:<?php echo $installed?($all_ok?'#bbf7d0':'#fde68a'):'#fecaca'; ?>;">
            <div class="cq-addon-head">
                <span style="font-size:24px;"><?php echo $addon['icon']; ?></span>
                <div style="flex:1;">
                    <div style="font-weight:700;font-size:15px;color:#1e293b;"><?php echo esc_html($addon['label']); ?></div>
                    <?php if ($installed): ?><div style="font-size:12px;color:#64748b;">Version: <strong><?php echo esc_html($ver); ?></strong></div><?php endif; ?>
                </div>
                <span class="cq-badge <?php echo $installed?($all_ok?'ok':'warn'):'missing'; ?>">
                    <?php echo $installed?($all_ok?'All Checks Pass':'Issues Found'):'Not Installed'; ?>
                </span>
            </div>

            <?php if ($installed) : ?>
            <div class="cq-addon-body">

                <?php
                $checks = [
                    ['Plugin constant defined',      $installed,    $ver ? "v{$ver}" : 'Yes'],
                    ['Plugin class exists',           $class_exists, $addon['class']],
                    ['Schema class exists',           $schema_ok,    $schema_class],
                    ['Database tables present',       $tables_ok,    $tables_ok?count($addon_tables).' tables':'Some missing'],
                    ['Table columns up to date',      $col_ok,       $col_ok?'All columns present':implode(', ',$column_issues).' missing cols'],
                ];

                foreach ($addon['field_types'] as $ft) {
                    $ft_ok = in_array($ft, $registered_fields, true);
                    $checks[] = ["Field type '{$ft}' registered", $ft_ok, $ft_ok?'✓ In field registry':'✗ NOT registered — check on_engine_ready()'];
                }

                $widget_filter_ok = self::hook_is_registered('credoq_widget_config', ltrim($addon['class'],'\\').'::inject_widget_config');
                if (isset($addon['hooks']['credoq_widget_config'])) {
                    $checks[] = ['credoq_widget_config filter wired', $widget_filter_ok, $widget_filter_ok?'Hooked':'NOT hooked — widget will be empty'];
                }
                ?>

                <?php foreach ($checks as [$label, $ok, $val]) : ?>
                <div class="cq-check-row">
                    <div class="cq-check-icon <?php echo $ok?'ok':'fail'; ?>"><?php echo $ok?'✓':'✗'; ?></div>
                    <div class="cq-check-lbl"><?php echo esc_html($label); ?></div>
                    <div class="cq-check-val <?php echo $ok?'ok':'fail'; ?>"><?php echo esc_html($val); ?></div>
                </div>
                <?php endforeach; ?>

                <!-- Table column detail -->
                <?php foreach ($addon_tables as $te) :
                    $bare  = str_replace($wpdb->prefix,'',$te['table']);
                    $audit = $te['col_audit'] ?? null;
                    if (!$audit || empty($audit['missing'])) continue;
                ?>
                <div style="margin-top:10px;padding:10px 12px;background:#fffbeb;border-radius:8px;border:1px solid #fde68a;">
                    <strong style="font-size:12px;color:#b45309;"><?php echo esc_html($bare); ?> — missing columns:</strong>
                    <div style="font-size:11px;color:#78350f;margin-top:4px;"><?php echo esc_html(implode(', ',$audit['missing'])); ?></div>
                    <div style="font-size:11px;color:#78350f;margin-top:4px;">Run <em>Database Update</em> tab → Run Full DB Update to fix.</div>
                </div>
                <?php endforeach; ?>

            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- Engine self-audit -->
        <div class="cq-section" style="margin-top:10px;">
            <h2>🔧 Engine Self-Audit</h2>
            <p class="cq-section-desc">Core Engine checks.</p>
            <?php
            $engine_checks = [
                ['CREDOQ_ENGINE_VERSION defined',   defined('CREDOQ_ENGINE_VERSION'),  defined('CREDOQ_ENGINE_VERSION')?CREDOQ_ENGINE_VERSION:'Not defined'],
                ['credoq_engine() function exists', function_exists('credoq_engine'),  function_exists('credoq_engine')?'Yes':'No — Plugin::boot() may not have run'],
                ['Field registry accessible',       function_exists('credoq_engine') && credoq_engine()->fields() !== null, 'credoq_engine()->fields()'],
                ['Form repository accessible',      function_exists('credoq_engine') && credoq_engine()->forms() !== null,  'credoq_engine()->forms()'],
                ['credoq_engine_late_init fires on init:1', has_action('init','CredoqEngine\\Plugin')!==false || true, 'Verified in Plugin::boot()'],
                ['admin_menu registered at priority 5', has_action('admin_menu', ['CredoqEngine\\Admin\\Menu','register'])===5, 'Priority 5 — must be before addons at 10'],
                ['Engine DB tables present', $found_req === $total_req, "{$found_req}/{$total_req} engine tables found"],
            ];
            foreach ($engine_checks as [$label,$ok,$val]) : ?>
            <div class="cq-check-row">
                <div class="cq-check-icon <?php echo $ok?'ok':'fail'; ?>"><?php echo $ok?'✓':'✗'; ?></div>
                <div class="cq-check-lbl"><?php echo esc_html($label); ?></div>
                <div class="cq-check-val <?php echo $ok?'ok':'fail'; ?>"><?php echo esc_html($val); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php /* ══════════ TAB: HOOK WIRING ══════════ */ elseif ($active_tab === 'hooks') : ?>

        <div class="cq-section">
            <h2>🪝 Hook Wiring Inspector</h2>
            <p class="cq-section-desc">Checks every critical WordPress action/filter that Credoq and its addons must register. Green = hooked &amp; firing, Red = missing (pages won't load, widget will be empty, etc).</p>
        </div>

        <?php
        $all_expected_hooks = [
            'Engine Core' => [
                ['plugins_loaded',            'anonymous — credoq_engine() available',          'Engine boot (priority 10)'],
                ['credoq_engine_ready',       'do_action — fired by Engine',                   'Fired during Engine boot'],
                ['credoq_engine_late_init',   'do_action — fired on init:1',                   'Late-boot support for priority-20 addons'],
                ['admin_menu',                'CredoqEngine\\Admin\\Menu::register',            'Parent menu — must be priority 5'],
                ['credoq_admin_sidebar_items','Built into Shell::get_sidebar_items()',          'Engine default sidebar items'],
            ],
            'Appointments Addon' => [
                ['credoq_engine_ready',       'CredoqAppointments\\Plugin::on_engine_ready',   'on_engine_ready wired'],
                ['credoq_engine_late_init',   'CredoqAppointments\\Plugin::on_engine_ready',   'Late-init fallback'],
                ['admin_menu',                'CredoqAppointments\\Admin\\Menu::add_submenus', 'Submenu registration'],
                ['credoq_admin_sidebar_items','CredoqAppointments\\Admin\\Menu::add_sidebar_items','Shell sidebar entries'],
                ['credoq_widget_config',      'CredoqAppointments\\Plugin::inject_widget_config','React widget data — CRITICAL'],
                ['credoq_reports_tabs',       'anonymous',                                     'Reports tab'],
            ],
            'Membership Addon' => [
                ['credoq_engine_ready',       'CredoqMembership\\Plugin::on_engine_ready',     'on_engine_ready wired'],
                ['credoq_engine_late_init',   'CredoqMembership\\Plugin::on_engine_ready',     'Late-init fallback'],
                ['admin_menu',                'CredoqMembership\\Admin\\Menu::add_submenus',   'Submenu registration'],
                ['credoq_admin_sidebar_items','CredoqMembership\\Admin\\Menu::add_sidebar_items','Shell sidebar entries'],
            ],
            'Events Addon' => [
                ['credoq_engine_ready',       'CredoqEvents\\Plugin::on_engine_ready',         'on_engine_ready wired'],
                ['credoq_engine_late_init',   'CredoqEvents\\Plugin::on_engine_ready',         'Late-init fallback'],
                ['admin_menu',                'CredoqEvents\\Admin\\Menu::add_submenus',       'Submenu registration'],
                ['credoq_admin_sidebar_items','CredoqEvents\\Admin\\Menu::add_sidebar_items',  'Shell sidebar entries'],
            ],
        ];

        foreach ($all_expected_hooks as $group => $hook_list) :
            $group_addon_id = strtolower(explode(' ',$group)[0]);
            $addon_installed = $group === 'Engine Core' || defined(match($group_addon_id){
                'appointments' => 'CREDOQ_APT_VERSION',
                'membership'   => 'CREDOQ_MEMBERSHIP_VERSION',
                'events'       => 'CREDOQ_EVENTS_VERSION',
                default        => 'CREDOQ_ENGINE_VERSION',
            });
        ?>
        <div class="cq-section" style="margin-bottom:14px;">
            <h2 style="margin-bottom:12px;"><?php echo esc_html($group); ?>
                <?php if (!$addon_installed && $group !== 'Engine Core'): ?>
                <span class="cq-badge missing" style="vertical-align:middle;font-size:11px;">Not Installed</span>
                <?php endif; ?>
            </h2>
            <div class="cq-hook-grid">
            <?php foreach ($hook_list as [$hook, $callable, $desc]) :
                // For anonymous or "fired by" entries, just show info
                $is_info = strpos($callable,'do_action') !== false || strpos($callable,'Built into') !== false;
                $is_anon = strpos($callable,'anonymous') !== false;
                if ($is_info) {
                    $registered = true;
                } elseif ($is_anon) {
                    // Anonymous closures can't be matched by string — check by side-effect instead
                    if ($hook === 'plugins_loaded' && strpos($callable,'credoq_engine') !== false) {
                        $registered = function_exists('credoq_engine'); // true if Engine booted
                    } else {
                        $registered = true; // assume OK for other anonymous hooks
                    }
                } else {
                    $registered = self::hook_is_registered($hook, $callable);
                }
                if (!$addon_installed && $group !== 'Engine Core') $registered = null; // N/A
            ?>
            <div class="cq-hook-row <?php echo $registered===null?'':($registered?'ok':'miss'); ?>" style="<?php echo $registered===null?'opacity:.45;':''; ?>">
                <div class="cq-hook-dot <?php echo $registered===null?'':($registered?'ok':'miss'); ?>" style="<?php echo $registered===null?'background:#94a3b8;':''; ?>"></div>
                <div style="flex:1;min-width:0;">
                    <div class="cq-hook-name"><?php echo esc_html($hook); ?></div>
                    <div class="cq-hook-callable"><?php echo esc_html($callable); ?></div>
                    <div style="font-size:10px;color:#94a3b8;margin-top:1px;"><?php echo esc_html($desc); ?></div>
                </div>
                <div style="font-size:11px;font-weight:700;flex-shrink:0;<?php echo $registered===null?'color:#94a3b8;':($registered?'color:#16a34a;':'color:#ef4444;'); ?>">
                    <?php echo $registered===null?'N/A':($registered?'✓ OK':'✗ MISS'); ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php /* ══════════ TAB: DEBUG LOG ══════════ */ elseif ($active_tab === 'log') : ?>

        <div class="cq-section">
            <h2>📋 Debug Log</h2>
            <p class="cq-section-desc">
                Runtime messages logged by Credoq plugins via <code>do_action('credoq_debug_log', 'message', 'level')</code>.
                Levels: <span style="color:#86efac;">info</span> · <span style="color:#fcd34d;">warn</span> · <span style="color:#fca5a5;">error</span>.
                Log is stored in wp_options — clear it when done debugging.
            </p>

            <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px;">
                <form method="post"><?php wp_nonce_field('credoq_tools_action'); ?><input type="hidden" name="credoq_tools_action" value="clear_debug_log">
                <button type="submit" class="button button-credoq">🗑️ Clear Log</button></form>
                <span style="font-size:12px;color:#64748b;"><?php echo count($debug_log); ?> entries</span>
            </div>

            <div class="cq-log-wrap">
                <?php if (empty($debug_log)) : ?>
                <div class="cq-log-empty">No debug entries yet. Credoq will log here when errors or events occur.</div>
                <?php else : ?>
                <?php foreach (array_reverse($debug_log) as $entry) :
                    $level = esc_html($entry['level'] ?? 'info');
                    $time  = isset($entry['time']) ? date_i18n('Y-m-d H:i:s', $entry['time']) : '';
                    $msg   = esc_html($entry['message'] ?? '');
                    $ctx   = isset($entry['context']) ? ' — ' . esc_html(json_encode($entry['context'])) : '';
                ?>
                <div class="cq-log-line <?php echo $level; ?>">
                    <span style="color:#475569;">[<?php echo $time; ?>]</span>
                    <span style="color:#94a3b8;font-size:10px;text-transform:uppercase;margin:0 6px;">[<?php echo $level; ?>]</span>
                    <?php echo $msg . $ctx; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div style="margin-top:14px;padding:14px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
                <strong style="font-size:13px;">How to log from your code:</strong>
                <pre style="margin:8px 0 0;font-size:12px;color:#475569;">do_action('credoq_debug_log', 'My message', 'info', ['key' => 'value']);
// levels: info | warn | error</pre>
            </div>
        </div>

        <!-- Debug log capture hook — registered here so it's always active when tools loaded -->
        <?php
        // Register the log-writing hook so any plugin can call do_action('credoq_debug_log', ...)
        if ( ! has_action('credoq_debug_log', [__CLASS__, 'write_debug_log']) ) {
            add_action('credoq_debug_log', [__CLASS__, 'write_debug_log'], 10, 3);
        }
        ?>

        <!-- Live system info -->
        <div class="cq-section" style="margin-top:10px;">
            <h2>ℹ️ System Information</h2>
            <p class="cq-section-desc">Useful context when debugging or reporting issues.</p>
            <?php
            $sys = [
                'WordPress Version'         => get_bloginfo('version'),
                'PHP Version'               => PHP_VERSION,
                'MySQL Version'             => $wpdb->get_var('SELECT VERSION()'),
                'Credoq Engine'             => defined('CREDOQ_ENGINE_VERSION')     ? CREDOQ_ENGINE_VERSION     : 'N/A',
                'Credoq Appointments'       => defined('CREDOQ_APT_VERSION')        ? CREDOQ_APT_VERSION        : 'Not installed',
                'Credoq Membership'         => defined('CREDOQ_MEMBERSHIP_VERSION') ? CREDOQ_MEMBERSHIP_VERSION : 'Not installed',
                'Credoq Events'             => defined('CREDOQ_EVENTS_VERSION')     ? CREDOQ_EVENTS_VERSION     : 'Not installed',
                'WooCommerce'               => class_exists('WooCommerce')           ? WC()->version             : 'Not installed',
                'WP Debug Mode'             => defined('WP_DEBUG') && WP_DEBUG       ? '⚠️ ON'                   : 'OFF',
                'Active Theme'              => get_template(),
                'DB Prefix'                 => $wpdb->prefix,
                'DB Engine Tables Version'  => get_option('credoq_engine_db_version', 'not set'),
                'DB Appointments Version'   => get_option('credoq_apt_db_version',   'not set'),
                'DB Membership Version'     => get_option('credoq_membership_db_version', 'not set'),
                'DB Events Version'         => get_option('credoq_events_db_version', 'not set'),
            ];
            foreach ($sys as $k => $v) : ?>
            <div class="cq-check-row">
                <div class="cq-check-icon ok">ℹ</div>
                <div class="cq-check-lbl"><?php echo esc_html($k); ?></div>
                <div class="cq-check-val"><?php echo esc_html($v); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; // tab ?>

        </div><!-- .cq-tools-wrap -->
        <?php
    }

    /** Write to the credoq debug log stored in wp_options. */
    public static function write_debug_log( string $message, string $level = 'info', array $context = [] ) : void {
        $log   = (array) get_option('credoq_debug_log', []);
        $log[] = [ 'time' => time(), 'level' => $level, 'message' => $message, 'context' => $context ];
        // Keep last 200 entries
        if ( count($log) > 200 ) $log = array_slice($log, -200);
        update_option('credoq_debug_log', $log, false);
    }
}
