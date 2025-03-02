<?php
if (! current_user_can('manage_options')) {
    wp_die(__('您没有权限访问此页面', 'multisite-sync'));
}
?>
<div class="wrap">
    <h1><?php esc_html_e('多站点同步设置', 'multisite-sync'); ?></h1>
    <form method="post" action="options.php">
        <?php
        settings_fields(MS_Settings::OPTION_GROUP);
        do_settings_sections('ms-sync-settings');
        submit_button();
        ?>
    </form>
    <p><?php esc_html_e('请填写目标站点信息，包括站点名称、语言、网址、用户名和密码。', 'multisite-sync'); ?></p>
</div>