<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    http_response_code(404);
    exit;
}
if (!current_user_can('manage_options')) {
    wp_die('WPLite diagnostics require administrator permission.', 'WPLite', ['response' => 403]);
}
require_once __DIR__ . '/src/Diagnostics.php';
$wpliteReport = \WPLite\Diagnostics::site();
echo '<div class="wrap"><h1>WPLite 运行诊断</h1>';
echo '<p>这是当前环境的基础运行检查，不是插件兼容或商店生产认证。默认不扫描整库；完整性快速检查可用 CLI doctor-site --integrity quick。</p>';
echo '<h2>当前能力</h2><table class="widefat striped"><tbody>';
foreach (['内容运行' => $wpliteReport['capabilities']['content'], '定时任务' => $wpliteReport['capabilities']['scheduled_features'],
    '商店认证' => $wpliteReport['capabilities']['commerce'], '自动 Cron 配置' => $wpliteReport['cron']['automatic_trigger'],
    '最近探针' => $wpliteReport['cron']['status']] as $wpliteLabel => $wpliteValue) {
    echo '<tr><th>' . esc_html($wpliteLabel) . '</th><td>' . esc_html($wpliteValue) . '</td></tr>';
}
echo '</tbody></table><h2>一次性 Cron 执行探针</h2>';
echo '<p>单次执行不证明定时服务持续可靠。DISABLE_WP_CRON 仅关闭流量触发，不表示外部计划任务不可用。HTTP 200 不算执行成功。</p>';
echo '<p><strong>Loopback 检查会请求本站 wp-cron.php，可能执行所有已经到期的 WordPress 任务；仅安排模式不会主动发出 HTTP 请求。</strong></p>';
echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
echo '<input type="hidden" name="action" value="wplite_cron_probe">';
wp_nonce_field('wplite_cron_probe');
echo '<label>检查方式 <select name="transport"><option value="schedule">仅安排，等待现有 Cron 服务执行</option><option value="loopback">安排并请求本站 wp-cron.php</option></select></label> ';
submit_button('安排一次性探针', 'secondary', 'submit', false);
echo '</form><p>稍后刷新本页查看实际执行记录。若采用外部 Cron，请让原有调度器正常运行；CLI 可调用 php wp-cron.php，注意它也会执行其他到期任务。</p>';
echo '<h2>已去除私密字段的运行报告</h2><p>报告不包含数据目录、站点身份、盐值、安装密钥或备份认证密钥。仍含版本与磁盘余量，分享前请检查用途。公开目录 HTTP 记录仅表示安装时的检查，本页没有重新验证服务器规则。</p>';
echo '<pre id="wplite-diagnostics-json" style="white-space:pre-wrap">' . esc_html(wp_json_encode($wpliteReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></div>';
unset($wpliteReport, $wpliteLabel, $wpliteValue);
