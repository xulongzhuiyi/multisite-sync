<?php
if (! current_user_can('manage_options')) {
    wp_die(__('您没有权限访问此页面', 'multisite-sync'));
}
global $wpdb;
$table_name = $wpdb->prefix . MS_Logger::LOG_TABLE;
$logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created DESC LIMIT 5");
?>
<div class="wrap">
    <h1><?php esc_html_e('同步队列监控', 'multisite-sync'); ?></h1>
    <h2><?php esc_html_e('最近错误日志', 'multisite-sync'); ?></h2>
    <table class="widefat">
        <thead>
            <tr>
                <th><?php esc_html_e('文章ID', 'multisite-sync'); ?></th>
                <th><?php esc_html_e('语言', 'multisite-sync'); ?></th>
                <th><?php esc_html_e('状态', 'multisite-sync'); ?></th>
                <th><?php esc_html_e('错误信息', 'multisite-sync'); ?></th>
                <th><?php esc_html_e('时间', 'multisite-sync'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo esc_html($log->post_id); ?></td>
                    <td><?php echo esc_html($log->lang); ?></td>
                    <td><?php echo esc_html($log->status); ?></td>
                    <td><?php echo esc_html($log->message); ?></td>
                    <td><?php echo esc_html($log->created); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>