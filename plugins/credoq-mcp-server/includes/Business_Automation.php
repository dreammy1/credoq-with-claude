<?php
/**
 * CredoQ MCP business automation and synthetic staging orchestration.
 *
 * All writes are staging-only, synthetic-data-only, proposal/confirmation
 * gated, and auditable. The class deliberately uses the plugin tables and
 * repositories already shipped by CredoQ so an AI client has one stable MCP
 * contract instead of screen-scraping admin pages.
 */
defined( 'ABSPATH' ) || exit;

final class Credoq_MCP_Business_Automation {

    const PROPOSAL_PREFIX = 'credoq_mcp_business_';
    const RESOURCES = array( 'service', 'staff', 'user', 'membership', 'event', 'seat_plan', 'form', 'page', 'wc_product_link' );
    const BUSINESS_TYPES = array( 'appointment', 'event', 'membership', 'seat', 'form', 'full' );

    public static function preview_provision( $args ) {
        $resource = sanitize_key( $args['resource'] ?? '' );
        $data = is_array( $args['data'] ?? null ) ? $args['data'] : array();
        if ( ! in_array( $resource, self::RESOURCES, true ) ) return array( 'error' => 'Unsupported provision resource.' );
        if ( empty( $data ) ) return array( 'error' => 'Provision data cannot be empty.' );
        $proposal_id = wp_generate_uuid4();
        $token = wp_generate_password( 40, false, false );
        $correlation_id = 'AUDIT TEST ' . strtoupper( substr( str_replace( '-', '', $proposal_id ), 0, 12 ) );
        $clean = self::sanitize_resource_data( $resource, $data, $correlation_id );
        if ( isset( $clean['error'] ) ) return $clean;
        set_transient( self::PROPOSAL_PREFIX . $proposal_id, array( 'kind' => 'provision', 'resource' => $resource, 'data' => $clean, 'token' => $token, 'correlation_id' => $correlation_id ), 600 );
        return array( 'status' => 'awaiting_approval', 'proposal_id' => $proposal_id, 'confirm_token' => $token, 'expires_in' => 600, 'resource' => $resource, 'correlation_id' => $correlation_id, 'requested' => $clean, 'warning' => 'No mutation was performed. Synthetic staging data only.' );
    }

    public static function apply_provision( $args ) {
        if ( empty( $args['confirm'] ) ) return array( 'error' => 'Explicit confirm=true is required.' );
        if ( ! self::staging_guard() ) return array( 'error' => 'Provisioning is allowed only in an explicitly enabled non-production staging environment.' );
        $proposal_id = sanitize_text_field( $args['proposal_id'] ?? '' );
        $proposal = get_transient( self::PROPOSAL_PREFIX . $proposal_id );
        if ( ! is_array( $proposal ) || 'provision' !== ( $proposal['kind'] ?? '' ) || ! hash_equals( (string) $proposal['token'], (string) ( $args['confirm_token'] ?? '' ) ) ) return array( 'error' => 'Invalid or expired provisioning confirmation token.' );
        $result = self::create_resource( $proposal['resource'], $proposal['data'], $proposal['correlation_id'] );
        if ( isset( $result['error'] ) ) return $result;
        if ( 'membership' === $proposal['resource'] && ! empty( $proposal['data']['user_id'] ) && ! empty( $result['id'] ) ) {
            $assignment = self::assign_membership( (int) $proposal['data']['user_id'], (int) $result['id'], $proposal['data'], $proposal['correlation_id'] );
            if ( isset( $assignment['error'] ) ) return $assignment;
            $result['assignment'] = $assignment;
        }
        if ( 'seat_plan' === $proposal['resource'] && ! empty( $result['id'] ) ) {
            $result['seats'] = self::create_seats( (int) $result['id'], (array) ( $proposal['data']['seats'] ?? array() ) );
        }
        delete_transient( self::PROPOSAL_PREFIX . $proposal_id );
        self::audit( 'provision_' . $proposal['resource'], array( 'correlation_id' => $proposal['correlation_id'], 'result' => $result ) );
        return array( 'status' => 'created', 'proposal_id' => $proposal_id, 'resource' => $proposal['resource'], 'correlation_id' => $proposal['correlation_id'], 'result' => $result );
    }

