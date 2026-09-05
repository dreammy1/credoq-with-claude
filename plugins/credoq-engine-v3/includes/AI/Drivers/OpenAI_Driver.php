<?php
/**
 * OpenAI_Driver — OpenAI API implementation (compatible with Grok/DeepSeek).
 *
 * @package CredoqEngine\AI\Drivers
 */

namespace CredoqEngine\AI\Drivers;

use CredoqEngine\AI\AI_Driver;

defined( 'ABSPATH' ) || exit;

class OpenAI_Driver extends AI_Driver {

	private $api_key;
	private $base_url;
	private $model;
	private $slug;
	private $label;

	public function __construct( string $slug = 'openai' ) {
		$s = get_option( 'credoq_engine_settings', [] );
		$this->slug = $slug;

		switch ( $slug ) {
			case 'grok':
				$this->api_key  = $s['ai_grok_key'] ?? '';
				$this->base_url = 'https://api.x.ai/v1';
				$this->model    = 'grok-1';
				$this->label    = 'xAI Grok';
				break;
			case 'deepseek':
				$this->api_key  = $s['ai_deepseek_key'] ?? '';
				$this->base_url = 'https://api.deepseek.com/v1';
				$this->model    = 'deepseek-chat';
				$this->label    = 'DeepSeek';
				break;
			default:
				$this->api_key  = $s['ai_openai_key'] ?? '';
				$this->base_url = 'https://api.openai.com/v1';
				$this->model    = 'gpt-4o';
				$this->label    = 'OpenAI ChatGPT';
				break;
		}
	}

	public function get_slug() : string { return $this->slug; }
	public function get_label() : string { return $this->label; }

	public function chat( string $prompt, array $history = [] ) : array {
		if ( ! $this->api_key ) {
			return [ 'text' => "{$this->label} API key is not configured.", 'history' => $history ];
		}

		$messages = $history;
		$messages[] = [ 'role' => 'user', 'content' => $prompt ];

		$payload = [
			'model'    => $this->model,
			'messages' => $messages,
			'tools'    => $this->get_mcp_tools(),
		];

		return $this->execute_chat_loop( $payload, $messages );
	}

	private function execute_chat_loop( array $payload, array $history ) : array {
		$response = wp_remote_post( $this->base_url . '/chat/completions', [
			'timeout' => 30,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			],
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'text' => "{$this->label} API Error: " . $response->get_error_message(), 'history' => $history ];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$choice = $body['choices'][0] ?? null;
		if ( ! $choice ) {
			return [ 'text' => "{$this->label} API Error: " . ( $body['error']['message'] ?? 'Unknown error' ), 'history' => $history ];
		}

		$msg = $choice['message'];
		$history[] = $msg;

		if ( ! empty( $msg['tool_calls'] ) ) {
			foreach ( $msg['tool_calls'] as $call ) {
				$res = $this->execute_tool( $call['function']['name'], json_decode( $call['function']['arguments'], true ) ?: [] );
				$history[] = [
					'role'    => 'tool',
					'tool_call_id' => $call['id'],
					'content' => wp_json_encode( $res ),
				];
			}
			$new_payload = [
				'model'    => $this->model,
				'messages' => $history,
				'tools'    => $this->get_mcp_tools(),
			];
			return $this->execute_chat_loop( $new_payload, $history );
		}

		return [ 'text' => $msg['content'], 'history' => $history ];
	}

	protected function format_tool( array $mcp_tool ) : array {
		return [
			'type'     => 'function',
			'function' => [
				'name'        => $mcp_tool['name'],
				'description' => $mcp_tool['description'],
				'parameters'  => $mcp_tool['inputSchema'],
			],
		];
	}
}
