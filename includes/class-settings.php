<?php
class MS_Settings
{
    const OPTION_GROUP = 'ms_sync_settings';
    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }
    public static function add_menu()
    {
        add_options_page(
            __('多站点同步设置', 'multisite-sync'),
            __('多站点同步', 'multisite-sync'),
            'manage_options',
            'ms-sync-settings',
            array(__CLASS__, 'render_page')
        );
    }

    // 新增获取全部站点的方法
    public static function get_all_target_sites()
    {
        $sites_option = get_option('ms_sync_sites', array());

        // 处理旧数据格式（兼容字符串格式）
        if (!is_array($sites_option)) {
            $decoded = json_decode($sites_option, true);
            $sites_option = is_array($decoded) ? $decoded : array();
        }

        // 过滤无效条目
        return array_filter($sites_option, function ($site) {
            return !empty($site['url']) && !empty($site['lang']);
        });
    }
    public static function register_settings()
    {
        // 注册 API 密钥设置
        register_setting(self::OPTION_GROUP, 'ms_translation_api_key');
        // 注册目标站点设置（包含验证逻辑）
        register_setting(
            self::OPTION_GROUP,
            'ms_sync_sites',
            array(
                'sanitize_callback' => function ($raw_input) {
                    $valid_sites = array();
                    // 处理旧数据格式（兼容字符串格式）
                    if (!is_array($raw_input)) {
                        $decoded = json_decode($raw_input, true);
                        $raw_input = is_array($decoded) ? $decoded : array();
                    }
                    // 获取当前已保存的站点数据
                    $existing_sites = get_option('ms_sync_sites', array());
                    foreach ($raw_input as $index => $site) {
                        $clean_site = array();
                        // 验证 URL（必需字段）
                        if (empty($site['url']) || !filter_var($site['url'], FILTER_VALIDATE_URL)) {
                            add_settings_error(
                                'ms_sync_sites',
                                'invalid_url_' . $index,
                                sprintf(__('第 %d 个站点：无效的 URL 格式', 'multisite-sync'), $index + 1)
                            );
                            continue;
                        }
                        $clean_site['url'] = esc_url_raw(trailingslashit($site['url']));
                        // 处理语言代码（必需字段）
                        if (empty($site['lang'])) {
                            // 自动从域名提取语言代码（示例：fr.example.com → fr）
                            $parsed = parse_url($clean_site['url']);
                            $domain_parts = explode('.', $parsed['host']);
                            $clean_site['lang'] = (count($domain_parts) > 1) ? sanitize_key($domain_parts[0]) : 'en';
                        } else {
                            $clean_site['lang'] = sanitize_key($site['lang']);
                        }
                        // 处理用户名（必需字段）
                        if (empty($site['username'])) {
                            add_settings_error(
                                'ms_sync_sites',
                                'empty_username_' . $index,
                                sprintf(__('第 %d 个站点：用户名不能为空', 'multisite-sync'), $index + 1)
                            );
                            continue;
                        }
                        $clean_site['username'] = sanitize_user($site['username']);
                        // 处理密码（必需字段）
                        if (empty($site['password'])) {
                            // 如果密码为空，尝试从已保存的数据中获取
                            if (isset($existing_sites[$index]['password'])) {
                                $clean_site['password'] = $existing_sites[$index]['password'];
                            } else {
                                add_settings_error(
                                    'ms_sync_sites',
                                    'empty_password_' . $index,
                                    sprintf(__('第 %d 个站点：密码不能为空', 'multisite-sync'), $index + 1)
                                );
                                continue;
                            }
                        } else {
                            // 如果密码字段有值，且未加密，则加密存储
                            if (!MS_Security::is_encrypted($site['password'])) {
                                $clean_site['password'] = MS_Security::encrypt($site['password']);
                            } else {
                                // 如果密码已经加密，直接使用
                                $clean_site['password'] = $site['password'];
                            }
                        }
                        // 站点名称（可选字段）
                        $clean_site['name'] = !empty($site['name'])
                            ? sanitize_text_field($site['name'])
                            : sprintf(__('站点 %s', 'multisite-sync'), strtoupper($clean_site['lang']));
                        $valid_sites[] = $clean_site;
                    }
                    return $valid_sites;
                }
            )
        );
        // 添加 API 配置部分
        add_settings_section(
            'ms_api_section',
            __('DeepSeek API 配置', 'multisite-sync'),
            function () {
                echo '<p>' . __('请填写 DeepSeek API 密钥（加密存储）。如果为空则直接同步原文。', 'multisite-sync') . '</p>';
            },
            'ms-sync-settings'
        );
        // 添加 API 密钥字段
        add_settings_field(
            'ms_translation_api_key',
            __('API 密钥', 'multisite-sync'),
            array(__CLASS__, 'render_api_key_field'),
            'ms-sync-settings',
            'ms_api_section'
        );
        // 添加目标站点配置部分
        add_settings_section(
            'ms_sites_section',
            __('目标站点配置', 'multisite-sync'),
            function () {
                echo '<p>' . __('请填写目标站点信息，包括站点名称、语言、网址、用户名和密码。', 'multisite-sync') . '</p>';
            },
            'ms-sync-settings'
        );
        // 添加目标站点列表字段
        add_settings_field(
            'ms_sync_sites',
            __('目标站点列表', 'multisite-sync'),
            array(__CLASS__, 'render_sites_field'),
            'ms-sync-settings',
            'ms_sites_section'
        );
    }
    public static function render_page()
    {
        include MS_SYNC_PATH . 'templates/settings-page.php';
    }
    public static function render_api_key_field()
    {
        $api_key = get_option('ms_translation_api_key', '');
        echo '<input type="password" name="ms_translation_api_key" value="' . esc_attr($api_key) . '" class="regular-text" />';
    }
    public static function render_sites_field()
    {
        $sites = MS_Settings::get_all_target_sites();
?>
        <table id="ms-sync-sites-table" class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th><?php esc_html_e('站点名称', 'multisite-sync'); ?></th>
                    <th><?php esc_html_e('语言', 'multisite-sync'); ?></th>
                    <th><?php esc_html_e('网址', 'multisite-sync'); ?></th>
                    <th><?php esc_html_e('用户名', 'multisite-sync'); ?></th>
                    <th><?php esc_html_e('密码', 'multisite-sync'); ?></th>
                    <th><?php esc_html_e('操作', 'multisite-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($sites)) : ?>
                    <?php foreach ($sites as $index => $site) : ?>
                        <tr>
                            <td>
                                <input type="text"
                                    name="ms_sync_sites[<?php echo $index; ?>][name]"
                                    value="<?php echo esc_attr($site['name'] ?? ''); ?>"
                                    class="regular-text" />
                            </td>
                            <td>
                                <select name="ms_sync_sites[<?php echo $index; ?>][lang]">
                                    <option value="en" <?php selected($site['lang'] ?? '', 'en'); ?>>EN</option>
                                    <option value="fr" <?php selected($site['lang'] ?? '', 'fr'); ?>>FR</option>
                                    <option value="ru" <?php selected($site['lang'] ?? '', 'ru'); ?>>RU</option>
                                    <option value="ja" <?php selected($site['lang'] ?? '', 'ja'); ?>>JA</option>
                                </select>
                            </td>
                            <td>
                                <input type="url"
                                    name="ms_sync_sites[<?php echo $index; ?>][url]"
                                    value="<?php echo esc_attr($site['url'] ?? ''); ?>"
                                    class="regular-text" />
                            </td>
                            <td>
                                <input type="text"
                                    name="ms_sync_sites[<?php echo $index; ?>][username]"
                                    value="<?php echo esc_attr($site['username'] ?? ''); ?>"
                                    class="regular-text" />
                            </td>
                            <td>
                                <input type="password"
                                    name="ms_sync_sites[<?php echo $index; ?>][password]"
                                    value=""
                                    class="regular-text"
                                    placeholder="<?php esc_attr_e('留空则不修改', 'multisite-sync'); ?>" />
                            </td>
                            <td>
                                <button type="button" class="button ms-sync-remove-site"><?php esc_html_e('删除', 'multisite-sync'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="6"><?php esc_html_e('暂无站点配置', 'multisite-sync'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p>
            <button type="button" id="ms-sync-add-site" class="button"><?php esc_html_e('添加站点', 'multisite-sync'); ?></button>
        </p>
        <script>
            jQuery(document).ready(function($) {
                $('#ms-sync-add-site').on('click', function() {
                    var index = $('#ms-sync-sites-table tbody tr').length;
                    var newRow = '<tr>' +
                        '<td><input type="text" name="ms_sync_sites[' + index + '][name]" value="" class="regular-text" /></td>' +
                        '<td>' +
                        '<select name="ms_sync_sites[' + index + '][lang]">' +
                        '<option value="en">EN</option>' +
                        '<option value="fr">FR</option>' +
                        '<option value="ru">RU</option>' +
                        '<option value="ja">JA</option>' +
                        '</select>' +
                        '</td>' +
                        '<td><input type="url" name="ms_sync_sites[' + index + '][url]" value="" class="regular-text" /></td>' +
                        '<td><input type="text" name="ms_sync_sites[' + index + '][username]" value="" class="regular-text" /></td>' +
                        '<td><input type="password" name="ms_sync_sites[' + index + '][password]" value="" class="regular-text" /></td>' +
                        '<td><button type="button" class="button ms-sync-remove-site"><?php esc_html_e('删除', 'multisite-sync'); ?></button></td>' +
                        '</tr>';
                    $('#ms-sync-sites-table tbody').append(newRow);
                });
                $(document).on('click', '.ms-sync-remove-site', function() {
                    $(this).closest('tr').remove();
                });
            });
        </script>
<?php
    }

    /**
     * 辅助方法：根据语言获取目标站点配置
     * 返回数组：包含 url、username、password
     */
    public static function get_target_site($lang)
    {
        foreach (self::get_all_target_sites() as $site) {
            if (isset($site['lang']) && $site['lang'] === $lang) {
                return $site;
            }
        }
        return false;
    }
}
