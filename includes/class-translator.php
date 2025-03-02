<?php
class MS_Translator
{
    // DeepSeek API 的端点
    const API_ENDPOINT = 'https://api.deepseek.com/v1/translate';
    /**
     * 翻译文本，若 API 密钥为空，则直接返回原文
     */
    public static function translate_text($text, $target_lang)
    {
        $api_key = get_option('ms_translation_api_key');
        if (empty($api_key)) {
            // 未配置 API 密钥，直接返回原文
            return $text;
        }
        // 调用 DeepSeek API 翻译
        $response = wp_remote_post(self::API_ENDPOINT, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json'
            ),
            'body' => json_encode(array(
                'text'   => $text,
                'source' => get_locale(), // 可以改成网站默认语言或者你自己的配置值，如 get_option('ms_source_language')
                // 'source' => 'auto',// 可以选择自动，但是自动会将某些英文内容的字段（比如网站）也进行翻译，并不建议
                'target' => $target_lang
            ))
        ));
        if (is_wp_error($response)) {
            MS_Logger::log_error(0, $target_lang, '翻译 API 错误：' . $response->get_error_message());
            return $text; // 出错时返回原文
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['translations'][0]['text'])) {
            return $body['translations'][0]['text'];
        }
        return $text;
    }
}
