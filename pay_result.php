<?php
/**
 * pay_result.php - 消費者付款結果頁
 * 線上付款：顯示成功 / 失敗
 * ATM 虛擬帳號：顯示銀行、分公司、虛擬帳號、金額、繳費期限
 */

require_once(dirname(__FILE__) . '/includes/common.php');
require_once(dirname(__FILE__) . '/includes/layout.php');

$orderNo = nw_get('ono');
$order = PayGateway::getOrder($orderNo);
if (!$order) {
    nw_page_head('查無付款資料', '');
    echo '<p class="bad">查無此訂單：' . nw_e($orderNo) . '</p>';
    nw_page_foot();
    exit;
}

nw_page_head('付款結果', '訂單編號 ' . $order['order_no']);

if ($order['status'] === 'PAID') {
    echo '<h2 class="ok">付款成功</h2>';
} elseif ($order['status'] === 'VACC_ISSUED') {
    echo '<h2 class="warn">ATM 虛擬帳號已產生，請依下列資訊轉帳</h2>';
} elseif ($order['status'] === 'FAILED' || $order['status'] === 'CANCELLED') {
    echo '<h2 class="bad">付款未完成</h2>';
    $__mail = nw_cfg('support_email', '');
    if ($__mail !== '') {
        echo '<p>若已扣款但狀態未更新，請來信 ' . nw_e($__mail) . '（請附訂單編號），我們會協助確認。</p>';
    }
} else {
    echo '<h2>尚未收到付款結果</h2>';
    echo '<p class="notice">若您已完成信用卡付款，結果可能需數秒鐘才會回傳；若使用 ATM 虛擬帳號請見下方帳號資訊。</p>';
}

echo '<table>';
echo '<tr><td class="k">訂單編號</td><td><code>' . nw_e($order['order_no']) . '</code></td></tr>';
echo '<tr><td class="k">付款說明</td><td>' . nw_e($order['item_desc']) . '</td></tr>';
echo '<tr><td class="k">金額</td><td><span class="amount">NT$' . number_format($order['amount']) . '</span></td></tr>';
echo '<tr><td class="k">付款方式</td><td>' . nw_e(nw_payment_type_text($order['payment_type']) !== '' ? nw_payment_type_text($order['payment_type']) : nw_paytype_text($order['pay_type'])) . '</td></tr>';
if ($order['pay_time'] !== '') {
    echo '<tr><td class="k">付款時間</td><td>' . nw_e($order['pay_time']) . '</td></tr>';
}
if ($order['neweb_trade_no'] !== '') {
    echo '<tr><td class="k">藍新交易序號</td><td>' . nw_e($order['neweb_trade_no']) . '</td></tr>';
}
if ($order['status_msg'] !== '') {
    echo '<tr><td class="k">藍新回應</td><td>' . nw_e($order['status_msg']) . '</td></tr>';
}
echo '</table>';

if ($order['virtual_account'] !== '') {
    echo '<div class="vacc">ATM 虛擬帳號：' . nw_e($order['virtual_account']) . '</div>';
    echo '<table>';
    echo '<tr><td class="k">銀行</td><td>' . nw_e($order['bank_name']) . '</td></tr>';
    echo '<tr><td class="k">銀行代號</td><td>' . nw_e($order['bank_code']) . '</td></tr>';
    echo '<tr><td class="k">分公司</td><td>' . nw_e($order['branch_name']) . '</td></tr>';
    echo '<tr><td class="k">繳費期限</td><td>' . nw_e($order['expire_date']) . '</td></tr>';
    echo '</table>';
    echo '<div class="notice">請使用 <b>ATM 轉帳</b>或<b>網路銀行</b>匯款（不接受臨櫃匯款），單次轉帳金額需與訂單金額完全相同。</div>';
}

echo '<p style="margin-top:20px"><a class="btn ghost" href="pay_query.php?ono=' . urlencode($order['order_no']) . '">訂單查詢</a> ';
echo '<a class="btn ghost" href="' . nw_e(nw_base('pay_request.php')) . '">回到付款閘道</a></p>';

nw_page_foot();