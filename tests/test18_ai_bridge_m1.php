<?php
/**
 * Test: M1 AI Bridge Orchestration
 *
 * Verifies that the AI_Bridge correctly routes requests to drivers
 * and handles tool execution.
 */

define('ABSPATH', '/tmp/');

// Mock global functions before any includes
function get_option($k, $d = []) { 
    if ($k === 'credoq_engine_settings') return [
        'ai_admin_provider' => 'test_driver'
    ];
    return $d;
}
function sanitize_key($s) { return $s; }
function sanitize_text_field($s) { return $s; }
function wp_json_encode($v) { return json_encode($v); }
function current_time($f) { return date('Y-m-d H:i:s'); }
function add_action($t, $c) {}
function apply_filters($t, $v) { return $v; }

// Load Bridge & Abstracts (bypass autoloader for direct testing)
require_once __DIR__ . '/../plugins/credoq-engine-v3/includes/AI/AI_Driver.php';
require_once __DIR__ . '/../plugins/credoq-engine-v3/includes/AI/Drivers/Gemini_Driver.php';
require_once __DIR__ . '/../plugins/credoq-engine-v3/includes/AI/Drivers/Claude_Driver.php';
require_once __DIR__ . '/../plugins/credoq-engine-v3/includes/AI/Drivers/OpenAI_Driver.php';
require_once __DIR__ . '/../plugins/credoq-engine-v3/includes/AI/AI_Bridge.php';

use CredoqEngine\AI\AI_Bridge;
use CredoqEngine\AI\AI_Driver;

// 1. Create a Mock Driver
class Mock_Driver extends AI_Driver {
    public function get_slug() : string { return 'test_driver'; }
    public function get_label() : string { return 'Test Driver'; }
    public function chat(string $prompt, array $history = [], string $context = 'admin') : array {
        if ($prompt === 'ping') return ['text' => 'pong', 'history' => $history];
        if ($prompt === 'call_tool') {
            $res = $this->execute_tool('credoq_system_status', []);
            return ['text' => 'Status: ' . ($res['wordpress'] ?? 'error'), 'history' => $history];
        }
        return ['text' => 'hello', 'history' => $history];
    }
    protected function format_tool(array $mcp_tool) : array { return $mcp_tool; }
}

// 2. Mock MCP Server for tool execution
class Credoq_MCP_Server {
    public static function tools() {
        return [['name' => 'credoq_system_status', 'description' => 'test', 'inputSchema' => []]];
    }
    public static function call_tool($id, $params) {
        return new class {
            public function get_data() {
                return ['result' => ['wordpress' => '6.4.1']];
            }
        };
    }
}

$bridge = AI_Bridge::instance();
$bridge->register_driver(new Mock_Driver());

echo "Testing AI Bridge Routing...\n";
echo "Active Slug: " . $bridge->get_active_driver_slug() . "\n";

// Test A: Basic Chat
$res = $bridge->chat('ping');
echo "Prompt: ping, Response: " . $res['text'] . "\n";
if ($res['text'] === 'pong') {
    echo "✅ SUCCESS: Basic routing works.\n";
} else {
    echo "❌ FAILURE: Basic routing failed.\n";
}

// Test B: Tool Execution via Bridge
$res = $bridge->chat('call_tool');
echo "Prompt: call_tool, Response: " . $res['text'] . "\n";
if (str_contains($res['text'], '6.4.1')) {
    echo "✅ SUCCESS: Tool execution via bridge works.\n";
} else {
    echo "❌ FAILURE: Tool execution via bridge failed.\n";
}
