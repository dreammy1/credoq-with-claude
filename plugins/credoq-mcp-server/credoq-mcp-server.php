<?php
/**
 * Plugin Name: CredoQ MCP Server
 * Description: Authenticated MCP endpoint for scoped AI management of CredoQ plugins.
 * Version: 0.1.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: CredoQ
 */

defined( 'ABSPATH' ) || exit;

final class Credoq_MCP_Server {
    const NS = 'credoq-mcp/v1';
    const KEY_HASH_OPTION = 'credoq_mcp_key_hash';
    const KEY_META_OPTION = 'credoq_mcp_key_meta';
    const AUDIT_OPTION = 'credoq_mcp_audit_log';
    const CONFIRM_PREFIX = 'credoq_mcp_confirm_';

    public static function boot() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ], 30 );
        add_action( 'admin_post_credoq_mcp_generate_key', [ __CLASS__, 'generate_key' ] );
        add_action( 'admin_post_credoq_mcp_revoke_key', [ __CLASS__, 'revoke_key' ] );
    }

    public static function register_routes() {
        register_rest_route( self::NS, '/mcp', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [ __CLASS__, 'health' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [ __CLASS__, 'handle_jsonrpc' ],
                'permission_callback' => '__return_true',
            ],
        ] );
    }

    public static function health() {
        return new WP_REST_Response([
            'name' => 'CredoQ MCP Server',
            'protocol' => 'MCP Streamable HTTP JSON-RPC',
            'auth' => 'Bearer key or X-CredoQ-MCP-Key',
            'authenticated' => false,
            'message' => 'POST initialize and tools/list for protocol discovery.',
        ], 200);
    }

    private static function key_from_request( WP_REST_Request $request ) {
        $header = $request->get_header( 'authorization' );
        if ( preg_match( '/^Bearer\s+(.+)$/i', (string) $header, $matches ) ) {
            return trim( $matches[1] );
        }
        return trim( (string) $request->get_header( 'x-credoq-mcp-key' ) );
    }

    private static function authenticate( WP_REST_Request $request ) {
        $hash = get_option( self::KEY_HASH_OPTION, '' );
        $key  = self::key_from_request( $request );
        if ( ! $hash || ! $key || ! wp_check_password( $key, $hash ) ) {
            return new WP_Error( 'credoq_mcp_unauthorized', 'Invalid MCP key.', [ 'status' => 401 ] );
        }
        return true;
    }

    public static function handle_jsonrpc( WP_REST_Request $request ) {
        $auth = self::authenticate( $request );
        if ( is_wp_error( $auth ) ) {
            self::audit( 'unauthorized', [ 'method' => $request->get_method() ] );
            return $auth;
        }

        $body = $request->get_json_params();
        if ( ! is_array( $body ) || empty( $body['jsonrpc'] ) || $body['jsonrpc'] !== '2.0' ) {
            return self::rpc_error( null, -32600, 'Invalid JSON-RPC request.' );
        }
        $id     = array_key_exists( 'id', $body ) ? $body['id'] : null;
        $method = isset( $body['method'] ) ? sanitize_text_field( $body['method'] ) : '';
        $params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : [];

        if ( 'initialize' === $method ) {
            self::audit( 'initialize', [] );
            return self::rpc_result( $id, [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [ 'tools' => [ 'listChanged' => false ] ],
                'serverInfo' => [ 'name' => 'credoq-mcp-server', 'version' => '0.1.2' ],
            ] );
        }
        if ( 'notifications/initialized' === $method ) {
            return new WP_REST_Response( null, 202 );
        }
        if ( 'tools/list' === $method ) {
            return self::rpc_result( $id, [ 'tools' => self::tools() ] );
        }
        if ( 'tools/call' === $method ) {
            return self::call_tool( $id, $params );
        }
        return self::rpc_error( $id, -32601, 'Method not found.' );
    }

    private static function tools() {
        return [
            [ 'name' => 'credoq_system_status', 'description' => 'Read WordPress, WooCommerce, PHP, and CredoQ plugin status.', 'inputSchema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_plugin_inventory', 'description' => 'List installed CredoQ plugins, versions, active state, and declared admin surfaces.', 'inputSchema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_list_settings', 'description' => 'List non-secret CredoQ settings currently stored in WordPress.', 'inputSchema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_get_setting', 'description' => 'Read one allowlisted CredoQ setting without mutating WordPress.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'option' => [ 'type' => 'string' ] ], 'required' => [ 'option' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_preview_setting_update', 'description' => 'Preview a settings change and return a one-time confirmation token; no mutation occurs.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'option' => [ 'type' => 'string' ], 'value' => [] ], 'required' => [ 'option', 'value' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_apply_setting_update', 'description' => 'Apply a previously previewed settings change only with its one-time token and explicit confirm=true.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'proposal_id' => [ 'type' => 'string' ], 'confirm_token' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean' ] ], 'required' => [ 'proposal_id', 'confirm_token', 'confirm' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_list_bookings', 'description' => 'List CredoQ appointment bookings with bounded pagination.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ], 'status' => [ 'type' => 'string' ] ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_get_booking', 'description' => 'Read one CredoQ appointment booking by numeric ID.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'id' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_list_services', 'description' => 'List CredoQ appointment services from the appointments catalog.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ] ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_get_service', 'description' => 'Read one CredoQ service/appointment catalog row by numeric ID.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'id' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_list_seat_plans', 'description' => 'List configured CredoQ seat plans.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ] ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_get_seat_plan', 'description' => 'Read a seat plan and its seats by numeric plan ID.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'id' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_propose_booking_update', 'description' => 'Preview a booking status or note change; no database mutation occurs.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ], 'status' => [ 'type' => 'string' ], 'notes' => [ 'type' => 'string' ] ], 'required' => [ 'id' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_propose_service_update', 'description' => 'Preview a service title/price change; no database mutation occurs.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ], 'title' => [ 'type' => 'string' ], 'price' => [ 'type' => 'number', 'minimum' => 0 ] ], 'required' => [ 'id' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_propose_seat_plan_update', 'description' => 'Preview a seat-plan name or capacity change and return a one-time confirmation token.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ], 'name' => [ 'type' => 'string' ], 'capacity' => [ 'type' => 'integer', 'minimum' => 0 ] ], 'required' => [ 'id' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_apply_management_proposal', 'description' => 'Apply a booking, service, or seat-plan proposal only with confirm=true and its one-time token.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'proposal_id' => [ 'type' => 'string' ], 'confirm_token' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean' ] ], 'required' => [ 'proposal_id', 'confirm_token', 'confirm' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_list_payment_gateways', 'description' => 'List enabled WooCommerce gateways and identify non-capturing methods.', 'inputSchema' => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_preview_staging_order', 'description' => 'Preview a staging-only WooCommerce order using COD or BACS; no order is created.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'product_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'quantity' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ], 'payment_method' => [ 'type' => 'string', 'enum' => [ 'cod', 'bacs' ] ], 'billing' => [ 'type' => 'object' ] ], 'required' => [ 'product_id', 'payment_method', 'billing' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_create_staging_order', 'description' => 'Create a pending, non-capturing staging WooCommerce order only after preview token, confirm=true, and explicit staging-order enablement.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'proposal_id' => [ 'type' => 'string' ], 'confirm_token' => [ 'type' => 'string' ], 'confirm' => [ 'type' => 'boolean' ] ], 'required' => [ 'proposal_id', 'confirm_token', 'confirm' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_get_option', 'description' => 'Backward-compatible alias for credoq_get_setting.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'option' => [ 'type' => 'string' ] ], 'required' => [ 'option' ], 'additionalProperties' => false ] ],
            [ 'name' => 'credoq_propose_option_update', 'description' => 'Backward-compatible alias for credoq_preview_setting_update.', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'option' => [ 'type' => 'string' ], 'value' => [] ], 'required' => [ 'option', 'value' ], 'additionalProperties' => false ] ],
        ];
    }

    private static function call_tool( $id, $params ) {
        $name = isset( $params['name'] ) ? sanitize_key( $params['name'] ) : '';
        $args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : [];
        switch ( $name ) {
            case 'credoq_system_status':
                $result = [ 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'woocommerce_active' => class_exists( 'WooCommerce' ), 'site_url' => home_url(), 'mcp_key_configured' => (bool) get_option( self::KEY_HASH_OPTION, '' ) ];
                break;
            case 'credoq_plugin_inventory':
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $all = get_plugins();
                $result = [];
                foreach ( $all as $file => $data ) {
                    if ( stripos( $data['Name'], 'Credoq' ) !== false || stripos( $file, 'credoq' ) !== false ) {
                        $result[] = [ 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'], 'active' => is_plugin_active( $file ) ];
                    }
                }
                break;
            case 'credoq_list_settings':
                $result = self::list_settings();
                break;
            case 'credoq_get_setting':
            case 'credoq_get_option':
                $option = isset( $args['option'] ) ? sanitize_key( $args['option'] ) : '';
                if ( ! self::allowed_option( $option ) ) return self::rpc_error( $id, -32602, 'Option is not allowlisted.' );
                $result = [ 'option' => $option, 'value' => self::redact_value( $option, get_option( $option, null ) ) ];
                break;
            case 'credoq_preview_setting_update':
            case 'credoq_propose_option_update':
                $option = isset( $args['option'] ) ? sanitize_key( $args['option'] ) : '';
                if ( ! self::allowed_option( $option ) ) return self::rpc_error( $id, -32602, 'Option is not allowlisted.' );
                $proposal_id = wp_generate_uuid4();
                $confirm_token = wp_generate_password( 32, false, false );
                set_transient( self::CONFIRM_PREFIX . $proposal_id, [ 'option' => $option, 'value' => $args['value'], 'token' => $confirm_token ], 300 );
                $result = [ 'proposal_id' => $proposal_id, 'status' => 'awaiting_approval', 'option' => $option, 'before' => self::redact_value( $option, get_option( $option, null ) ), 'after' => self::redact_value( $option, $args['value'] ), 'confirm_token' => $confirm_token, 'expires_in' => 300, 'warning' => 'No mutation was performed.' ];
                break;
            case 'credoq_list_bookings':
                $limit = max( 1, min( 100, absint( $args['limit'] ?? 25 ) ) );
                $status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
                $result = self::db_list( 'credoq_bookings', $limit, $status ? [ 'status' => $status ] : [] );
                break;
            case 'credoq_get_booking':
                $result = self::db_get( 'credoq_bookings', absint( $args['id'] ?? 0 ) );
                if ( null === $result ) return self::rpc_error( $id, -32602, 'Booking not found.' );
                break;
            case 'credoq_list_services':
                $result = self::db_list( 'credoq_appointments', max( 1, min( 100, absint( $args['limit'] ?? 25 ) ) ) );
                break;
            case 'credoq_get_service':
                $result = self::db_get( 'credoq_appointments', absint( $args['id'] ?? 0 ) );
                if ( null === $result ) return self::rpc_error( $id, -32602, 'Service not found.' );
                break;
            case 'credoq_list_seat_plans':
                $result = self::db_list( 'credoq_seat_plans', max( 1, min( 100, absint( $args['limit'] ?? 25 ) ) ) );
                break;
            case 'credoq_get_seat_plan':
                $plan = self::db_get( 'credoq_seat_plans', absint( $args['id'] ?? 0 ) );
                if ( null === $plan ) return self::rpc_error( $id, -32602, 'Seat plan not found.' );
                $plan['seats'] = self::db_list( 'credoq_seats', 100, [ 'plan_id' => absint( $args['id'] ?? 0 ) ] );
                $result = $plan;
                break;
            case 'credoq_propose_booking_update':
                $result = self::proposal( 'booking', 'credoq_bookings', $args, [ 'status', 'notes' ] );
                break;
            case 'credoq_propose_service_update':
                $result = self::proposal( 'service', 'credoq_appointments', $args, [ 'title', 'price' ] );
                break;
            case 'credoq_propose_seat_plan_update':
                $result = self::proposal( 'seat_plan', 'credoq_seat_plans', $args, [ 'name', 'capacity' ] );
                break;
            case 'credoq_apply_management_proposal':
                $result = self::apply_management_proposal( $args );
                if ( isset( $result['error'] ) ) return self::rpc_error( $id, -32602, $result['error'] );
                break;
            case 'credoq_list_payment_gateways':
                if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) return self::rpc_error( $id, -32602, 'WooCommerce is not available.' );
                $result = [];
                foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) $result[] = [ 'id' => $gateway->id, 'title' => wp_strip_all_tags( $gateway->get_title() ), 'enabled' => 'yes' === $gateway->enabled, 'non_capturing_allowed' => in_array( $gateway->id, [ 'cod', 'bacs' ], true ) ];
                break;
            case 'credoq_preview_staging_order':
                $result = self::preview_staging_order( $args );
                if ( isset( $result['error'] ) ) return self::rpc_error( $id, -32602, $result['error'] );
                break;
            case 'credoq_create_staging_order':
                $result = self::create_staging_order( $args );
                if ( isset( $result['error'] ) ) return self::rpc_error( $id, -32602, $result['error'] );
                break;
            case 'credoq_apply_setting_update':
                if ( empty( $args['confirm'] ) ) return self::rpc_error( $id, -32602, 'Explicit confirm=true is required.' );
                $proposal_id = sanitize_text_field( $args['proposal_id'] ?? '' );
                $proposal = get_transient( self::CONFIRM_PREFIX . $proposal_id );
                if ( ! is_array( $proposal ) || ! hash_equals( (string) $proposal['token'], (string) ( $args['confirm_token'] ?? '' ) ) ) return self::rpc_error( $id, -32602, 'Invalid or expired confirmation token.' );
                if ( ! self::allowed_option( $proposal['option'] ) ) return self::rpc_error( $id, -32602, 'Option is not allowlisted.' );
                $before = get_option( $proposal['option'], null );
                update_option( $proposal['option'], $proposal['value'], false );
                delete_transient( self::CONFIRM_PREFIX . $proposal_id );
                $result = [ 'status' => 'updated', 'proposal_id' => $proposal_id, 'option' => $proposal['option'], 'before' => self::redact_value( $proposal['option'], $before ), 'after' => self::redact_value( $proposal['option'], get_option( $proposal['option'], null ) ) ];
                break;
            default:
                return self::rpc_error( $id, -32602, 'Unknown or disabled tool.' );
        }
        self::audit( 'tool:' . $name, [ 'args' => array_keys( $args ) ] );
        return self::rpc_result( $id, [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) ] ], 'structuredContent' => $result ] );
    }

    private static function db_table( $table ) {
        global $wpdb;
        $allowed = [ 'credoq_bookings', 'credoq_appointments', 'credoq_seat_plans', 'credoq_seats' ];
        return in_array( $table, $allowed, true ) && isset( $wpdb ) ? $wpdb->prefix . $table : '';
    }

    private static function db_list( $table, $limit = 25, $where = [] ) {
        global $wpdb;
        $full = self::db_table( $table );
        if ( ! $full || ! method_exists( $wpdb, 'get_results' ) ) return [];
        $clauses = [];
        $values = [];
        foreach ( $where as $key => $value ) { if ( ! in_array( $key, [ 'status', 'plan_id' ], true ) ) continue; $clauses[] = "`{$key}` = %s"; $values[] = (string) $value; }
        $sql = "SELECT * FROM {$full}" . ( $clauses ? ' WHERE ' . implode( ' AND ', $clauses ) : '' ) . ' ORDER BY id DESC LIMIT %d';
        $values[] = $limit;
        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
    }

    private static function db_get( $table, $id ) {
        global $wpdb;
        $full = self::db_table( $table );
        if ( ! $full || ! $id || ! method_exists( $wpdb, 'get_row' ) ) return null;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$full} WHERE id = %d", $id ), ARRAY_A ) ?: null;
    }

    private static function proposal( $type, $table, $args, $fields ) {
        $id = absint( $args['id'] ?? 0 );
        $before = self::db_get( $table, $id );
        if ( null === $before ) return [ 'status' => 'not_found', 'type' => $type, 'id' => $id, 'warning' => 'No mutation was performed.' ];
        $after = [];
        foreach ( $fields as $field ) if ( array_key_exists( $field, $args ) ) $after[ $field ] = is_string( $args[ $field ] ) ? sanitize_text_field( $args[ $field ] ) : $args[ $field ];
        $proposal_id = wp_generate_uuid4(); $token = wp_generate_password( 32, false, false );
        set_transient( self::CONFIRM_PREFIX . $proposal_id, [ 'kind' => $type, 'table' => $table, 'id' => $id, 'changes' => $after, 'token' => $token ], 300 );
        return [ 'proposal_id' => $proposal_id, 'confirm_token' => $token, 'expires_in' => 300, 'status' => 'awaiting_approval', 'type' => $type, 'id' => $id, 'before' => $before, 'requested_changes' => $after, 'warning' => 'No mutation was performed.' ];
    }

    private static function apply_management_proposal( $args ) {
        if ( empty( $args['confirm'] ) ) return [ 'error' => 'Explicit confirm=true is required.' ];
        $id = sanitize_text_field( $args['proposal_id'] ?? '' ); $proposal = get_transient( self::CONFIRM_PREFIX . $id );
        if ( ! is_array( $proposal ) || ! hash_equals( (string) $proposal['token'], (string) ( $args['confirm_token'] ?? '' ) ) ) return [ 'error' => 'Invalid or expired confirmation token.' ];
        $changes = $proposal['changes']; $ok = false;
        if ( 'booking' === $proposal['kind'] ) {
            $allowed_statuses = [ 'confirmed', 'pending_payment', 'cancelled', 'failed', 'refunded' ];
            if ( isset( $changes['status'] ) && ! in_array( $changes['status'], $allowed_statuses, true ) ) return [ 'error' => 'Booking status is not allowed.' ];
            global $wpdb; $safe = array_intersect_key( $changes, array_flip( [ 'status', 'notes' ] ) );
            if ( $safe && isset( $wpdb ) && method_exists( $wpdb, 'update' ) ) $ok = false !== $wpdb->update( self::db_table( 'credoq_bookings' ), $safe, [ 'id' => (int) $proposal['id'] ] );
        } elseif ( in_array( $proposal['kind'], [ 'service', 'seat_plan' ], true ) ) { global $wpdb; $allowed = 'service' === $proposal['kind'] ? [ 'title', 'price' ] : [ 'name', 'capacity' ]; $safe = array_intersect_key( $changes, array_flip( $allowed ) ); if ( $safe ) $ok = (bool) $wpdb->update( self::db_table( $proposal['table'] ), $safe, [ 'id' => (int) $proposal['id'] ] ); }
        if ( ! $ok ) return [ 'error' => 'The typed repository update was not applied.' ];
        delete_transient( self::CONFIRM_PREFIX . $id ); self::audit( 'management_update:' . $proposal['kind'], [ 'id' => $proposal['id'], 'fields' => array_keys( $changes ) ] );
        return [ 'status' => 'updated', 'proposal_id' => $id, 'type' => $proposal['kind'], 'id' => (int) $proposal['id'], 'changed_fields' => array_keys( $changes ) ];
    }

    private static function preview_staging_order( $args ) {
        if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'WC' ) ) return [ 'error' => 'WooCommerce is not available.' ];
        $method = sanitize_key( $args['payment_method'] ?? '' ); if ( ! in_array( $method, [ 'cod', 'bacs' ], true ) ) return [ 'error' => 'Only COD or BACS is allowed for MCP staging orders.' ];
        $product = wc_get_product( absint( $args['product_id'] ?? 0 ) ); if ( ! $product ) return [ 'error' => 'Product not found.' ];
        $billing = is_array( $args['billing'] ?? null ) ? $args['billing'] : []; if ( empty( $billing['email'] ) || ! is_email( $billing['email'] ) ) return [ 'error' => 'A valid synthetic billing email is required.' ];
        $proposal_id = wp_generate_uuid4(); $token = wp_generate_password( 32, false, false ); $qty = max( 1, min( 10, absint( $args['quantity'] ?? 1 ) ) );
        set_transient( self::CONFIRM_PREFIX . $proposal_id, [ 'kind' => 'staging_order', 'token' => $token, 'product_id' => $product->get_id(), 'quantity' => $qty, 'payment_method' => $method, 'billing' => array_map( 'sanitize_text_field', $billing ) ], 300 );
        return [ 'status' => 'awaiting_approval', 'proposal_id' => $proposal_id, 'confirm_token' => $token, 'product_id' => $product->get_id(), 'product_name' => $product->get_name(), 'quantity' => $qty, 'payment_method' => $method, 'estimated_total' => (float) $product->get_price() * $qty, 'expires_in' => 300, 'warning' => 'No order was created.' ];
    }

    private static function create_staging_order( $args ) {
        if ( empty( $args['confirm'] ) ) return [ 'error' => 'Explicit confirm=true is required.' ];
        if ( ! defined( 'CREDOQ_MCP_STAGING_MODE' ) || true !== CREDOQ_MCP_STAGING_MODE ) return [ 'error' => 'Staging order creation requires CREDOQ_MCP_STAGING_MODE=true in staging wp-config.php.' ];
        if ( function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type() ) return [ 'error' => 'Order creation is disabled in production environment.' ];
        if ( ! get_option( 'credoq_mcp_enable_staging_orders', false ) ) return [ 'error' => 'Staging order creation is disabled. Enable credoq_mcp_enable_staging_orders explicitly on staging.' ];
        $id = sanitize_text_field( $args['proposal_id'] ?? '' ); $p = get_transient( self::CONFIRM_PREFIX . $id );
        if ( ! is_array( $p ) || 'staging_order' !== $p['kind'] || ! hash_equals( (string) $p['token'], (string) ( $args['confirm_token'] ?? '' ) ) ) return [ 'error' => 'Invalid or expired order confirmation token.' ];
        if ( ! function_exists( 'wc_create_order' ) ) return [ 'error' => 'WooCommerce order API is not available.' ];
        $order = wc_create_order(); $product = wc_get_product( $p['product_id'] ); if ( ! $order || ! $product ) return [ 'error' => 'Order or product creation failed.' ];
        $order->add_product( $product, $p['quantity'] ); foreach ( $p['billing'] as $key => $value ) { $setter = 'set_' . $key; if ( is_callable( [ $order, $setter ] ) ) $order->$setter( $value ); }
        $order->set_payment_method( $p['payment_method'] ); $order->set_payment_method_title( 'cod' === $p['payment_method'] ? 'Cash on delivery' : 'Direct bank transfer' ); $order->calculate_totals(); $order->update_status( 'pending', 'CredoQ MCP staging audit order.' ); $order->add_order_note( 'AUDIT TEST — created by CredoQ MCP staging tool.' ); $order->save(); delete_transient( self::CONFIRM_PREFIX . $id ); self::audit( 'staging_order_created', [ 'order_id' => $order->get_id(), 'payment_method' => $p['payment_method'] ] ); return [ 'status' => 'created', 'order_id' => $order->get_id(), 'status_after_creation' => $order->get_status(), 'payment_method' => $p['payment_method'], 'captured' => false ];
    }

    private static function list_settings() {
        global $wpdb;
        $names = [];
        if ( isset( $wpdb ) && method_exists( $wpdb, 'get_col' ) ) $names = (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'credoq%' ORDER BY option_name" );
        $result = [];
        foreach ( $names as $name ) $result[] = [ 'option' => $name, 'value' => self::redact_value( $name, get_option( $name, null ) ) ];
        return [ 'count' => count( $result ), 'settings' => $result ];
    }

    private static function redact_value( $option, $value ) {
        if ( preg_match( '/(key|token|secret|password|credential)/i', (string) $option ) ) return '[redacted]';
        return $value;
    }

    private static function allowed_option( $option ) {
        $prefixes = [ 'credoq_', 'credoq' ];
        foreach ( $prefixes as $prefix ) if ( 0 === strpos( $option, $prefix ) ) return true;
        return false;
    }

    private static function rpc_result( $id, $result ) { return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ], 200 ); }
    private static function rpc_error( $id, $code, $message ) { return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ], 200 ); }

    private static function audit( $event, $data ) {
        $log = get_option( self::AUDIT_OPTION, [] );
        if ( ! is_array( $log ) ) $log = [];
        $log[] = [ 'time' => gmdate( 'c' ), 'event' => sanitize_key( $event ), 'data' => $data, 'ip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ];
        update_option( self::AUDIT_OPTION, array_slice( $log, -200 ), false );
    }

    public static function admin_menu() { add_submenu_page( 'credoq', 'CredoQ MCP', 'MCP Connection', 'manage_options', 'credoq-mcp', [ __CLASS__, 'render_admin' ] ); }
    public static function render_admin() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        $meta = get_option( self::KEY_META_OPTION, [] );
        echo '<div class="wrap"><h1>CredoQ MCP Connection</h1><p>Endpoint: <code>' . esc_html( rest_url( self::NS . '/mcp' ) ) . '</code></p>';
        if ( ! empty( $_GET['generated'] ) ) { $notice_key = 'credoq_mcp_notice_' . get_current_user_id(); $generated = get_transient( $notice_key ); delete_transient( $notice_key ); if ( $generated ) echo '<div class="notice notice-warning"><p>Copy the new key now. It will not be shown again: <code>' . esc_html( $generated ) . '</code></p></div>'; }
        echo '<p>Configured: <strong>' . ( get_option( self::KEY_HASH_OPTION, '' ) ? 'Yes' : 'No' ) . '</strong></p><p>Last rotation: ' . esc_html( isset( $meta['rotated_at'] ) ? $meta['rotated_at'] : 'Never' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="credoq_mcp_generate_key">' . wp_nonce_field( 'credoq_mcp_generate_key', '_wpnonce', true, false ) . '<p><button class="button button-primary">Generate / rotate MCP key</button></p></form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="credoq_mcp_revoke_key">' . wp_nonce_field( 'credoq_mcp_revoke_key', '_wpnonce', true, false ) . '<p><button class="button">Revoke key</button></p></form></div>';
    }
    public static function generate_key() { if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'credoq_mcp_generate_key' ) ) wp_die( 'Forbidden' ); $key = 'cq_' . wp_generate_password( 48, false, false ); update_option( self::KEY_HASH_OPTION, wp_hash_password( $key ), false ); update_option( self::KEY_META_OPTION, [ 'rotated_at' => gmdate( 'c' ) ], false ); $notice_key = 'credoq_mcp_notice_' . get_current_user_id(); set_transient( $notice_key, $key, 120 ); wp_safe_redirect( add_query_arg( [ 'page' => 'credoq-mcp', 'generated' => '1' ], admin_url( 'admin.php' ) ) ); exit; }
    public static function revoke_key() { if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'credoq_mcp_revoke_key' ) ) wp_die( 'Forbidden' ); delete_option( self::KEY_HASH_OPTION ); delete_option( self::KEY_META_OPTION ); wp_safe_redirect( admin_url( 'admin.php?page=credoq-mcp' ) ); exit; }
}

add_action( 'plugins_loaded', [ 'Credoq_MCP_Server', 'boot' ], 20 );
