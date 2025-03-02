<?php
class MS_Sync_Core
{
    // 存储主站与各目标站文章对应关系的 meta 前缀
    const SYNC_META_PREFIX = '_ms_sync_';
    public static function init()
    {
        // 在文章编辑页添加同步控制面板
        add_action('add_meta_boxes', array(__CLASS__, 'add_sync_meta_box'));
    }
    // class-sync-core.php 新增方法
    private static function get_blog_id_by_lang($lang)
    {
        $site = MS_Settings::get_target_site($lang);
        if (!$site || !isset($site['url'])) {
            return false;
        }
        $target_url = trailingslashit($site['url']);
        $sites = get_sites(array('number' => 100)); // 限制查询数量
        foreach ($sites as $site_obj) {
            switch_to_blog($site_obj->blog_id);
            $home_url = trailingslashit(get_option('home'));
            restore_current_blog();
            if ($home_url === $target_url) {
                return $site_obj->blog_id;
            }
        }
        return false;
    }
    private static function process_content($content, $lang)
    {
        // 匹配所有图片
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches);
        foreach ($matches[1] as $img_url) {
            $new_url = MS_Media_Sync::sync_media($img_url, $lang);
            $content = str_replace($img_url, $new_url, $content);
        }
        return $content;
    }
    /**
     * 添加文章编辑页面的同步控制面板
     */
    public static function add_sync_meta_box()
    {
        $post_types = array('post', 'page');
        foreach ($post_types as $post_type) {
            add_meta_box(
                'ms_sync_meta_box',
                __('同步到目标站点', 'multisite-sync'),
                array(__CLASS__, 'render_sync_meta_box'),
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * 渲染同步控制面板（调用模板）
     */
    public static function render_sync_meta_box($post)
    {
        include MS_SYNC_PATH . 'templates/sync-control-panel.php';
    }
    /**
     * AJAX 请求处理：根据传入的 post_id 与 langs 同步文章
     */
    public static function handle_sync_request()
    {
        // 权限验证
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
            return;
        }
        // 安全验证
        check_ajax_referer('ms_sync_nonce', 'security');
        // 获取参数
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $langs = isset($_POST['langs']) ? (array)$_POST['langs'] : array();
        $force_sync = isset($_POST['force_sync']) ? (bool)$_POST['force_sync'] : false;
        // 参数有效性验证
        if (!$post_id || empty($langs)) {
            wp_send_json_error(array('message' => '缺少必要参数: post_id 或 langs'));
            return;
        }
        // 获取原始文章数据
        $original_post = get_post($post_id);
        if (!$original_post) {
            wp_send_json_error(array('message' => '文章不存在'));
            return;
        }
        // 初始化结果容器
        $results = array();
        // 遍历所有目标语言
        foreach ($langs as $lang) {
            // 获取目标站点配置
            $target_site = MS_Settings::get_target_site($lang);
            if (!$target_site) {
                MS_Logger::log_error($post_id, $lang, "未找到语言为 {$lang} 的站点配置");
                $results[$lang] = false;
                continue;
            }
            try {
                // 准备同步数据
                $data = array(
                    'title'       => MS_Translator::translate_text($original_post->post_title, $lang),
                    'content'     => MS_Translator::translate_text(apply_filters('the_content', $original_post->post_content), $lang),
                    'excerpt'     => MS_Translator::translate_text($original_post->post_excerpt, $lang),
                    'post_type'   => $original_post->post_type,
                    'post_status' => $original_post->post_status,
                    'meta'        => self::process_meta($post_id),
                    'taxonomies'  => self::get_taxonomies_data($post_id),
                    'media'       => self::sync_media($original_post->post_content, $lang),
                    'source_id'   => $post_id
                );
                // 推送数据到目标站点
                $response = self::push_to_target($data, $lang, $post_id);
                if (is_wp_error($response)) {
                    throw new Exception($response->get_error_message());
                }
                // 记录成功日志
                MS_Logger::log_success($post_id, $lang);
                $results[$lang] = array(
                    'status'  => 'success',
                    'message' => '同步完成',
                    'new_id'  => $response['id'] ?? null
                );
            } catch (Exception $e) {
                // 记录错误日志
                MS_Logger::log_error($post_id, $lang, $e->getMessage());
                $results[$lang] = array(
                    'status'  => 'error',
                    'message' => $e->getMessage()
                );
            }
        }
        // 处理最终结果
        $success_count = count(array_filter($results, fn($item) => $item['status'] === 'success'));
        $message = "成功同步到 {$success_count}/" . count($langs) . "个站点";
        wp_send_json_success(array(
            'message' => $message,
            'details' => $results
        ));
    }
    /**
     * 同步文章分类和标签，保持层级结构（简化示例）
     */
    public static function sync_taxonomies($post_id, $lang)
    {
        // 同步分类：获取文章所有分类，并检查目标站点中是否已存在该分类（通过 slug 判断）
        $categories = wp_get_post_terms($post_id, 'category', array('fields' => 'all'));
        foreach ($categories as $cat) {
            // 如果目标站点中不存在此分类的 slug，则进行创建（包括递归同步父分类）
            if (! self::target_taxonomy_exists('category', $cat->slug, $lang)) {
                self::create_target_taxonomy($cat, $lang);
            }
        }

        // 同步标签：获取文章所有标签，并检查目标站点中是否已存在该标签（通过 slug 判断）
        $tags = wp_get_post_terms($post_id, 'post_tag', array('fields' => 'all'));
        foreach ($tags as $tag) {
            // 如果目标站点中不存在此标签的 slug，则进行创建
            if (! self::target_taxonomy_exists('post_tag', $tag->slug, $lang)) {
                self::create_target_taxonomy($tag, $lang);
            }
        }

        // 同步分类和标签
        $taxonomies = get_post_taxonomies($post_id);
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy);
            foreach ($terms as $term) {
                MS_Taxonomy_Sync::sync_term($term->term_id, $taxonomy, $lang);
            }
        }
    }

    /**
     * 检查目标站点中是否存在指定分类或标签
     * @param string $taxonomy  'category' 或 'post_tag'
     * @param string $slug      要检查的 slug
     * @param string $lang      目标站点语言标识
     * @return bool
     */
    private static function target_taxonomy_exists($taxonomy, $slug, $lang)
    {
        // 此处你需要调用目标站点 API 或查找本地缓存来判断是否存在
        // 示例：简单返回 false，表示目标站点中不存在（实际需要替换为具体逻辑）
        return false;
    }
    /**
     * 在目标站点创建指定分类或标签，并返回创建后的信息
     * @param object $term   传入的 WP_Term 对象（包含 name, slug, description, parent 等信息）
     * @param string $lang   目标站点语言标识
     */
    private static function create_target_taxonomy($term, $lang)
    {
        // 如果该分类/标签有父级，先递归同步父级
        if ($term->parent > 0) {
            $parent = get_term($term->parent, $term->taxonomy);
            if ($parent && ! self::target_taxonomy_exists($term->taxonomy, $parent->slug, $lang)) {
                self::create_target_taxonomy($parent, $lang);
            }
        }
        // 构造数据包，包含完整信息：名称、slug、别名、描述、自定义字段等
        $data = array(
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            // 可根据需要加入别名和自定义字段
            'meta'        => get_term_meta($term->term_id)
        );
        // 调用 push_to_target() 或专门的 taxonomy API 接口创建目标站点的分类/标签
        $result = self::push_to_target_taxonomy($data, $term->taxonomy, $lang);
        // 记录映射关系，本示例不做处理，实际应存储目标站点对应的 term ID
        return $result;
    }
    /**
     * 将分类/标签数据推送到目标站点
     * @param array  $data      分类/标签数据
     * @param string $taxonomy  'category' 或 'post_tag'
     * @param string $lang      目标站点语言标识
     * @return mixed            创建成功返回数据，失败返回 WP_Error 对象
     */
    private static function push_to_target_taxonomy($data, $taxonomy, $lang)
    {
        // 从设置中获取目标站点配置
        $site = MS_Settings::get_target_site($lang);
        if (! $site) {
            return new WP_Error('no_target_site', __('目标站点未配置', 'multisite-sync'));
        }
        // 构造目标站点分类/标签 API URL，假设目标站点已实现对应接口
        $api_url = trailingslashit($site['url']) . 'wp-json/ms-sync/v1/receive-taxonomy';
        $credentials = base64_encode($site['username'] . ':' . $site['password']);
        $response = wp_remote_post($api_url, array(
            'method'    => 'POST',
            'timeout'   => 15,
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . $credentials,
            ),
            'body'      => json_encode(array(
                'taxonomy' => $taxonomy,
                'data'     => $data
            ))
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * 获取文章所有自定义字段，过滤系统字段，转换格式
     */
    public static function process_meta($post_id)
    {
        $all_meta = get_post_meta($post_id);
        $custom_meta = array();
        foreach ($all_meta as $key => $values) {
            if (in_array($key, array('_edit_lock', '_edit_last'))) {
                continue;
            }
            // 如果是主题备份字段，直接同步原始数据，不进行翻译处理
            if ('csf-export-data' === $key) {
                $custom_meta[$key] = $values[0]; // 或者根据需要先解码
                continue;
            }
            $custom_meta[$key] = count($values) === 1 ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
        }
        return $custom_meta;
    }

    /**
     * 同步文章内的媒体资源，自动上传并替换 URL（示例）
     */
    public static function sync_media($content, $lang)
    {
        // 使用正则匹配文章中的 <img> 标签
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches)) {
            foreach ($matches[1] as $img_url) {
                // 下载图片内容
                $response = wp_remote_get($img_url);
                if (is_wp_error($response)) {
                    continue;
                }
                $image_data = wp_remote_retrieve_body($response);
                $md5_hash = md5($image_data);
                // 检查目标站点是否已存在相同文件（你需要实现 get_target_media_by_hash()）
                $target_media = self::get_target_media_by_hash($md5_hash, $lang);
                if ($target_media) {
                    $new_url = $target_media['guid'];
                } else {
                    // 如果不存在，则上传图片到目标站点（调用目标站点媒体 API）
                    $upload_response = self::upload_media_to_target($img_url, $image_data, $md5_hash, $lang);
                    if (! is_wp_error($upload_response) && isset($upload_response['guid'])) {
                        $new_url = $upload_response['guid'];
                    } else {
                        $new_url = $img_url;
                    }
                }
                // 替换文章中的图片 URL
                $content = str_replace($img_url, $new_url, $content);
            }
        }
        return $content;
    }
    /**
     * 示例函数：检查目标站点是否存在相同媒体（需要你根据目标站点 API 实现）
     */
    private static function get_target_media_by_hash($hash, $lang)
    {
        // 此处返回 false 表示目标站点中不存在相同文件
        return false;
    }
    /**
     * 示例函数：上传媒体到目标站点（需要调用目标站点媒体 API）
     */
    private static function upload_media_to_target($img_url, $image_data, $hash, $lang)
    {
        $site = MS_Settings::get_target_site($lang);
        if (! $site) {
            return new WP_Error('no_target_site', __('目标站点未配置', 'multisite-sync'));
        }
        $api_url = trailingslashit($site['url']) . 'wp-json/wp/v2/media';
        $credentials = base64_encode($site['username'] . ':' . $site['password']);
        $response = wp_remote_post($api_url, array(
            'method'    => 'POST',
            'headers'   => array(
                'Content-Disposition' => 'attachment; filename="' . basename($img_url) . '"',
                'Authorization' => 'Basic ' . $credentials,
                'Content-Type'  => 'image/jpeg'
            ),
            'body'      => $image_data,
            'timeout'   => 15,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * 获取文章分类、标签数据，供 REST API 调用
     */
    public static function get_taxonomies_data($post_id)
    {
        $tax_data = array();
        $taxonomies = get_object_taxonomies('post');
        foreach ($taxonomies as $tax) {
            $terms = wp_get_post_terms($post_id, $tax, array('fields' => 'all'));
            $tax_data[$tax] = array();
            foreach ($terms as $term) {
                // 排除默认分类（例如 ID 为 1 的分类）根据实际情况判断
                if ('category' === $tax && $term->term_id == 1) {
                    continue;
                }
                $tax_data[$tax][] = array(
                    'name'        => $term->name,
                    'slug'        => $term->slug,
                    'parent'      => $term->parent,
                    'description' => $term->description,
                    'alias'       => isset($term->alias) ? $term->alias : '', // 若有别名字段
                    'meta'        => get_term_meta($term->term_id)
                );
            }
        }
        return $tax_data;
    }

    /**
     * 将数据推送到目标站点 API 接口
     * 目标站点配置中包含 URL、API账号、API密钥（加密存储）
     */
    private static function push_to_target($data, $lang, $post_id)
    {
        $site = MS_Settings::get_target_site($lang);
        if (!$site) {
            return new WP_Error('no_target_site', "目标站点 {$lang} 未配置");
        }
        // 调试：输出目标站点配置
        error_log("目标站点配置: " . print_r($site, true));
        // 解密密码
        $password = MS_Security::decrypt($site['password']);
        error_log("解密后的密码: " . $password); // 调试：输出解密后的密码
        $credentials = base64_encode($site['username'] . ':' . $password);
        // 测试目标站点的认证
        $test_url = trailingslashit($site['url']) . 'wp-json/ms-sync/v1/test-auth';
        $test_response = wp_remote_post($test_url, array(
            'method'    => 'POST',
            'timeout'   => 10,
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . $credentials,
            )
        ));
        if (is_wp_error($test_response)) {
            error_log("测试认证失败: " . $test_response->get_error_message());
            return new WP_Error('auth_failed', '目标站点认证失败');
        } else {
            $test_result = json_decode(wp_remote_retrieve_body($test_response), true);
            error_log("测试认证结果: " . print_r($test_result, true));

            if (isset($test_result['code']) && $test_result['code'] === 'rest_forbidden') {
                return new WP_Error('auth_failed', '目标站点认证失败：' . $test_result['message']);
            }
        }
        // 确保同步到正确的post_type
        $post_type = $data['post_type'] ?? 'post';
        $api_url = trailingslashit($site['url']) . "wp-json/ms-sync/v1/receive-{$post_type}";

        // 正式发送同步请求
        $api_url = trailingslashit($site['url']) . 'wp-json/ms-sync/v1/receive-post';
        $response = wp_remote_post($api_url, array(
            'method'    => 'POST',
            'timeout'   => 20,
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . $credentials,
            ),
            'body'      => json_encode($data)
        ));
        if (is_wp_error($response)) {
            error_log("push_to_target 请求错误: " . $response->get_error_message());
            return $response;
        }
        $result = json_decode(wp_remote_retrieve_body($response), true);
        error_log("push_to_target 响应结果: " . print_r($result, true));
        return $result;
    }

    public static function handle_bulk_sync()
    {
        check_ajax_referer('ms_sync_nonce', 'security');
        $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids']) ? $_POST['post_ids'] : array();
        $langs = isset($_POST['langs']) && is_array($_POST['langs']) ? $_POST['langs'] : array();
        if (empty($post_ids) || empty($langs)) {
            wp_send_json_error(array('message' => __('缺少文章或站点信息', 'multisite-sync')));
        }
        foreach ($post_ids as $post_id) {
            // 对每篇文章逐一调用 handle_sync_request（可以复用现有逻辑）
            // 这里示例逐篇处理，实际应使用异步队列
            foreach ($langs as $lang) {
                self::handle_sync_request_for_post($post_id, $lang);
            }
        }
        wp_send_json_success(array('message' => __('批量同步任务已加入队列', 'multisite-sync')));
    }
    private static function handle_sync_request_for_post($post_id, $lang)
    {
        $post_type = get_post_type($post_id);
        if (! in_array($post_type, array('post', 'page'))) {
            throw new Exception(__('不支持该类型的内容同步', 'multisite-sync'));
        }
        // 将 handle_sync_request() 内的同步逻辑封装为一个单独函数，便于批量调用
        // 这里调用现有逻辑（假设已封装好同步文章的函数 sync_post_to_target() ）
        $api_key = get_option('ms_translation_api_key');
        $force_translate = !empty($api_key); // 如果配置了API密钥则强制翻译

        // 准备数据时根据配置决定是否翻译
        $data = array(
            'title'   => $force_translate ?
                MS_Translator::translate_text($original_post->post_title, $lang) :
                $original_post->post_title,
            'content' => $force_translate ?
                MS_Translator::translate_text(apply_filters('the_content', $original_post->post_content), $lang) :
                $original_post->post_content,
            // ...其他字段...
        );
        // 同步其他信息同单篇同步逻辑
        $meta = self::process_meta($post_id);
        $content = self::sync_media($content, $lang);
        $data = array(
            'title'      => $title,
            'content'    => $content,
            'excerpt'    => get_the_excerpt($post_id),
            'date'       => get_post_field('post_date', $post_id),
            'meta'       => $meta,
            'taxonomies' => self::get_taxonomies_data($post_id),
            'source_id'  => $post_id
        );
        $result = self::push_to_target($data, $lang, $post_id);
        if (! is_wp_error($result)) {
            MS_Logger::log_success($post_id, $lang);
            update_post_meta($post_id, self::SYNC_META_PREFIX . $lang, $result['id']);
        } else {
            MS_Logger::log_error($post_id, $lang, $result->get_error_message());
        }
    }
    public static function handle_sync_taxonomy()
    {
        check_ajax_referer('ms_sync_nonce', 'security');
        $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_key($_POST['taxonomy']) : '';
        $lang = isset($_POST['lang']) ? sanitize_key($_POST['lang']) : '';
        if (!$term_id || empty($taxonomy) || empty($lang)) {
            wp_send_json_error(array('message' => '参数错误'));
        }
        try {
            MS_Taxonomy_Sync::sync_term($term_id, $taxonomy, $lang);
            wp_send_json_success(array('message' => '分类同步完成'));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}
// 在初始化时添加钩子
add_action('wp_ajax_ms_sync_taxonomy', array('MS_Sync_Core', 'handle_sync_taxonomy'));
add_action('wp_ajax_ms_sync_bulk', array('MS_Sync_Core', 'handle_bulk_sync'));
