<?php
/**
 * demo/callback_receiver.php - 模擬來源站台接收金流回呼
 *
 * 收到回呼後：驗簽 → 記錄到檔案（demo/callback_log.txt）→ 回應 OK
 * 正式環境的來源站台請照這個邏輯實作（並刪除 demo 目錄）。
 */

$secret = 'DEMO-SECRET-123456';
$logFile = dirname(__FILE__) . '/callback_log.txt';

require_once(dirname(__FILE__) . '/../includes/common.php');

$post = $_POST;
if (count($post) === 0) {
    // 僅供本機測試：沒有 POST 時可手動帶參數
    $post = $_GET;
}

// 沒有帶簽章或完全沒有參數 → 只是有人用瀏覽器開這個頁面看日誌，不算錯誤
$isCallback = isset($_POST['sign']) || count($_POST) > 0;
if ($isCallback) {
    $sign = isset($post['sign']) ? $post['sign'] : '';
    unset($post['sign']);
    $expect = nw_sign($secret, $post, 'sign');
    $ok = NewebPayCrypto::safeEquals($expect, strtoupper($sign));

    $line = '[' . date('Y-m-d H:i:s') . '] ' . ($ok ? '簽章正確' : '簽章錯誤')
        . ' ' . json_encode($_POST, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
} else {
    $ok = null;
}

if ($isCallback) {
    header('Content-Type: text/plain; charset=utf-8');
    echo $ok ? 'OK' : 'FAIL: sign error';
    exit;
}

// 沒帶參數時顯示紀錄
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><title>收到的回呼</title>
<style>body{font:14px/1.7 -apple-system,"PingFang TC",sans-serif;max-width:900px;margin:30px auto;color:#22262e}
pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;white-space:pre-wrap}</style></head><body>
<h2>來源站台收到的金流回呼</h2>
<p>回應內容：驗簽成功回 <code>OK</code>、失敗回 <code>FAIL: sign error</code>（本站會據此判斷回呼是否成功並記錄）。</p>
<pre><?php echo file_exists($logFile) ? htmlspecialchars(file_get_contents($logFile), ENT_QUOTES, 'UTF-8') : '(尚無紀錄)'; ?></pre>
<p><a href="source_site.php">回模擬來源站台</a></p>
</body></html>