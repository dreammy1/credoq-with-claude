<?php
/**
 * Plugin Name: CredoQ MCP Server
 * Description: Authenticated MCP endpoint for scoped AI management of CredoQ plugins.
 * Version: 0.1.0
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
                'serverInfo' => [ 'name' => 'credoq-mcp-server', 'version' => '0.1.0' ],
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
