<?php
/*
Plugin Name: Multisite Sync Pro
Description: 多站点内容同步插件（跨站同步、自动翻译、异步队列、事务回滚、日志监控等）。
Version: 1.0
Author: Your Name
*/
// 在 Tools 菜单下添加“同步队列监控”页面
function ms_sync_add_tools_page()
{
    add_submenu_page(
        'tools.php',
        __('同步队列监控', 'multisite-sync'),
        __('同步队列监控', 'multisite-sync'),
        'manage_options',
        'ms-sync-queue',
        'ms_sync_render_queue_page'
    );
}
add_action('admin_menu', 'ms_sync_add_tools_page');

add_action('admin_init', function () {
    foreach (get_taxonomies() as $taxonomy) {
        add_action("{$taxonomy}_edit_form", function ($term) use ($taxonomy) {
            include MS_SYNC_PATH . 'templates/taxonomy-sync-panel.php';
        });
    }
});
// 渲染同步队列监控页面
function ms_sync_render_queue_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . MS_Logger::LOG_TABLE;
    // 查询最近 20 条日志记录
    $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created DESC LIMIT 20");
?>
    <div class="wrap">
        <h1><?php esc_html_e('同步队列监控', 'multisite-sync'); ?></h1>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('文章ID', 'multisite-sync'); ?></th>
                    <th><?php esc_html_e('语言', 'multisite-sync'); ?></th>
                    <th><?php esc_html_e('状态', 'multisite-sync'); ?></th>
                    <th><?php esc_html_e('错误信息', 'multisite-sync'); ?></th>
                    <th><?php esc_html_e('记录时间', 'multisite-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (! empty($logs)) : ?>
                    <?php foreach ($logs as $log) : ?>
                        <tr>
                            <td><?php echo esc_html($log->post_id); ?></td>
                            <td><?php echo esc_html($log->lang); ?></td>
                            <td><?php echo esc_html($log->status); ?></td>
                            <td><?php echo esc_html($log->message); ?></td>
                            <td><?php echo esc_html($log->created); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="5"><?php esc_html_e('没有找到日志记录。', 'multisite-sync'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php
}

add_action('wp_ajax_ms_sync_post', array('MS_Sync_Core', 'handle_sync_request'));

// 定义常量
define('MS_SYNC_PATH', plugin_dir_path(__FILE__));
define('MS_SYNC_URL', plugin_dir_url(__FILE__));
function ms_sync_admin_assets($hook)
{
    // 扩展加载范围到所有编辑页面
    $allowed_pages = array(
        'post.php',
        'post-new.php',
        'edit-tags.php',
        'term.php'
    );
    if (in_array($hook, $allowed_pages)) {
        wp_enqueue_script(
            'ms-sync-admin-js',
            MS_SYNC_URL . 'admin/js/admin.js',
            array('jquery'),
            '1.0',
            true
        );
        wp_localize_script('ms-sync-admin-js', 'ms_sync_vars', array(
            'nonce' => wp_create_nonce('ms_sync_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
    }
}
add_action('admin_enqueue_scripts', 'ms_sync_admin_assets');

// 加载包含的文件
require_once MS_SYNC_PATH . 'includes/class-sync-core.php';
require_once MS_SYNC_PATH . 'includes/class-translator.php';
require_once MS_SYNC_PATH . 'includes/class-security.php';
require_once MS_SYNC_PATH . 'includes/class-queue.php';
require_once MS_SYNC_PATH . 'includes/class-logger.php';
require_once MS_SYNC_PATH . 'includes/class-settings.php';
// 初始化各个模块
add_action('init', function () {
    MS_Sync_Core::init();
    MS_Security::init();
    MS_Queue::init();
    MS_Logger::init();
    MS_Settings::init();
});
// 添加 AJAX 接口
add_action('wp_ajax_ms_sync_post', array('MS_Sync_Core', 'handle_sync_request'));
add_action('wp_ajax_ms_get_sync_sites', 'ms_get_sync_sites');
// 激活/停用钩子（可扩展，如创建日志表等）
register_activation_hook(__FILE__, array('MS_Logger', 'create_log_table'));
register_deactivation_hook(__FILE__, function () {
    // 清理计划任务等操作
    wp_localize_script('ms-sync-admin-js', 'ms_sync_vars', array(
        'nonce' => wp_create_nonce('ms_sync_nonce')
    ));
});
