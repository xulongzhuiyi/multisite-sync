<?php
class MS_Logger
{
    const LOG_TABLE = 'ms_sync_logs';
    public static function init()
    {
        // 可在初始化时注册计划任务或其他日志清理机制
    }
    /**
     * 创建日志表（在插件激活时调用）
     */
    public static function create_log_table()
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . self::LOG_TABLE;
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            lang varchar(10) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            created datetime NOT NULL,
            PRIMARY KEY (id),
            KEY post_lang (post_id, lang)
        ) $charset;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    /**
     * 记录同步成功日志
     */
    public static function log_success($post_id, $lang)
    {
        self::insert_log($post_id, $lang, 'success', '');
    }
    /**
     * 记录同步错误日志
     */
    public static function log_error($post_id, $lang, $message)
    {
        self::insert_log($post_id, $lang, 'error', $message);
    }
    private static function insert_log($post_id, $lang, $status, $message)
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . self::LOG_TABLE,
            array(
                'post_id' => $post_id,
                'lang'    => $lang,
                'status'  => $status,
                'message' => substr($message, 0, 255),
                'created' => current_time('mysql')
            )
        );
    }
}
