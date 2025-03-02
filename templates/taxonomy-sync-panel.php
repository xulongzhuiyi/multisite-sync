<div class="ms-sync-meta-box">
    <h4><?php esc_html_e('同步到目标站点', 'multisite-sync'); ?></h4>
    <?php foreach (MS_Settings::get_all_target_sites() as $site): ?>
        <button type="button"
            class="button sync-taxonomy-button"
            data-term-id="<?php echo $term->term_id; ?>"
            data-taxonomy="<?php echo $taxonomy; ?>"
            data-lang="<?php echo $site['lang']; ?>">
            同步到 <?php echo $site['name']; ?>
        </button>
    <?php endforeach; ?>
</div>
<div class="ms-sync-meta-box">
    <h4><?php esc_html_e('多站点同步', 'multisite-sync'); ?></h4>
    <p><?php esc_html_e('最后同步状态：', 'multisite-sync'); ?>
        <?php foreach (MS_Settings::get_all_target_sites() as $site): ?>
            <?php
            $last_sync = get_term_meta(
                $term->term_id,
                "ms_sync_{$site['lang']}_last_sync",
                true
            );
            ?>
            <span class="sync-status">
                <?php echo esc_html($site['name']); ?>:
                <?php echo $last_sync ? date('Y-m-d H:i', strtotime($last_sync)) : '从未同步'; ?>
            </span>
        <?php endforeach; ?>
    </p>

    <div class="sync-controls">
        <?php foreach (MS_Settings::get_all_target_sites() as $site): ?>
            <button type="button"
                class="button sync-taxonomy-button"
                data-term-id="<?php echo $term->term_id; ?>"
                data-taxonomy="<?php echo $taxonomy; ?>"
                data-lang="<?php echo $site['lang']; ?>">
                <?php esc_html_e('同步到', 'multisite-sync'); ?> <?php echo esc_html($site['name']); ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>