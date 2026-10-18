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

// 沒帶參數時顯示紀錄（用表格呈現，長 JSON 會自動換行不再溢出畫面）
require_once(dirname(__FILE__) . '/../includes/layout.php');
$lines = array();
if (file_exists($logFile)) {
    $lines = array_reverse(array_filter(explode("\n", file_get_contents($logFile))));
}

$rows = array();
$okCount = 0;
$errCount = 0;
foreach ($lines as $l) {
    if (preg_match('/^\[([^\]]+)\]\s*(簽章正確|簽章錯誤)\s*(.*)$/u', $l, $m)) {
        $payload = json_decode($m[3], true);
        $rows[] = array('time' => $m[1], 'verdict' => $m[2], 'raw' => $m[3],
            'data' => is_array($payload) ? $payload : array());
        if ($m[2] === '簽章正確') { $okCount++; } else { $errCount++; }
    }
}

$only = nw_get('only', '');
if ($only === 'error') {
    $filtered = array();
    foreach ($rows as $r) {
        if ($r['verdict'] === '簽章錯誤') { $filtered[] = $r; }
    }
    $rows = $filtered;
}

nw_page_head('來源站台收到的金流回呼', '這是「公司其他網站」端的模擬接收器');
echo '<p>回應規則：驗簽成功回 <code>OK</code>、失敗回 <code>FAIL: sign error</code>'
    . '（付款閘道會據此判斷回呼是否成功並記錄到 pay_callbacks）。<br>'
    . '模擬面板情境 ⑪ 會刻意讓驗簽失敗一次，那是預期行為。</p>';

echo '<div class="logsum">';
echo '<span class="badge">共 ' . count($rows) . ' 筆</span>';
echo '<span class="ok">簽章正確 ' . $okCount . ' 筆</span>';
echo '<span class="bad">簽章錯誤 ' . $errCount . ' 筆</span>';
echo '<a class="btn ghost sm" href="callback_receiver.php">顯示全部</a> ';
echo '<a class="btn ghost sm" href="callback_receiver.php?only=error">只看簽章錯誤</a> ';
echo '<a class="btn ghost sm" href="callback_receiver.php">重新整理</a>';
echo '</div>';

if (count($rows) === 0) {
    echo '<p class="muted">尚無回呼紀錄。先到模擬來源站台送出一筆付款需求。</p>';
}

foreach ($rows as $r) {
    $d = $r['data'];
    $cls = $r['verdict'] === '簽章正確' ? 'ok' : 'bad';
    echo '<div class="card">';
    echo '<div class="logsum" style="margin:0 0 6px">';
    echo '<span class="' . $cls . '"><b>' . nw_e($r['verdict']) . '</b></span>';
    echo '<span class="muted">' . nw_e($r['time']) . '</span>';
    if (isset($d['order_no'])) { echo '<span>訂單 <code>' . nw_e($d['order_no']) . '</code></span>'; }
    if (isset($d['source_order_no'])) { echo '<span>來源訂單 <code>' . nw_e($d['source_order_no']) . '</code></span>'; }
    if (isset($d['status'])) { echo '<span>狀態 ' . nw_e($d['status']) . '（' . nw_e(isset($d['status_text']) ? $d['status_text'] : '') . '）</span>'; }
    if (isset($d['amount'])) { echo '<span>金額 NT$' . number_format((float)$d['amount']) . '</span>'; }
    if (isset($d['payment_type'])) { echo '<span>付款方式 ' . nw_e($d['payment_type']) . '</span>'; }
    if (isset($d['message'])) { echo '<span class="muted">' . nw_e($d['message']) . '</span>'; }
    echo '</div>';
    if (!empty($d)) {
        $detail = '<pre>' . nw_e(json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '<details><summary class="muted">完整內容（' . count($d) . ' 個欄位）</summary>' . $detail . '</details>';
    } else {
        echo '<pre>' . nw_e($r['raw']) . '</pre>';
    }
    echo '</div>';
}

echo '<p><a class="btn ghost sm" href="source_site.php">回模擬來源站台</a> ';
echo '<a class="btn ghost sm" href="callback_receiver.php">重新整理</a></p>';
nw_page_foot();