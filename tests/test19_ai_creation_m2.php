<?php
/**
 * Test: M2 AI Administrative Tool Expansion
 */

namespace CredoqMembership {
    class Plan_Repository {
        public function save($data) {
            global $wpdb;
            $wpdb->insert('credoq_membership_plans', $data);
            return $wpdb->insert_id;
        }
    }
}

namespace {

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/wp_stubs.php';

// Mock DB & Options
global $wpdb, $__options;
$wpdb = new FakeWPDB();
$wpdb->prefix = 'wp_';

$__options = [
    'credoq_engine_settings' => ['ai_admin_provider' => 'test_driver'],
    'credoq_mcp_key_hash'    => wp_hash_password('test-key')
];

// Mock Request/Response classes
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $params; private $headers = [];
        public function __construct($m, $r) {}
        public function set_header($k, $v) { $this->headers[strtolower($k)] = $v; }
        public function get_header($k) { return $this->headers[strtolower($k)] ?? null; }
        public function set_body($b) { $this->params = json_decode($b, true); }
        public function get_json_params() { return $this->params; }
        public function get_method() { return 'POST'; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $data;
        public function __construct($d) { $this->data = $d; }
        public function get_data() { return $this->data; }
    }
}

// Load MCP Server (which includes Admin_Expansion.php)
require_once PLUGINS_DIR . '/credoq-mcp-server/credoq-mcp-server.php';

echo "Testing AI Propose & Create (M2)...\n";

// 1. Propose creation
$proposal_rpc = [
    'jsonrpc' => '2.0',
    'id'      => 1,
    'method'  => 'tools/call',
    'params'  => [
        'name'      => 'credoq_propose_creation',
        'arguments' => [
            'resource' => 'membership_plan',
            'data'     => [
                'name'          => 'AI Monthly Plan',
                'duration_days' => 30,
                'slot_credit'   => 5
            ]
        ]
    ]
];

$request = new WP_REST_Request('POST', '/credoq-mcp/v1/mcp');
$request->set_header('X-CredoQ-MCP-Key', 'test-key');
$request->set_body(json_encode($proposal_rpc));

echo "Phase 1: Proposing Membership Plan...\n";
$response = Credoq_MCP_Server::handle_jsonrpc($request);
if (is_wp_error($response)) {
    echo "❌ ERROR: " . $response->get_error_message() . "\n";
    exit;
}
$data = $response->get_data();

if (isset($data['result']['structuredContent']['proposal_id'])) {
    $proposal_id = $data['result']['structuredContent']['proposal_id'];
    $token       = $data['result']['structuredContent']['confirm_token'];
    echo "✅ SUCCESS: Proposal created (ID: $proposal_id)\n";
} else {
    echo "❌ FAILURE: Proposal creation failed.\n";
    print_r($data);
    exit;
}

// 2. Apply the creation
$apply_rpc = [
    'jsonrpc' => '2.0',
    'id'      => 2,
    'method'  => 'tools/call',
    'params'  => [
        'name'      => 'credoq_apply_creation',
        'arguments' => [
            'proposal_id'   => $proposal_id,
            'confirm_token' => $token,
            'confirm'       => true
        ]
    ]
];

$request->set_body(json_encode($apply_rpc));

echo "Phase 2: Applying Proposal...\n";
$response = Credoq_MCP_Server::handle_jsonrpc($request);
$data = $response->get_data();

if (isset($data['result']['structuredContent']['status']) && $data['result']['structuredContent']['status'] === 'created') {
    $new_id = $data['result']['structuredContent']['id'];
    echo "✅ SUCCESS: Plan created with ID $new_id\n";
} else {
    echo "❌ FAILURE: Applying proposal failed.\n";
    print_r($data);
}

} // end global namespace
