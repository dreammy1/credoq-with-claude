<?php
/**
 * Claude_Driver — Anthropic Claude API implementation.
 *
 * @package CredoqEngine\AI\Drivers
 */

namespace CredoqEngine\AI\Drivers;

use CredoqEngine\AI\AI_Driver;

defined( 'ABSPATH' ) || exit;

class Claude_Driver extends AI_Driver {

	private $api_key;
	private $model = 'claude-3-5-sonnet-20240620';

	public function __construct() {
		$s = get_option( 'credoq_engine_settings', [] );
		$this->api_key = $s['ai_claude_key'] ?? '';
	}

	public function get_slug() : string { return 'claude'; }
	public function get_label() : string { return 'Anthropic Claude'; }

	public function chat( string $prompt, array $history = [] ) : array {
		if ( ! $this->api_key ) {
			return [ 'text' => 'Claude API key is not configured.', 'history' => $history ];
		}

		$messages = $history;
		$messages[] = [ 'role' => 'user', 'content' => $prompt ];

		$payload = [
			'model'      => $this->model,
			'messages'   => $messages,
			'max_tokens' => 4096,
			'tools'      => $this->get_mcp_tools(),
		];

		return $this->execute_chat_loop( $payload, $messages );
	}

	private function execute_chat_loop( array $payload, array $history ) : array {
		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => 30,
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
			],
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'text' => 'Claude API Error: ' . $response->get_error_message(), 'history' => $history ];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['content'] ) ) {
			return [ 'text' => 'Claude API Error: ' . ( $body['error']['message'] ?? 'Unknown error' ), 'history' => $history ];
		}

		$content = $body['content'];
		$history[] = [ 'role' => 'assistant', 'content' => $content ];

		$tool_results = [];
		foreach ( $content as $part ) {
			if ( $part['type'] === 'tool_use' ) {
				$res = $this->execute_tool( $part['name'], $part['input'] ?? [] );
				$tool_results[] = [
					'type'      => 'tool_result',
					'tool_use_id' => $part['id'],
					'content'   => wp_json_encode( $res ),
				];
			}
		}

		if ( ! empty( $tool_results ) ) {
			$history[] = [ 'role' => 'user', 'content' => $tool_results ];
			$new_payload = [
				'model'    => $this->model,
				'messages' => $history,
				'max_tokens' => 4096,
				'tools'    => $this->get_mcp_tools(),
			];
			return $this->execute_chat_loop( $new_payload, $history );
		}

		// Extract text response
		$text = '';
		foreach ( $content as $part ) {
			if ( $part['type'] === 'text' ) $text .= $part['text'];
		}

		return [ 'text' => $text, 'history' => $history ];
	}

	protected function format_tool( array $mcp_tool ) : array {
		return [
			'name'        => $mcp_tool['name'],
			'description' => $mcp_tool['description'],
			'input_schema' => $mcp_tool['inputSchema'],
		];
	}
}