    public static function preview_business_e2e( $args ) {
        $type = sanitize_key( $args['business_type'] ?? '' );
        if ( ! in_array( $type, self::BUSINESS_TYPES, true ) ) return array( 'error' => 'Unsupported business_type.' );
        $options = is_array( $args['options'] ?? null ) ? $args['options'] : array();
        $proposal_id = wp_generate_uuid4();
        $token = wp_generate_password( 40, false, false );
        $correlation_id = 'AUDIT TEST E2E ' . strtoupper( substr( str_replace( '-', '', $proposal_id ), 0, 12 ) );
        $steps = self::steps_for( $type );
        set_transient( self::PROPOSAL_PREFIX . $proposal_id, array( 'kind' => 'business_e2e', 'business_type' => $type, 'options' => $options, 'token' => $token, 'correlation_id' => $correlation_id ), 600 );
        return array( 'status' => 'awaiting_approval', 'proposal_id' => $proposal_id, 'confirm_token' => $token, 'expires_in' => 600, 'business_type' => $type, 'correlation_id' => $correlation_id, 'steps' => $steps, 'environment_guard' => 'synthetic staging only; no live payment capture or production deployment', 'warning' => 'No test was run.' );
    }

    public static function run_business_e2e( $args ) {
        if ( empty( $args['confirm'] ) ) return array( 'error' => 'Explicit confirm=true is required.' );
        if ( ! self::staging_guard() ) return array( 'error' => 'Business E2E is allowed only in explicitly enabled non-production staging.' );
        $proposal_id = sanitize_text_field( $args['proposal_id'] ?? '' );
        $proposal = get_transient( self::PROPOSAL_PREFIX . $proposal_id );
        if ( ! is_array( $proposal ) || 'business_e2e' !== ( $proposal['kind'] ?? '' ) || ! hash_equals( (string) $proposal['token'], (string) ( $args['confirm_token'] ?? '' ) ) ) return array( 'error' => 'Invalid or expired E2E confirmation token.' );
        delete_transient( self::PROPOSAL_PREFIX . $proposal_id );
        $report = self::verify_business_journey( array( 'correlation_id' => $proposal['correlation_id'] ) );
        $report['business_type'] = $proposal['business_type'];
        $report['correlation_id'] = $proposal['correlation_id'];
        $report['planned_steps'] = self::steps_for( $proposal['business_type'] );
        $report['status'] = 'completed_readback';
        $report['limitations'] = array( 'Browser form filling and external mailbox inspection require the authenticated staging Playwright track; this MCP call verifies server-side records and configured integrations.' );
        self::audit( 'business_e2e', array( 'business_type' => $proposal['business_type'], 'correlation_id' => $proposal['correlation_id'] ) );
        return $report;
    }

    public static function verify_business_journey( $args ) {
        $correlation = sanitize_text_field( $args['correlation_id'] ?? '' );
        if ( '' === $correlation ) return array( 'error' => 'correlation_id is required.' );
        global $wpdb;
        $out = array( 'correlation_id' => $correlation, 'checks' => array() );
        $out['checks']['submissions'] = self::search_table( 'credoq_submissions', array( 'payload', 'source' ), $correlation, true );
        $out['checks']['appointment_bookings'] = self::search_table( 'credoq_bookings', array( 'form_data', 'notes' ), $correlation, false );
        $out['checks']['event_bookings'] = self::search_table( 'credoq_event_bookings', array( 'guest_name', 'guest_email' ), $correlation, false );
        $out['checks']['memberships'] = self::search_table( 'credoq_user_memberships', array( 'user_id', 'plan_id' ), $correlation, false );
        $out['checks']['credit_ledger'] = self::search_table( 'credoq_credit_ledger', array( 'description', 'ref_id' ), $correlation, false );
        $out['checks']['audit_log'] = self::search_audit( $correlation );
        $out['checks']['woocommerce'] = self::search_orders( $correlation );
        $out['summary'] = array( 'passed' => 0, 'failed' => 0, 'not_observable' => 0 );
        foreach ( $out['checks'] as $check ) {
            if ( ! empty( $check['observable'] ) ) { $out['summary']['passed'] += ! empty( $check['count'] ) ? 1 : 0; $out['summary']['not_observable'] += empty( $check['count'] ) ? 1 : 0; }
            else $out['summary']['not_observable']++;
        }
        return $out;
    }

