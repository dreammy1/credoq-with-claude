<?php
/**
 * AI_Bridge — Orchestrator for AI drivers and Command Center logic.
 *
 * @package CredoqEngine\AI
 */

namespace CredoqEngine\AI;

use CredoqEngine\AI\Drivers\Gemini_Driver;
use CredoqEngine\AI\Drivers\Claude_Driver;
use CredoqEngine\AI\Drivers\OpenAI_Driver;

defined( 'ABSPATH' ) || exit;

class AI_Bridge {

	private static $instance = null;
	private $drivers = [];

	private function __construct() {
		$this->register_driver( new Gemini_Driver() );
		$this->register_driver( new Claude_Driver() );
		$this->register_driver( new OpenAI_Driver( 'openai' ) );
		$this->register_driver( new OpenAI_Driver( 'grok' ) );
		$this->register_driver( new OpenAI_Driver( 'deepseek' ) );
	}

	public static function instance() : self {
		if ( ! self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	public function register_driver( AI_Driver $driver ) : void {
		$this->drivers[ $driver->get_slug() ] = $driver;
	}

	public function get_driver( string $slug ) : ?AI_Driver {
		return $this->drivers[ $slug ] ?? null;
	}

	public function get_active_driver_slug( string $context = 'admin' ) : string {
		$s = get_option( 'credoq_engine_settings', [] );
		$key = ( $context === 'admin' ) ? 'ai_admin_provider' : 'ai_customer_provider';
		return $s[ $key ] ?? 'gemini';
	}

	public function get_active_driver( string $context = 'admin' ) : ?AI_Driver {
		return $this->get_driver( $this->get_active_driver_slug( $context ) );
	}

	/**
	 * Main chat entry point.
	 */
	public function chat( string $prompt, array $history = [], string $context = 'admin' ) : array {
		$driver = $this->get_active_driver( $context );
		if ( ! $driver ) return [ 'text' => 'AI Provider not found.', 'history' => $history ];

		return $driver->chat( $prompt, $history );
	}

	/**
	 * List all registered providers for the settings UI.
	 */
	public function list_providers() : array {
		$out = [];
		foreach ( $this->drivers as $slug => $d ) {
			$out[] = [ 'slug' => $slug, 'label' => $d->get_label() ];
		}
		return $out;
	}
}
