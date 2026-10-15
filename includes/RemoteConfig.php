<?php
/**
 * RemoteConfig.php - 讀取 wh-console 的後台設定（僅供「顯示用」字串）
 *
 * 設計原則：付款流程不能被外部服務拖垮，所以
 *   1. 結果快取 5 分鐘（寫在暫存目錄）
 *   2. 連線失敗直接回空值（fail-open），頁面照常顯示預設文字
 *   3. 只用於公告、客服信箱這類顯示字串，不影響金額、付款方式與交易判定
 */

function nw_remote_config()
{
    if (!defined('ADMIN_ENDPOINT') || ADMIN_ENDPOINT === '') {
        return array();
    }
    if (function_exists('session_start')) {
        @session_start();
    }
    if (isset($_SESSION['nw_remote_cfg']) && $_SESSION['nw_remote_cfg'] !== array()) {
        return $_SESSION['nw_remote_cfg'];
    }

    $cacheFile = sys_get_temp_dir() . '/nw_remote_config.json';
    $ttl = 300;
    clearstatcache();
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $json = json_decode(file_get_contents($cacheFile), true);
        if (is_array($json) && isset($json['config']) && is_array($json['config'])) {
            if (function_exists('session_start')) {
                $_SESSION['nw_remote_cfg'] = $json['config'];
            }
            return $json['config'];
        }
    }

    $token = defined('ADMIN_TOKEN') ? ADMIN_TOKEN : '';
    $resp = Http::get(ADMIN_ENDPOINT, 5, array('X-Admin-Token' => $token));
    if ($resp['code'] < 200 || $resp['code'] >= 300) {
        return array();   // 取不到就用預設值，不要影響付款
    }
    $json = json_decode($resp['body'], true);
    if (!is_array($json) || !isset($json['config']) || !is_array($json['config'])) {
        return array();
    }
    @file_put_contents($cacheFile, $resp['body']);
    if (function_exists('session_start')) {
        $_SESSION['nw_remote_cfg'] = $json['config'];
    }
    return $json['config'];
}

/** 取遠端設定字串，沒有就用預設值 */
function nw_cfg($key, $default)
{
    $cfg = nw_remote_config();
    if (isset($cfg[$key]) && $cfg[$key] !== '' && $cfg[$key] !== null) {
        return $cfg[$key];
    }
    return $default;
}