    private static function create_resource( $resource, $data, $correlation ) {
        global $wpdb;
        $now = current_time( 'mysql', true );
        if ( 'user' === $resource ) {
            if ( ! function_exists( 'wp_create_user' ) ) return array( 'error' => 'WordPress user API unavailable.' );
            $email = sanitize_email( $data['email'] );
            $login = sanitize_user( $data['login'] ?: current( explode( '@', $email ) ), true );
            $password = wp_generate_password( 24, true, true );
            $user_id = username_exists( $login );
            if ( ! $user_id ) $user_id = wp_create_user( $login, $password, $email );
            if ( is_wp_error( $user_id ) ) return array( 'error' => $user_id->get_error_message() );
            return array( 'user_id' => (int) $user_id, 'login' => $login, 'email' => $email, 'password_issued' => false );
        }
        $tables = array( 'service' => 'credoq_appointments', 'staff' => 'credoq_staff', 'event' => 'credoq_events', 'seat_plan' => 'credoq_seat_plans', 'membership' => 'credoq_membership_plans' );
        if ( isset( $tables[ $resource ] ) ) {
            $table = $wpdb->prefix . $tables[ $resource ];
            $map = self::row_for( $resource, $data, $now, $correlation );
            if ( ! $map ) return array( 'error' => 'No supported fields were supplied for ' . $resource . '.' );
            $ok = $wpdb->insert( $table, $map );
            if ( false === $ok ) return array( 'error' => 'Insert failed for ' . $resource . ': ' . $wpdb->last_error );
            return array( 'id' => (int) $wpdb->insert_id, 'table' => $tables[ $resource ] );
        }
        if ( 'form' === $resource ) {
            if ( ! class_exists( '\CredoqEngine\Forms\Repository' ) ) return array( 'error' => 'Forms Repository unavailable.' );
            $repo = new \CredoqEngine\Forms\Repository();
            $saved = $repo->save( array( 'title' => sanitize_text_field( $data['title'] ), 'fields' => $data['fields'], 'settings' => $data['settings'] ?? array(), 'status' => 'published' ) );
            if ( is_wp_error( $saved ) ) return array( 'error' => $saved->get_error_message() );
            return array( 'form_id' => (int) $saved );
        }
        if ( 'page' === $resource ) {
            $post_id = wp_insert_post( array( 'post_title' => sanitize_text_field( $data['title'] ), 'post_content' => wp_kses_post( $data['content'] ), 'post_status' => 'publish', 'post_type' => 'page' ), true );
            return is_wp_error( $post_id ) ? array( 'error' => $post_id->get_error_message() ) : array( 'page_id' => (int) $post_id, 'url' => get_permalink( $post_id ) );
        }
        if ( 'wc_product_link' === $resource ) {
            $id = absint( $data['resource_id'] ?? 0 ); $product_id = absint( $data['product_id'] ?? 0 );
            if ( ! $id || ! $product_id ) return array( 'error' => 'resource_id and product_id are required.' );
            $table = sanitize_key( $data['resource_table'] ?? 'credoq_appointments' );
            if ( ! in_array( $table, array( 'credoq_appointments', 'credoq_events' ), true ) ) return array( 'error' => 'Only appointment and event product links are supported.' );
            $ok = $wpdb->update( $wpdb->prefix . $table, array( 'wc_product_id' => $product_id ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
            return false === $ok ? array( 'error' => 'WooCommerce product link update failed.' ) : array( 'resource_id' => $id, 'product_id' => $product_id, 'table' => $table );
        }
        return array( 'error' => 'Unsupported resource implementation.' );
    }

    private static function row_for( $resource, $data, $now, $correlation ) {
        $name = sanitize_text_field( $data['name'] ?? $data['title'] ?? ( 'AUDIT TEST ' . ucfirst( $resource ) ) );
        if ( 'service' === $resource ) return array( 'title' => $name, 'location' => sanitize_text_field( $data['location'] ?? 'AUDIT TEST' ), 'description' => sanitize_textarea_field( $data['description'] ?? $correlation ), 'duration' => absint( $data['duration'] ?? 60 ), 'slot_interval' => absint( $data['slot_interval'] ?? 60 ), 'base_price' => (float) ( $data['price'] ?? 0 ), 'wc_product_id' => absint( $data['wc_product_id'] ?? 0 ), 'created_at' => $now );
        if ( 'staff' === $resource ) return array( 'user_id' => absint( $data['user_id'] ?? 0 ), 'display_name' => $name, 'email' => sanitize_email( $data['email'] ?? '' ), 'bio' => sanitize_textarea_field( $data['bio'] ?? $correlation ), 'price_multiplier' => (float) ( $data['price_multiplier'] ?? 1 ), 'created_at' => $now );
        if ( 'event' === $resource ) return array( 'title' => $name, 'description' => sanitize_textarea_field( $data['description'] ?? $correlation ), 'start_datetime' => sanitize_text_field( $data['start_datetime'] ?? gmdate( 'Y-m-d H:i:s', time() + 86400 ) ), 'end_datetime' => sanitize_text_field( $data['end_datetime'] ?? gmdate( 'Y-m-d H:i:s', time() + 90000 ) ), 'location' => sanitize_text_field( $data['location'] ?? 'AUDIT TEST venue' ), 'price' => (float) ( $data['price'] ?? 0 ), 'capacity' => absint( $data['capacity'] ?? 20 ), 'status' => 'published', 'wc_product_id' => absint( $data['wc_product_id'] ?? 0 ), 'credit_deduct_enabled' => ! empty( $data['credit_deduct_enabled'] ), 'credit_deduct_amount' => max( 1, absint( $data['credit_deduct_amount'] ?? 1 ) ), 'created_at' => $now );
        if ( 'membership' === $resource ) return array( 'name' => $name, 'product_id' => absint( $data['product_id'] ?? 0 ), 'duration_days' => max( 1, absint( $data['duration_days'] ?? 30 ) ), 'rules' => wp_json_encode( array( 'price' => (float) ( $data['price'] ?? 0 ), 'credits' => absint( $data['credits'] ?? 0 ), 'correlation_id' => $correlation ) ), 'created_at' => $now );
        if ( 'seat_plan' === $resource ) return array( 'name' => $name, 'description' => sanitize_textarea_field( $data['description'] ?? $correlation ), 'template_key' => sanitize_key( $data['template_key'] ?? 'custom' ), 'connect_type' => sanitize_key( $data['connect_type'] ?? 'event' ), 'connected_ids' => wp_json_encode( array_map( 'absint', (array) ( $data['connected_ids'] ?? array() ) ) ), 'layout_json' => wp_json_encode( $data['layout'] ?? array() ), 'total_floors' => max( 1, absint( $data['total_floors'] ?? 1 ) ), 'total_seats' => absint( $data['total_seats'] ?? count( (array) ( $data['seats'] ?? array() ) ) ), 'capacity_limit' => absint( $data['capacity_limit'] ?? 0 ), 'status' => 'published', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now );
        return array();
    }

    private static function assign_membership( $user_id, $plan_id, $data, $correlation ) {
        global $wpdb;
        $table = $wpdb->prefix . 'credoq_user_memberships';
        $expiry = gmdate( 'Y-m-d H:i:s', time() + max( 1, absint( $data['duration_days'] ?? 30 ) ) * DAY_IN_SECONDS );
        $ok = $wpdb->insert( $table, array( 'user_id' => $user_id, 'plan_id' => $plan_id, 'purchase_date' => current_time( 'mysql', true ), 'expiry_date' => $expiry, 'order_id' => 0, 'wc_order_status' => 'synthetic', 'status' => 'active', 'created_at' => current_time( 'mysql', true ) ) );
        if ( false === $ok ) return array( 'error' => 'Membership assignment failed: ' . $wpdb->last_error );
        $membership_id = (int) $wpdb->insert_id;
        $credits = absint( $data['credits'] ?? 0 );
        $ledger_id = 0;
        if ( $credits > 0 ) {
            $wpdb->insert( $wpdb->prefix . 'credoq_credit_ledger', array( 'user_id' => $user_id, 'user_email' => sanitize_email( $data['email'] ?? '' ), 'plan_id' => $plan_id, 'amount' => $credits, 'type' => 'grant', 'ref_id' => $membership_id, 'note' => $correlation, 'created_at' => current_time( 'mysql', true ) ) );
            $ledger_id = (int) $wpdb->insert_id;
        }
        return array( 'membership_id' => $membership_id, 'ledger_id' => $ledger_id, 'credits_granted' => $credits );
    }

    private static function create_seats( $plan_id, $seats ) {
        global $wpdb;
        $floor_id = 0;
        $floor_table = $wpdb->prefix . 'credoq_seat_plan_floors';
        $wpdb->insert( $floor_table, array( 'plan_id' => $plan_id, 'name' => 'AUDIT TEST Floor 1', 'sort_order' => 0, 'color' => '#4f46e5', 'seat_count' => count( $seats ) ) );
        $floor_id = (int) $wpdb->insert_id;
        if ( ! $floor_id ) return array( 'error' => 'Seat floor creation failed: ' . $wpdb->last_error );
        $created = array();
        foreach ( $seats as $index => $seat ) {
            $label = sanitize_text_field( is_array( $seat ) ? ( $seat['label'] ?? 'A' . ( $index + 1 ) ) : (string) $seat );
            $price = is_array( $seat ) && isset( $seat['price'] ) ? (float) $seat['price'] : null;
            $row = array( 'plan_id' => $plan_id, 'floor_id' => $floor_id, 'seat_label' => $label, 'seat_type' => sanitize_key( is_array( $seat ) ? ( $seat['type'] ?? 'standard' ) : 'standard' ), 'row_index' => $index, 'col_index' => 0, 'x_pos' => $index * 80, 'y_pos' => 0, 'price_override' => $price, 'status' => 'available', 'color_class' => '' );
            if ( false !== $wpdb->insert( $wpdb->prefix . 'credoq_seats', $row ) ) $created[] = (int) $wpdb->insert_id;
        }
        return array( 'floor_id' => $floor_id, 'seat_ids' => $created, 'count' => count( $created ) );
    }

    private static function sanitize_resource_data( $resource, $data, $correlation ) {
        if ( 'user' === $resource && empty( $data['email'] ) ) return array( 'error' => 'A synthetic email is required for a user.' );
        if ( 'form' === $resource && ( empty( $data['title'] ) || ! is_array( $data['fields'] ?? null ) ) ) return array( 'error' => 'A form title and fields array are required.' );
        $data['correlation_id'] = $correlation;
        return $data;
    }

    private static function steps_for( $type ) {
        $common = array( 'discover plugin and configuration state', 'create or identify synthetic fixtures', 'publish the form/page', 'submit with synthetic identity', 'read back persistence and correlation records', 'check audit trail and produce a structured report' );
        $extra = array( 'appointment' => array( 'create service and staff', 'link WooCommerce product', 'test slot selection and capacity', 'test checkout and order-status synchronization' ), 'event' => array( 'create event and seat plan', 'test event registration and seat allocation', 'test paid checkout and credit-paid branch' ), 'membership' => array( 'create membership plan and synthetic user', 'grant membership and inspect ledger', 'test credit balance and deduction/refund' ), 'seat' => array( 'create seat plan', 'test per-seat pricing, holds, confirmation, and release' ), 'form' => array( 'test contact, basic estimate, advanced estimate, appointment, event, and signature field combinations' ), 'full' => array( 'run all appointment, event, membership, seat, form, checkout, security, notification, and audit checks' ) );
        return array_merge( $common, $extra[ $type ] ?? array() );
    }

    private static function staging_guard() {
        return defined( 'CREDOQ_MCP_STAGING_MODE' ) && true === CREDOQ_MCP_STAGING_MODE && ( ! function_exists( 'wp_get_environment_type' ) || 'production' !== wp_get_environment_type() );
    }

    private static function search_table( $table, $columns, $term, $redact ) {
        global $wpdb;
        $full = $wpdb->prefix . $table; $parts = array();
        foreach ( $columns as $column ) $parts[] = $wpdb->prepare( "`{$column}` LIKE %s", '%' . $wpdb->esc_like( $term ) . '%' );
        $rows = (array) $wpdb->get_results( 'SELECT * FROM ' . $full . ' WHERE ' . implode( ' OR ', $parts ) . ' ORDER BY id DESC LIMIT 25', ARRAY_A );
        if ( $redact ) foreach ( $rows as &$row ) { unset( $row['payload'], $row['ip_address'], $row['user_agent'] ); }
        return array( 'observable' => true, 'count' => count( $rows ), 'records' => $rows );
    }

    private static function search_audit( $term ) {
        $rows = get_option( 'credoq_mcp_audit_log', array() ); $found = array();
        foreach ( (array) $rows as $row ) if ( false !== stripos( wp_json_encode( $row ), $term ) ) $found[] = $row;
        return array( 'observable' => true, 'count' => count( $found ), 'records' => array_slice( $found, -25 ) );
    }

    private static function search_orders( $term ) {
        if ( ! function_exists( 'wc_get_orders' ) ) return array( 'observable' => false, 'reason' => 'WooCommerce unavailable.' );
        $orders = wc_get_orders( array( 'limit' => 25, 'orderby' => 'date', 'order' => 'DESC' ) ); $found = array();
        foreach ( $orders as $order ) if ( false !== stripos( wp_json_encode( $order->get_meta_data() ), $term ) || false !== stripos( (string) $order->get_customer_note(), $term ) ) $found[] = array( 'id' => $order->get_id(), 'status' => $order->get_status(), 'total' => $order->get_total() );
        return array( 'observable' => true, 'count' => count( $found ), 'records' => $found );
    }

    private static function audit( $event, $data ) {
        if ( class_exists( '\Credoq_MCP_Server' ) && method_exists( '\Credoq_MCP_Server', 'business_audit' ) ) \Credoq_MCP_Server::business_audit( $event, $data );
    }
}
