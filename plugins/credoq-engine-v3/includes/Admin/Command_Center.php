<?php
/**
 * Command_Center — React-based AI administration hub.
 *
 * @package CredoqEngine\Admin
 */

namespace CredoqEngine\Admin;

defined( 'ABSPATH' ) || exit;

class Command_Center {

	public static function render() : void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

		?>
		<div class="wrap credoq-admin-wrap">
			<div class="credoq-page-header">
				<div class="credoq-page-header-inner">
					<h1 class="credoq-page-title">
						<span class="dashicons dashicons-brain" style="font-size:28px;margin-right:8px;color:#4f46e5;"></span>
						<?php esc_html_e( 'Credoq Command Center', 'credoq-engine' ); ?>
					</h1>
					<div style="margin-left:auto;display:flex;gap:8px;">
						<span class="cq-badge cq-badge-confirmed" id="ai-status">AI Online</span>
					</div>
				</div>
			</div>

			<div id="credoq-command-root">
				<div class="credoq-card" style="text-align:center;padding:100px 40px;">
					<span class="dashicons dashicons-update spin" style="font-size:40px;width:40px;height:40px;color:#4f46e5;margin-bottom:20px;"></span>
					<h2><?php esc_html_e( 'Initializing Neural Link...', 'credoq-engine' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Connecting to your primary LLM provider.', 'credoq-engine' ); ?></p>
				</div>
			</div>
		</div>

		<style>
		.spin { animation: spin 2s linear infinite; }
		@keyframes spin { 100% { transform: rotate(360deg); } }
		</style>
		<?php
	}
}
