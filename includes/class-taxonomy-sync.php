<?php
class MS_Taxonomy_Sync {
    public static function sync_term($term_id, $taxonomy, $lang) {
        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) return;
        // 递归同步父级分类
        if ($term->parent > 0) {
            self::sync_term($term->parent, $taxonomy, $lang);
        }
        // 获取完整分类数据
        $term_data = array(
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'parent'      => self::get_target_parent_id($term->parent, $taxonomy, $lang),
            'meta'        => self::get_term_metadata($term_id),
            'taxonomy'    => $taxonomy
        );
        $site = MS_Settings::get_target_site($lang);
        $api_url = trailingslashit($site['url']) . 'wp-json/ms-sync/v1/receive-term';
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($site['username'] . ':' . MS_Security::decrypt($site['password']),
                'Authorization' => 'Basic ' . base64_encode($site['username'] . ':' . MS_Security::decrypt($site['password'])),

                'Content-Type'  => 'application/json'
            ),
            'body' => json_encode($term_data)
        ));
        if (is_wp_error($response)) {
            MS_Logger::log_error(0, $lang, "分类同步失败：" . $response->get_error_message());
            return;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['id'])) {
            // 存储term映射关系
            update_term_meta($term_id, "ms_sync_{$lang}_term_id", $body['id']);
            MS_Logger::log_success($term_id, $lang, "分类同步成功");
        } else {
            MS_Logger::log_error($term_id, $lang, "分类同步失败：" . ($body['message'] ?? '未知错误'));
        }
    }
    private static function get_term_metadata($term_id) {
        $meta = array();
        $all_meta = get_term_meta($term_id);
        foreach ($all_meta as $key => $values) {
            if (in_array($key, array('ms_sync_*'))) continue; // 排除同步插件自身的meta
            $meta[$key] = count($values) > 1 ? $values : maybe_unserialize($values[0]);
        }
        return $meta;
    }
    private static function get_target_parent_id($source_parent_id, $taxonomy, $lang) {
        if ($source_parent_id == 0) return 0;
        // 获取已同步的父级term_id
        return get_term_meta($source_parent_id, "ms_sync_{$lang}_term_id", true) ?: 0;
    }
}