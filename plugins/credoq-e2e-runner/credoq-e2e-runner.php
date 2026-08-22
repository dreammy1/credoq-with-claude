<?php
/**
 * Plugin Name: Credoq E2E Audit Runner
 * Description: Protected Credoq audit control panel for dispatching repository-based E2E runs. Deployment remains approval-gated.
 * Version: 0.1.1
 * Requires Plugins: credoq-engine-v3/credoq-engine.php
 */
namespace CredoqE2ERunner;

defined('ABSPATH') || exit;

final class Plugin {
    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_credoq_e2e_save', [__CLASS__, 'save']);
        add_action('admin_post_credoq_e2e_dispatch', [__CLASS__, 'dispatch']);
    }

    public static function menu(): void {
        add_submenu_page('credoq', 'E2E Audit Runner', 'E2E Audit Runner', 'manage_options', 'credoq-e2e-runner', [__CLASS__, 'render']);
    }

    private static function settings(): array {
        return wp_parse_args(get_option('credoq_e2e_runner', []), [
            'repo' => 'dreammy1/credoq-with-claude',
            'workflow' => 'credoq-audit-release.yml',
            'target' => 'https://credoq.freedev.app',
            'github_token' => '',
            'mcp_endpoint' => 'https://credoq.freedev.app/wp-json/credoq-mcp/v1/mcp',
        ]);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $s = self::settings();
        $notice = isset($_GET['credoq_e2e']) ? sanitize_text_field(wp_unslash($_GET['credoq_e2e'])) : '';
        ?>
        <div class="wrap">
            <h1>Credoq E2E Audit Runner</h1>
            <?php if ($notice): ?><div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <p>Run the labeled five-plugin audit through the repository workflow. This page never deploys code directly and never captures payment.</p>
            <h2>Connection</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('credoq_e2e_save'); ?><input type="hidden" name="action" value="credoq_e2e_save">
                <table class="form-table"><tbody>
                    <tr><th><label for="credoq-e2e-repo">GitHub repository</label></th><td><input id="credoq-e2e-repo" class="regular-text" name="repo" value="<?php echo esc_attr($s['repo']); ?>"></td></tr>
                    <tr><th><label for="credoq-e2e-workflow">Workflow file</label></th><td><input id="credoq-e2e-workflow" class="regular-text" name="workflow" value="<?php echo esc_attr($s['workflow']); ?>"></td></tr>
                    <tr><th><label for="credoq-e2e-target">Audit target</label></th><td><input id="credoq-e2e-target" class="large-text" name="target" value="<?php echo esc_attr($s['target']); ?>"></td></tr>
                    <tr><th><label for="credoq-e2e-mcp">MCP endpoint</label></th><td><input id="credoq-e2e-mcp" class="large-text" name="mcp_endpoint" value="<?php echo esc_attr($s['mcp_endpoint']); ?>"></td></tr>
                    <tr><th><label for="credoq-e2e-token">GitHub token</label></th><td><input id="credoq-e2e-token" type="password" class="large-text" name="github_token" value="" autocomplete="new-password"><p class="description">Stored server-side only. Leave blank to keep the existing value.</p></td></tr>
                </tbody></table>
                <?php submit_button('Save runner settings'); ?>
            </form>
            <hr><h2>Start audit</h2>
            <p><strong>Dry run</strong> runs repository tests, packages, and evidence generation. It does not deploy to WordPress.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('credoq_e2e_dispatch'); ?><input type="hidden" name="action" value="credoq_e2e_dispatch"><input type="hidden" name="mode" value="dry-run">
                <?php submit_button('Start dry-run audit', 'primary'); ?>
            </form>
            <p class="description">Live audit and deployment controls will appear only after the MCP deployment contract and rollback checks pass.</p>
        </div>
        <?php
    }

    public static function save(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('credoq_e2e_save')) wp_die('Forbidden');
        $old = self::settings();
        $new = [
            'repo' => sanitize_text_field(wp_unslash($_POST['repo'] ?? $old['repo'])),
            'workflow' => sanitize_file_name(wp_unslash($_POST['workflow'] ?? $old['workflow'])),
            'target' => esc_url_raw(wp_unslash($_POST['target'] ?? $old['target'])),
            'mcp_endpoint' => esc_url_raw(wp_unslash($_POST['mcp_endpoint'] ?? $old['mcp_endpoint'])),
            'github_token' => sanitize_text_field(wp_unslash($_POST['github_token'] ?? '')) ?: $old['github_token'],
        ];
        update_option('credoq_e2e_runner', $new, false);
        wp_safe_redirect(admin_url('admin.php?page=credoq-e2e-runner&credoq_e2e=' . rawurlencode('Settings saved.'))); exit;
    }

    public static function dispatch(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('credoq_e2e_dispatch')) wp_die('Forbidden');
        $s = self::settings();
        if (!$s['github_token']) wp_safe_redirect(admin_url('admin.php?page=credoq-e2e-runner&credoq_e2e=' . rawurlencode('Add a scoped GitHub workflow-dispatch token in runner settings first.'))); else {
            $url = 'https://api.github.com/repos/' . rawurlencode($s['repo']) . '/actions/workflows/' . rawurlencode($s['workflow']) . '/dispatches';
            $r = wp_remote_post($url, ['timeout'=>20, 'headers'=>['Accept'=>'application/vnd.github+json','Authorization'=>'Bearer ' . $s['github_token'],'X-GitHub-Api-Version'=>'2022-11-28'], 'body'=>wp_json_encode(['ref'=>'main','inputs'=>['target'=>$s['target'],'mode'=>'dry-run']])]);
            $ok = !is_wp_error($r) && in_array(wp_remote_retrieve_response_code($r), [201, 204], true);
            $msg = $ok ? 'Dry-run dispatched to GitHub Actions.' : 'Dispatch failed; inspect the token scope and workflow configuration.';
            wp_safe_redirect(admin_url('admin.php?page=credoq-e2e-runner&credoq_e2e=' . rawurlencode($msg))); exit;
        }
    }
}

add_action('plugins_loaded', [Plugin::class, 'boot'], 25);
