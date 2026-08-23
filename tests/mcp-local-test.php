<?php
// Local protocol test only. This file does not contact WordPress or the network.
const ABSPATH = '/tmp/wordpress/';
$options = [ 'credoq_demo_setting' => [ 'enabled' => false ], 'credoq_mcp_key_hash' => password_hash( 'local-test-key', PASSWORD_DEFAULT ) ];
$transients = [];
function add_action( $a, $b, $c = 10 ) {}
function add_submenu_page( ...$args ) {}
function register_rest_route( ...$args ) {}
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[$key] : $default; }
function update_option( $key, $value, $autoload = true ) { global $options; $options[$key] = $value; return true; }
function delete_option( $key ) { global $options; unset( $options[$key] ); }
function set_transient( $key, $value, $ttl ) { global $transients; $transients[$key] = $value; return true; }
function get_transient( $key ) { global $transients; return $transients[$key] ?? false; }
function delete_transient( $key ) { global $transients; unset( $transients[$key] ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_check_password( $key, $hash ) { return password_verify( $key, $hash ); }
function wp_generate_uuid4() { return 'proposal-' . bin2hex( random_bytes( 4 ) ); }
function wp_generate_password( $length = 16, $special = true, $extra = true ) { return substr( bin2hex( random_bytes( 32 ) ), 0, $length ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function home_url() { return 'http://local.test'; }
function rest_url( $path = '' ) { return 'http://local.test/wp-json/' . ltrim( $path, '/' ); }
function current_user_can( $cap ) { return true; }
function admin_url( $path = '' ) { return 'http://local.test/wp-admin/' . ltrim( $path, '/' ); }
function wp_nonce_field( ...$args ) { return ''; }
function check_admin_referer( ...$args ) { return true; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function wp_safe_redirect( $url ) {}
function esc_html( $v ) { return $v; }
function esc_url( $v ) { return $v; }
function is_plugin_active( $file ) { return false; }
function get_plugins() { return []; }
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_Error { public $code; public $message; public $data; function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; } function get_error_code() { return $this->code; } }
class WP_REST_Response { public $data; public $status; function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; } function get_data() { return $this->data; } function get_status() { return $this->status; } }
class WP_REST_Request { private $headers; private $body; function __construct( $body, $headers = [] ) { $this->body = $body; $this->headers = array_change_key_case( $headers, CASE_LOWER ); } function get_header( $key ) { return $this->headers[strtolower( $key )] ?? ''; } function get_json_params() { return $this->body; } function get_method() { return 'POST'; } }
require __DIR__ . '/../plugins/credoq-mcp-server/credoq-mcp-server.php';
function assert_true( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( 'FAIL: ' . $message ); echo "PASS: {$message}\n"; }
function rpc( $body, $key = 'local-test-key' ) { return Credoq_MCP_Server::handle_jsonrpc( new WP_REST_Request( $body, [ 'Authorization' => 'Bearer ' . $key ] ) ); }

$unauth = Credoq_MCP_Server::handle_jsonrpc( new WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize' ] ) );
assert_true( $unauth instanceof WP_Error && $unauth->get_error_code() === 'credoq_mcp_unauthorized', 'invalid key is rejected' );
$init = rpc( [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => [] ] );
assert_true( $init->get_status() === 200 && $init->get_data()['result']['serverInfo']['name'] === 'credoq-mcp-server', 'JSON-RPC initialize succeeds' );
$list = rpc( [ 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => [] ] );
$tool_list = json_decode( wp_json_encode( $list->get_data()['result']['tools'] ) );
$names = array_map( function ( $tool ) { return $tool->name; }, $tool_list );
foreach ( $tool_list as $tool ) {
    assert_true( isset( $tool->inputSchema->properties ) && is_object( $tool->inputSchema->properties ), $tool->name . ' uses object-shaped JSON Schema properties' );
}
assert_true( in_array( 'credoq_list_settings', $names, true ) && in_array( 'credoq_apply_setting_update', $names, true ), 'settings tools are discoverable' );
assert_true( in_array( 'credoq_list_bookings', $names, true ) && in_array( 'credoq_list_services', $names, true ) && in_array( 'credoq_list_seat_plans', $names, true ), 'booking, service, and seat tools are discoverable' );
assert_true( in_array( 'credoq_propose_booking_update', $names, true ) && in_array( 'credoq_propose_service_update', $names, true ) && in_array( 'credoq_propose_seat_plan_update', $names, true ), 'management proposal tools are discoverable' );
assert_true( in_array( 'credoq_apply_management_proposal', $names, true ), 'confirmed management write tool is discoverable' );
assert_true( in_array( 'credoq_list_payment_gateways', $names, true ) && in_array( 'credoq_preview_staging_order', $names, true ) && in_array( 'credoq_create_staging_order', $names, true ), 'payment and staging-order tools are discoverable' );
$read = rpc( [ 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => [ 'name' => 'credoq_get_setting', 'arguments' => [ 'option' => 'credoq_demo_setting' ] ] ] );
assert_true( $read->get_data()['result']['structuredContent']['value']['enabled'] === false, 'allowlisted setting can be read' );
$preview = rpc( [ 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => [ 'name' => 'credoq_preview_setting_update', 'arguments' => [ 'option' => 'credoq_demo_setting', 'value' => [ 'enabled' => true ] ] ] ] );
$proposal = $preview->get_data()['result']['structuredContent'];
assert_true( $proposal['status'] === 'awaiting_approval' && get_option( 'credoq_demo_setting' )['enabled'] === false, 'preview does not mutate settings' );
$apply = rpc( [ 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => [ 'name' => 'credoq_apply_setting_update', 'arguments' => [ 'proposal_id' => $proposal['proposal_id'], 'confirm_token' => $proposal['confirm_token'], 'confirm' => true ] ] ] );
assert_true( $apply->get_data()['result']['structuredContent']['status'] === 'updated' && get_option( 'credoq_demo_setting' )['enabled'] === true, 'confirmed setting update mutates only after explicit confirmation' );
$replay = rpc( [ 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => [ 'name' => 'credoq_apply_setting_update', 'arguments' => [ 'proposal_id' => $proposal['proposal_id'], 'confirm_token' => $proposal['confirm_token'], 'confirm' => true ] ] ] );
assert_true( $replay->get_data()['error']['code'] === -32602, 'confirmation token is one-time and replay is rejected' );
echo "ALL LOCAL MCP TESTS PASSED\n";
