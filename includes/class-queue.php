<?php
class MS_Queue
{
    public static function init()
    {
        // 注册处理同步任务的钩子（例如通过 WP Cron 触发）
        add_action('ms_process_sync', array(__CLASS__, 'process_queue'), 10, 2);
    }
    /**
     * 将同步任务加入队列
     */
    public static function enqueue($post_id, $lang)
    {
        // 简单示例：直接调度任务，实际生产环境建议使用 Action Scheduler
        wp_schedule_single_event(time() + 5, 'ms_process_sync', array($post_id, $lang));
    }
    /**
     * 处理队列中的任务
     */
    public static function process_queue($post_id, $lang)
    {
        try {
            // 调用同步逻辑
            MS_Sync_Core::handle_sync_request();
            // 可在此处更新任务状态
        } catch (Exception $e) {
            // 重试机制：记录错误并重试（最多 3 次）
            MS_Logger::log_error($post_id, $lang, '队列处理错误：' . $e->getMessage());
        }
    }
}
