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
				<div class="credoq-card">
					<div class="command-chat-log" id="command-chat-log">
						<div class="msg msg-ai">
							<?php esc_html_e( 'Neural Link Established. I am your Credoq AI Assistant. How can I help you manage your business today?', 'credoq-engine' ); ?>
						</div>
					</div>
					<div class="command-input-wrap">
						<input type="text" id="command-input" class="command-input" placeholder="<?php esc_attr_e( 'Type a command, e.g. "List my membership plans"...', 'credoq-engine' ); ?>">
						<button class="button button-primary button-large" id="command-send"><?php esc_html_e( 'Send', 'credoq-engine' ); ?></button>
					</div>
				</div>
			</div>
		</div>

		<script>
		(function() {
			const log = document.getElementById('command-chat-log');
			const input = document.getElementById('command-input');
			const btn = document.getElementById('command-send');
			let history = [];

			function addMsg(text, role) {
				const div = document.createElement('div');
				div.className = 'msg msg-' + role;
				div.innerText = text;
				log.appendChild(div);
				log.scrollTop = log.scrollHeight;
			}

			function send() {
				const prompt = input.value.trim();
				if (!prompt) return;

				input.value = '';
				btn.disabled = true;
				addMsg(prompt, 'user');

				const fd = new FormData();
				fd.append('action', 'credoq_ai_chat');
				fd.append('_ajax_nonce', CredoqEngineAdmin.aiNonce);
				fd.append('prompt', prompt);
				fd.append('history', JSON.stringify(history));

				fetch(CredoqEngineAdmin.ajaxUrl, { method: 'POST', body: fd })
					.then(r => r.json())
					.then(d => {
						btn.disabled = false;
						if (d.success) {
							addMsg(d.data.text, 'ai');
							history = d.data.history;
							
							// Check if the last part of history had a tool call (for visual evidence)
							const last = history[history.length - 1];
							if (last && last.parts) {
								last.parts.forEach(p => {
									if (p.functionCall) {
										const toolDiv = document.createElement('div');
										toolDiv.className = 'msg msg-tool';
										toolDiv.innerText = '⚙ Executing: ' + p.functionCall.name + '(' + JSON.stringify(p.functionCall.args) + ')';
										log.appendChild(toolDiv);
									}
								});
							}
						} else {
							addMsg('Error: ' + (d.data.message || 'Unknown error'), 'ai');
						}
					})
					.catch(e => {
						btn.disabled = false;
						addMsg('Network Error: ' + e.message, 'ai');
					});
			}

			btn.onclick = send;
			input.onkeydown = e => { if (e.key === 'Enter') send(); };
		})();
		</script>

		<style>
		.spin { animation: spin 2s linear infinite; }
		@keyframes spin { 100% { transform: rotate(360deg); } }
		
		/* Temporary simple UI for M1/M2 prototype */
		#credoq-command-root .credoq-card { margin-top: 0; }
		.command-chat-log { height: 400px; overflow-y: auto; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 16px; display: flex; flex-direction: column; gap: 12px; }
		.msg { padding: 12px 16px; border-radius: 12px; max-width: 80%; line-height: 1.4; }
		.msg-user { background: #4f46e5; color: #fff; align-self: flex-end; }
		.msg-ai { background: #fff; border: 1px solid #e2e8f0; align-self: flex-start; }
		.msg-tool { background: #f1f5f9; border-left: 4px solid #94a3b8; align-self: flex-start; font-family: monospace; font-size: 11px; }
		.command-input-wrap { display: flex; gap: 12px; }
		.command-input { flex: 1; padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 14px; }
		.command-input:focus { border-color: #4f46e5; outline: none; box-shadow: 0 0 0 3px rgba(79,70,229,0.1); }
		</style>
		<?php
	}
}
