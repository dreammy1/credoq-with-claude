<?php
/**
 * Gemini_Driver — Google Gemini API implementation.
 *
 * @package CredoqEngine\AI\Drivers
 */

namespace CredoqEngine\AI\Drivers;

use CredoqEngine\AI\AI_Driver;

defined( 'ABSPATH' ) || exit;

class Gemini_Driver extends AI_Driver {

	private $api_key;
	private $model = 'gemini-1.5-pro';

	public function __construct() {
		$s = get_option( 'credoq_engine_settings', [] );
		$this->api_key = $s['ai_gemini_key'] ?? '';
	}

	public function get_slug() : string { return 'gemini'; }
	public function get_label() : string { return 'Google Gemini'; }

	public function chat( string $prompt, array $history = [] ) : array {
		if ( ! $this->api_key ) {
			return [ 'text' => 'Gemini API key is not configured.', 'history' => $history ];
		}

		$tools = $this->get_mcp_tools();
		
		// Build the request payload
		$contents = $history;
		$contents[] = [ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ];

		$payload = [
			'contents' => $contents,
			'tools'    => [ [ 'function_declarations' => $tools ] ],
		];

		return $this->execute_chat_loop( $payload, $contents );
	}

	private function execute_chat_loop( array $payload, array $history ) : array {
		$url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->api_key}";

		$response = wp_remote_post( $url, [
			'timeout' => 30,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'text' => 'Gemini API Error: ' . $response->get_error_message(), 'history' => $history ];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$candidate = $body['candidates'][0] ?? null;
		if ( ! $candidate ) {
			return [ 'text' => 'Gemini API returned an empty response.', 'history' => $history ];
		}

		$msg = $candidate['content'];
		$history[] = $msg;

		// Check for function calls
		$parts = $msg['parts'] ?? [];
		$tool_results = [];
		foreach ( $parts as $part ) {
			if ( isset( $part['functionCall'] ) ) {
				$call = $part['functionCall'];
				$res = $this->execute_tool( $call['name'], $call['args'] ?? [] );
				$tool_results[] = [
					'functionResponse' => [
						'name'     => $call['name'],
						'response' => [ 'name' => $call['name'], 'content' => $res ],
					]
				];
			}
		}

		if ( ! empty( $tool_results ) ) {
			// We have results, need to send them back to Gemini to get the final text response.
			$history[] = [ 'role' => 'function', 'parts' => $tool_results ];
			$new_payload = [
				'contents' => $history,
				'tools'    => [ [ 'function_declarations' => $this->get_mcp_tools() ] ],
			];
			return $this->execute_chat_loop( $new_payload, $history );
		}

		// No more tool calls, return the final text
		$text = '';
		foreach ( $parts as $part ) {
			if ( isset( $part['text'] ) ) $text .= $part['text'];
		}

		return [ 'text' => $text, 'history' => $history ];
	}

	protected function format_tool( array $mcp_tool ) : array {
		return [
			'name'        => $mcp_tool['name'],
			'description' => $mcp_tool['description'],
			'parameters'  => $mcp_tool['inputSchema'],
		];
	}
}
