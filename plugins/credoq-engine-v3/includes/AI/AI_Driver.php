<?php
/**
 * AI_Driver — Abstract base for LLM provider drivers.
 *
 * @package CredoqEngine\AI
 */

namespace CredoqEngine\AI;

defined( 'ABSPATH' ) || exit;

abstract class AI_Driver {

	/**
	 * Get the unique slug for this provider (e.g. 'gemini', 'claude').
	 */
	abstract public function get_slug() : string;

	/**
	 * Get the human-readable label.
	 */
	abstract public function get_label() : string;

	/**
	 * Send a message to the AI and handle tool calls.
	 *
	 * @param string $prompt The user prompt.
	 * @param array  $history Optional conversation history.
	 * @return array { text: string, history: array }
	 */
	abstract public function chat( string $prompt, array $history = [] ) : array;

	/**
	 * Convert a standard MCP tool definition to the provider's native function-calling format.
	 */
	abstract protected function format_tool( array $mcp_tool ) : array;

	/**
	 * Fetch available tools from the CredoQ MCP Server.
	 */
	protected function get_mcp_tools() : array {
		if ( ! class_exists( 'Credoq_MCP_Server' ) ) return [];
		
		// Use reflection to get the private tools() result if needed, 
		// or ideally Credoq_MCP_Server provides a public method.
		$ref = new \ReflectionClass( 'Credoq_MCP_Server' );
		if ( ! $ref->hasMethod( 'tools' ) ) return [];
		
		$method = $ref->getMethod( 'tools' );
		$method->setAccessible( true );
		$tools = $method->invoke( null );

		$formatted = [];
		foreach ( $tools as $tool ) {
			$formatted[] = $this->format_tool( $tool );
		}
		return $formatted;
	}

	/**
	 * Execute a tool call locally via the MCP Server.
	 */
	protected function execute_tool( string $name, array $args ) : array {
		if ( ! class_exists( 'Credoq_MCP_Server' ) ) {
			return [ 'error' => 'MCP Server not found.' ];
		}

		$ref = new \ReflectionClass( 'Credoq_MCP_Server' );
		$method = $ref->getMethod( 'call_tool' );
		$method->setAccessible( true );

		// call_tool( $id, $params )
		// $params = [ 'name' => ..., 'arguments' => [...] ]
		$response = $method->invoke( null, 'ai_bridge_' . time(), [
			'name'      => $name,
			'arguments' => $args
		] );

		// MCP Server returns a WP_REST_Response with JSON-RPC shape
		$data = $response->get_data();
		if ( isset( $data['result'] ) ) {
			return $data['result'];
		}
		if ( isset( $data['error'] ) ) {
			return [ 'error' => $data['error']['message'] ?? 'Unknown tool error.' ];
		}

		return [ 'error' => 'Invalid response from MCP Server.' ];
	}
}
