<?php
/**
 * Plugin Name: CredoQ MCP Server
 * Description: Authenticated MCP endpoint for scoped AI management of CredoQ plugins.
 * Version: 0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: CredoQ
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/Business_Automation.php';

final class Credoq_MCP_Server {
    const NS = 'credoq-mcp/v1';
    const KEY_HASH_OPTION = 'credoq_mcp_key_hash';
    const KEY_META_OPTION = 'credoq_mcp_key_meta';
    const AUDIT_OPTION = 'credoq_mcp_audit_log';
    const CONFIRM_PREFIX = 'credoq_mcp_confirm_';
    // SECURITY FIX: Rate limiting constants
    const RATE_LIMIT_ATTEMPTS = 5;
    const RATE_LIMIT_WINDOW = 300; // 5 minutes
    const RATE_LIMIT_KEY = 'credoq_mcp_rate_limit_';

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
                // SECURITY FIX: health check is public but returns minimal info
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [ __CLASS__, 'handle_jsonrpc' ],
                // SECURITY FIX: Mutation endpoint requires authentication via callback
                'permission_callback' => [ __CLASS__, 'permission_callback' ],
            ],
        ] );
    }

    /**
     * SECURITY FIX: Centralized permission callback that runs BEFORE the mutation handler.
     * This ensures authentication happens at REST framework level.
     */
    public static function permission_callback( WP_REST_Request $request ) {
        $auth = self::authenticate( $request );
        if ( is_wp_error( $auth ) ) {
            return $auth;
        }
        return true;
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

    /**
     * SECURITY FIX: Comprehensive authentication with:
     * - Password hash verification using wp_check_password()
     * - Failed attempt tracking for rate limiting
     * - Audit logging of all failures
     */
    private static function authenticate( WP_REST_Request $request ) {
        $hash = get_option( self::KEY_HASH_OPTION, '' );
        $key  = self::key_from_request( $request );
        $ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

        // SECURITY FIX: Rate limiting check
        $rate_limit_key = self::RATE_LIMIT_KEY . md5( $ip );
        $attempts = (int) get_transient( $rate_limit_key );
        if ( $attempts >= self::RATE_LIMIT_ATTEMPTS ) {
            self::audit( 'rate_limit_exceeded', [ 'ip' => $ip ] );
            return new WP_Error( 'credoq_mcp_rate_limited', 'Too many authentication attempts. Please try again later.', [ 'status' => 429 ] );
        }

        if ( ! $hash || ! $key || ! wp_check_password( $key, $hash ) ) {
            // SECURITY FIX: Increment failed attempt counter
            set_transient( $rate_limit_key, $attempts + 1, self::RATE_LIMIT_WINDOW );
            self::audit( 'unauthorized', [ 'ip' => $ip, 'attempt' => $attempts + 1 ] );
            return new WP_Error( 'credoq_mcp_unauthorized', 'Invalid MCP key.', [ 'status' => 401 ] );
        }

        // SECURITY FIX: Reset rate limit counter on successful auth
        delete_transient( $rate_limit_key );
        return true;
    }

    public static function handle_jsonrpc( WP_REST_Request $request ) {
        // Authentication already verified by permission_callback
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
            [ 'name' => 'credoq_system_status', 'description' => 'Read WordPress, WooCommerce, PHP, and CredoQ plugin status.', 'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass() ] ],
        ];
    }

    private static function call_tool( $id, $params ) {
        $name = isset( $params['name'] ) ? sanitize_key( $params['name'] ) : '';
        $args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : [];

        self::audit( 'tool:' . $name, [ 'args' => array_keys( $args ) ] );
        return self::rpc_result( $id, [ 'status' => 'success', 'tool' => $name ] );
    }

    private static function rpc_result( $id, $result ) {
        return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ], 200 );
    }

    private static function rpc_error( $id, $code, $message ) {
        self::audit( 'rpc_error', [ 'request_id' => $id, 'code' => (int) $code, 'message' => substr( sanitize_text_field( $message ), 0, 180 ) ] );
        return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ], 200 );
    }

    private static function audit( $event, $data ) {
        $safe_event = sanitize_key( $event );
        $safe_data  = is_array( $data ) ? $data : [];
        if ( class_exists( '\\CredoqEngine\\Log\\Audit_Log' ) && method_exists( '\\CredoqEngine\\Log\\Audit_Log', 'record' ) ) {
            \CredoqEngine\Log\Audit_Log::record( 'mcp.' . $safe_event, [
                'subject' => 'CredoQ MCP',
                'message' => substr( 'AI MCP action: ' . $safe_event, 0, 255 ),
                'meta'    => [ 'source' => 'credoq-mcp', 'event' => $safe_event, 'data' => $safe_data ],
            ] );
        }
        $log = get_option( self::AUDIT_OPTION, [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        $log[] = [ 'time' => gmdate( 'c' ), 'event' => $safe_event, 'data' => $safe_data, 'ip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown' ];
        update_option( self::AUDIT_OPTION, array_slice( $log, -200 ), false );
    }

    public static function admin_menu() {
        add_submenu_page( 'credoq', 'CredoQ MCP', 'MCP Connection', 'manage_options', 'credoq-mcp', [ __CLASS__, 'render_admin' ] );
    }

    public static function render_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        $meta = get_option( self::KEY_META_OPTION, [] );
        echo '<div class="wrap"><h1>CredoQ MCP Connection</h1><p>Endpoint: <code>' . esc_html( rest_url( self::NS . '/mcp' ) ) . '</code></p>';
        echo '<p>Configured: <strong>' . ( get_option( self::KEY_HASH_OPTION, '' ) ? 'Yes' : 'No' ) . '</strong></p>';
        echo '</div>';
    }

    public static function generate_key() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'credoq_mcp_generate_key' ) ) {
            wp_die( 'Forbidden' );
        }
        $key = 'cq_' . wp_generate_password( 32, false, false );
        $hash = wp_hash_password( $key );
        update_option( self::KEY_HASH_OPTION, $hash, false );
        update_option( self::KEY_META_OPTION, [ 'rotated_at' => gmdate( 'c' ), 'rotated_by' => get_current_user_id() ], false );
        wp_safe_redirect( add_query_arg( 'generated', '1', admin_url( 'admin.php?page=credoq-mcp' ) ) );
        exit;
    }

    public static function revoke_key() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'credoq_mcp_revoke_key' ) ) {
            wp_die( 'Forbidden' );
        }
        delete_option( self::KEY_HASH_OPTION );
        delete_option( self::KEY_META_OPTION );
        self::audit( 'key_revoked', [ 'by' => get_current_user_id() ] );
        wp_safe_redirect( admin_url( 'admin.php?page=credoq-mcp' ) );
        exit;
    }
}

add_action( 'plugins_loaded', [ 'Credoq_MCP_Server', 'boot' ], 20 );
