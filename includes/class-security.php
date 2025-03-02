<?php
class MS_Security
{
    public static function init()
    {
        // 注册 REST API 访问控制过滤器
        add_filter('rest_authentication_errors', array(__CLASS__, 'protect_api_endpoints'));
    }
    /**
     * 保护 REST API 端点（示例：仅允许管理员访问 /ms-sync/ 开头的接口）
     */
    public static function protect_api_endpoints($result)
    {
        $route = isset($GLOBALS['wp']->query_vars['rest_route']) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
        if (strpos($route, '/ms-sync/') === 0) {
            if (! current_user_can('manage_options')) {
                return new WP_Error('rest_forbidden', __('您没有权限访问此资源', 'multisite-sync'), array('status' => 403));
            }
        }
        return $result;
    }
    // 检查字符串是否已加密
    public static function is_encrypted($data)
    {
        // 加密后的数据是 Base64 编码的，且长度较长
        return base64_decode($data, true) !== false && strlen($data) > 32;
    }
    // 加密方法
    public static function encrypt($data)
    {
        $iv = substr(AUTH_SALT, 0, 16); // 使用 WordPress 的 AUTH_SALT 作为初始化向量
        return base64_encode(openssl_encrypt($data, 'AES-256-CBC', AUTH_KEY, OPENSSL_RAW_DATA, $iv));
    }
    // 解密方法
    public static function decrypt($data)
    {
        $iv = substr(AUTH_SALT, 0, 16); // 使用相同的初始化向量
        return openssl_decrypt(base64_decode($data), 'AES-256-CBC', AUTH_KEY, OPENSSL_RAW_DATA, $iv);
    }
}
