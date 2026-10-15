<?php
/**
 * demo/source_site.php - 模擬「公司其他網站」（來源站台）
 *
 * 示範如何把付款需求轉送到本付款閘道，並驗證來源站台如何接收付款結果回呼。
 * 僅供測試，正式環境請刪除 demo 目錄。
 */

require_once(dirname(__FILE__) . '/../includes/common.php');
require_once(dirname(__FILE__) . '/../includes/layout.php');

$secret = 'DEMO-SECRET-123456';
$result = '';
$curl = '';
$payUrl = '';

$sourceCode = nw_post('source', 'demo');
$sourceOrderNo = nw_post('source_order_no', 'DEMO' . date('ymdHis'));
$amount = nw_post('amount', '480');
$itemDesc = nw_post('item_desc', '測試商品');
$email = nw_post('buyer_email', 'buyer@example.com');
$payType = nw_post('pay_type', 'both');
$callbackUrl = nw_post('callback_url', nw_base('demo/callback_receiver.php'));

if (isset($_POST['send'])) {
    $fields = array(
        'source' => $sourceCode,
        'source_order_no' => $sourceOrderNo,
        'amount' => $amount,
        'item_desc' => $itemDesc,
        'buyer_name' => nw_post('buyer_name', '王小明'),
        'buyer_email' => $email,
        'buyer_phone' => nw_post('buyer_phone', ''),
        'pay_type' => $payType,
        'callback_url' => $callbackUrl,
        'timestamp' => time(),
        'mode' => 'json',
    );
    $fields['sign'] = nw_sign($secret, $fields, 'sign');

    $resp = Http::postForm(nw_base('pay_request.php'), $fields, 10);
    $result = 'HTTP ' . $resp['code'] . "\n" . $resp['body'];
    $json = json_decode($resp['body'], true);
    if (is_array($json) && isset($json['pay_url'])) {
        $payUrl = $json['pay_url'];
    }

    // 產生等效的 curl 指令，讓其他網站工程參考
    $pairs = array();
    foreach ($fields as $k => $v) {
        $pairs[] = "-d '" . $k . "=" . $v . "'";
    }
    $curl = "curl -X POST '" . nw_base('pay_request.php') . "' \\\n  " . implode(" \\\n  ", $pairs);
}

nw_page_head('模擬來源站台', '送出付款需求 → 收款結果回呼');
echo '<div class="notice">這是「公司其他網站」的模擬頁：送出付款需求給付款閘道，並接收金流回呼。'
    . '回呼紀錄會存成檔案方便檢視。</div>';
echo '<form method="post">';
echo '<input type="hidden" name="send" value="1">';
echo '<table>';
echo '<tr><td class="k">來源站台代號</td><td><input name="source" value="' . nw_e($sourceCode) . '" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px"></td></tr>';
echo '<tr><td class="k">來源訂單編號</td><td><input name="source_order_no" value="' . nw_e($sourceOrderNo) . '" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px"></td></tr>';
echo '<tr><td class="k">金額（元）</td><td><input name="amount" value="' . nw_e($amount) . '" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px"></td></tr>';
echo '<tr><td class="k">商品說明</td><td><input name="item_desc" value="' . nw_e($itemDesc) . '" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px"></td></tr>';
echo '<tr><td class="k">付款人信箱</td><td><input name="buyer_email" value="' . nw_e($email) . '" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px"></td></tr>';
echo '<tr><td class="k">付款方式</td><td><select name="pay_type"><option value="both">線上付款 + ATM 虛擬帳號</option><option value="credit">僅線上付款</option><option value="atm">僅 ATM 虛擬帳號</option></select></td></tr>';
echo '<tr><td class="k">回呼網址</td><td><input name="callback_url" size="50" value="' . nw_e($callbackUrl) . '" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px"></td></tr>';
echo '</table>';
echo '<p><button class="btn" type="submit">送出付款需求</button> <a class="btn ghost" href="callback_receiver.php">查看收到的回呼</a></p>';
echo '</form>';

if ($result !== '') {
    echo '<h2>付款閘道回應</h2><pre>' . nw_e($result) . '</pre>';
    if ($payUrl !== '') {
        echo '<p><a class="btn" href="' . nw_e($payUrl) . '">前往付款頁（模擬消費者）</a></p>';
    }
    echo '<h2>等效 curl 指令</h2><pre>' . nw_e($curl) . '</pre>';
}
nw_page_foot();