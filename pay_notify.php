<?php
/**
 * pay_notify.php - 【第二支頁面】接收付款結果
 *
 * 三種來源（以參數 src 區分，藍新規範要求 NotifyURL 與 ReturnURL 不可相同）：
 *   ?src=notify    藍新幕後通知（以此為最終依據，務必允許外站 POST 進來）
 *   ?src=return    消費者付款完成後被導回（前台顯示用，同樣會記錄與回呼）
 *   ?src=customer  ATM / 超商取號結果（虛擬帳號）
 *
 * 驗證順序：TradeSha → 解密 → 商店代號 → 金額 → 訂單存在
 * 每次收到都寫 pay_logs；交易結果改變時回呼來源站台並寫 pay_callbacks（成功與否都記）
 */

require_once(dirname(__FILE__) . '/includes/common.php');

$ip = nw_client_ip();
$src = nw_req('src', 'notify');
if (!in_array($src, array('notify', 'return', 'customer'), true)) {
    $src = 'notify';
}

$post = $_POST;
if (count($post) === 0) {
    // 部分環境以 GET 帶參數（例如 ReturnURL 以瀏覽器導向）
    $post = $_GET;
}

$res = PayGateway::handleIncoming($post, $src, $ip);

// ---- 前台返回：導向結果頁給消費者看
if ($src === 'return') {
    if ($res['order_no'] !== '') {
        nw_redirect(nw_base('pay_result.php') . '?ono=' . urlencode($res['order_no']));
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo 'FAIL: ' . $res['msg'];
    exit;
}

// ---- 幕後通知 / 取號：回應純文字即可（藍新不檢查內容，但仍回應成功讓它知道已收到）
header('Content-Type: text/plain; charset=utf-8');
if ($res['ok']) {
    echo 'SUCCESS';
} else {
    echo 'FAIL: ' . $res['msg'];
}