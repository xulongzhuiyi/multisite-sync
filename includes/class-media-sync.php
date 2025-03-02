<?php
class MS_Media_Sync
{
    public static function sync_media($source_url, $lang)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ms_sync_media_map';
        // 检查是否已同步
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT target_url FROM $table_name WHERE source_url = %s AND lang = %s",
            $source_url,
            $lang
        ));
        if ($existing) return $existing->target_url;
        // 下载原始文件
        $tmp_file = download_url($source_url);
        if (is_wp_error($tmp_file)) {
            MS_Logger::log_error(0, $lang, "媒体下载失败：" . $tmp_file->get_error_message());
            return $source_url;
        }
        $site = MS_Settings::get_target_site($lang);
        $filename = basename(parse_url($source_url, PHP_URL_PATH));
        $response = wp_remote_post($site['url'] . '/wp-json/wp/v2/media', array(
            'headers' => array(
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Authorization' => 'Basic ' . base64_encode($site['username'] . ':' . MS_Security::decrypt($site['password'])),
            ),
            'body' => file_get_contents($tmp_file),
            'timeout' => 30,
        ));
        unlink($tmp_file); // 删除临时文件
        if (is_wp_error($response)) {
            MS_Logger::log_error(0, $lang, "媒体上传失败：" . $response->get_error_message());
            return $source_url;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['source_url'])) {
            // 存储映射关系
            $wpdb->insert($table_name, array(
                'source_url' => $source_url,
                'target_url' => $body['source_url'],
                'lang'       => $lang,
                'created'    => current_time('mysql')
            ));
            return $body['source_url'];
        }
        MS_Logger::log_error(0, $lang, "媒体同步失败：" . ($body['message'] ?? '未知错误'));
        return $source_url;
    }
}
