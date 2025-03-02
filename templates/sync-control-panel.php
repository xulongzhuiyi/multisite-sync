<?php
// 使用 MS_Settings 类获取目标站点配置
$sites = MS_Settings::get_all_target_sites();
if (empty($sites)) {
    echo '<p>' . __('未配置目标站点，请先在设置中添加。', 'multisite-sync') . '</p>';
    return;
}
// 获取文章已保存的选中站点（便于重复同步时默认选中）
$selected_sites = get_post_meta($post->ID, 'ms_sync_selected_sites', true);
if (!is_array($selected_sites)) {
    $selected_sites = array();
}
?>
<div class="ms-sync-meta-box">
    <h4><?php esc_html_e('选择目标站点', 'multisite-sync'); ?></h4>
    <?php foreach ($sites as $site) :
        $lang = isset($site['lang']) ? $site['lang'] : '';
        $label = isset($site['name']) ?
            esc_html($site['name'] . ' (' . $site['url'] . ')') :
            esc_html(strtoupper($lang) . ' (' . $site['url'] . ')');
    ?>
        <label>
            <input type="checkbox"
                name="ms_sync_sites[]"
                value="<?php echo esc_attr($lang); ?>"
                <?php checked(in_array($lang, $selected_sites)); ?> />
            <?php echo $label; ?>
        </label><br>
    <?php endforeach; ?>
    <button type="button" class="button sync-button" data-post-id="<?php echo esc_attr($post->ID); ?>">
        <?php esc_html_e('立即同步', 'multisite-sync'); ?>
    </button>
    <div class="sync-progress" style="display:none;">
        <div class="sync-progress-bar" style="width:0%; background:#4caf50; height:20px;"></div>
        <span class="sync-progress-text"></span>
    </div>
</div>