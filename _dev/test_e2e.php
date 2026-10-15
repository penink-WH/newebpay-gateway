<?php
/**
 * _dev/test_e2e.php - 端對端自動測試（HTTP 實際呼叫，含模擬藍新與模擬來源站台）
 *
 * 覆蓋情境：
 *   A. 簽章錯誤的付款需求要被拒絕
 *   B. timestamp 過期要被拒絕
 *   C. 正常付款需求：credit → 確認 → 導向藍新（TradeSha/解密驗證）→ 藍新回 PAID
 *      → 來源站台收到回呼 → pay_callbacks 記錄成功
 *   D. ATM 虛擬帳號：pay_type=atm → 取號 CustomerURL → 虛擬帳號入庫 → 再回呼
 *   E. 重複轉送同一來源訂單編號 → 被唯一鍵擋下（不重複出貨）
 *   F. 偽造 TradeSha 的通知要被拒絕
 *   G. 查詢 API：向藍新查詢 + pay_query 頁面可讀
 *
 * 執行：php _dev/test_e2e.php
 */

require_once(dirname(__FILE__) . '/../includes/common.php');

$base = rtrim(BASE_URL, '/');
$secret = 'DEMO-SECRET-123456';
$pass = 0;
$fail = 0;

function check($name, $cond, $info)
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] {$name}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$name}\n         → " . (is_scalar($info) ? $info : print_r($info, true)) . "\n";
    }
}

function section($t)
{
    echo "\n== {$t} ==\n";
}

/** 送出付款需求（來源站台身分，含簽章） */
function send_request($fields, $signOverride, $base)
{
    $post = $fields;
    $post['sign'] = ($signOverride === null)
        ? nw_sign('DEMO-SECRET-123456', $fields, 'sign')
        : $signOverride;
    $resp = Http::postForm($base . '/pay_request.php', $post, 15);
    return $resp;
}

function req($method, $url, $fields, $followRedirect)
{
    $resp = Http::postForm($url, $fields, 20);
    return $resp;
}

/** 直接組出藍新加密結果，偽造通知用 */
function fake_notify_fields($order, $status, $message, $tradeNo, $amtOverride = null)
{
    $c = new NewebPayCrypto(NEWEB_HASH_KEY, NEWEB_HASH_IV, NEWEB_PAD_BLOCK);
    $result = array(
        'Status' => $status,
        'Message' => $message,
        'MerchantID' => NEWEB_MERCHANT_ID,
        'Amt' => $amtOverride === null ? (int)$order['amount'] : (int)$amtOverride,
        'TradeNo' => $tradeNo,
        'MerchantOrderNo' => $order['order_no'],
        'PaymentType' => 'CREDIT',
        'PayTime' => date('Y-m-d H:i:s'),
        'IP' => '127.0.0.1',
        'EscrowBank' => '中國信託',
    );
    $ti = $c->encryptParams($result);
    return array(
        'Status' => $status,
        'MerchantID' => NEWEB_MERCHANT_ID,
        'TradeInfo' => $ti,
        'TradeSha' => $c->makeTradeSha($ti),
        'Version' => NEWEB_VERSION,
    );
}

$stamp = date('ymdHis') . rand(10, 99);

// ---------------------------------------------------------------- A/B 惡意請求
section('A/B 簽章與 timestamp 防護');
$r = send_request(array(
    'source' => 'demo', 'source_order_no' => 'E2E-' . $stamp . '-BAD', 'amount' => 100,
    'timestamp' => time(), 'mode' => 'json',
), 'deadbeef', $base);
check('錯誤簽章被拒絕', strpos($r['body'], 'sign 驗證失敗') !== false, $r['body']);
$n = (int)DB::queryValue("SELECT COUNT(*) FROM `pay_orders` WHERE `source_order_no` = 'E2E-{$stamp}-BAD'", 0);
check('錯誤簽章不會建單', $n === 0, "count={$n}");

$r = send_request(array(
    'source' => 'demo', 'source_order_no' => 'E2E-' . $stamp . '-OLD', 'amount' => 100,
    'timestamp' => time() - 9999, 'mode' => 'json',
), null, $base);
check('過期 timestamp 被拒絕', strpos($r['body'], 'timestamp 過期') !== false, $r['body']);

$r = send_request(array(
    'source' => 'no_such_site', 'source_order_no' => 'X', 'amount' => 100,
    'timestamp' => time(), 'mode' => 'json',
), null, $base);
check('不存在的來源站台被拒絕', strpos($r['body'], '來源站台不存在') !== false, $r['body']);

// ---------------------------------------------------------------- C 線上付款流程
section('C 線上付款（信用卡）完整流程');
$srcOrder = 'E2E-' . $stamp . '-CREDIT';
$r = send_request(array(
    'source' => 'demo', 'source_order_no' => $srcOrder, 'amount' => 660,
    'item_desc' => 'E2E 測試信用卡付款', 'buyer_email' => 'e2e@example.com',
    'pay_type' => 'credit', 'callback_url' => $base . '/demo/callback_receiver.php',
    'timestamp' => time(), 'mode' => 'json',
), null, $base);
$json = json_decode($r['body'], true);
check('付款需求被接受並回 JSON', is_array($json) && $json['ok'] === true, $r['body']);
$orderNo = $json['order_no'];
$order = PayGateway::getOrder($orderNo);
check('訂單已寫入 MySQL', $order !== false && (int)$order['amount'] === 660, $order ? $order['amount'] : 'not found');
check('訂單初始狀態 CREATED', $order['status'] === 'CREATED', $order['status']);

$logs = PayGateway::logs($orderNo, 50);
check('收到需求已記錄 RECEIVE/CREATE_ORDER', count($logs) === 1 && $logs[0]['stage'] === 'RECEIVE' && $logs[0]['action'] === 'CREATE_ORDER',
    count($logs) . ' ' . (isset($logs[0]['action']) ? $logs[0]['action'] : ''));

// 確認頁
$confirmHtml = Http::postForm($base . '/pay_request.php?step=confirm&ono=' . urlencode($orderNo) . '&t=x', array(), 15);
check('確認頁顯示金額 660', strpos($confirmHtml['body'], '660') !== false, 'no amount');
check('確認頁含確認付款按鈕', strpos($confirmHtml['body'], '確認付款') !== false, 'no button');

// 送出確認（driver: step=dispatch）
$secretUse = 'DEMO-SECRET-123456';
$token = nw_hmac_sha256($secretUse, $orderNo . '|660');
$bad = Http::postForm($base . '/pay_request.php?step=dispatch', array('ono' => $orderNo, 't' => 'wrong', 'pay_type' => 'credit'), 15);
check('錯誤權杖無法導向藍新', strpos($bad['body'], 'token') !== false, $bad['body']);

$disp = Http::postForm($base . '/pay_request.php?step=dispatch', array('ono' => $orderNo, 't' => $token, 'pay_type' => 'credit'), 15);
check('導向頁含藍新 MPG action', strpos($disp['body'], 'act=mpg') !== false, substr($disp['body'], 0, 200));
check('導向頁含 TradeSha 欄位', strpos($disp['body'], 'name="TradeSha"') !== false, 'no TradeSha');
$order = PayGateway::getOrder($orderNo);
check('導向後狀態 DISPATCHED', $order['status'] === 'DISPATCHED', $order['status']);

// 取出 TradeInfo/TradeSha 實際送到模擬藍新
preg_match('/name="TradeInfo" value="([^"]+)"/', $disp['body'], $m1);
preg_match('/name="TradeSha" value="([^"]+)"/', $disp['body'], $m2);
check('可取出 TradeInfo 與 TradeSha', isset($m1[1]) && isset($m2[1]), '');

$mpg = Http::postForm($base . '/_dev/mock_newebpay_gateway.php?act=mpg', array(
    'MerchantID' => NEWEB_MERCHANT_ID, 'TradeInfo' => $m1[1], 'TradeSha' => $m2[1], 'Version' => NEWEB_VERSION,
), 15);
check('模擬藍新：TradeSha 正確', strpos($mpg['body'], '驗證：<span class="ok">正確') !== false, 'sha fail');
check('模擬藍新：解密看到付款金額 660', strpos($mpg['body'], '660') !== false, 'amount not found');
check('模擬藍新：NotifyURL 指回本站', strpos($mpg['body'], 'pay_notify.php?src=notify') !== false, 'notify url');
check('模擬藍新：ReturnURL 指回本站', strpos($mpg['body'], 'pay_notify.php?src=return') !== false, 'return url');
check('信用卡訂單未開 VACC', strpos($mpg['body'], '>VACC<') === false, 'VACC should be absent');

// 模擬藍新完成付款（會 POST NotifyURL + ReturnURL）
$logFile = dirname(__FILE__) . '/../demo/callback_log.txt';
clearstatcache();
$before = file_exists($logFile) ? filesize($logFile) : 0;
$logTail = '';
$settle = Http::postForm($base . '/_dev/mock_newebpay_gateway.php?act=settle', array(
    'ono' => $orderNo, 'step' => 'paid', 'mode' => 'credit', 'amt' => 660,
), 25);
check('模擬藍新回應 NotifyURL 成功', strpos($settle['body'], 'NotifyURL（幕後通知）→ HTTP 200 SUCCESS') !== false,
    nw_truncate($settle['body'], 400));

$order = PayGateway::getOrder($orderNo);
check('訂單狀態變成 PAID', $order['status'] === 'PAID', $order['status']);
check('藍新交易序號已入庫', $order['neweb_trade_no'] !== '', $order['neweb_trade_no']);
check('付款方式記為 CREDIT', $order['payment_type'] === 'CREDIT', $order['payment_type']);
check('回呼狀態 SENT', $order['callback_state'] === 'SENT', $order['callback_state']);
check('回呼時間有值', $order['callback_at'] !== null && $order['callback_at'] !== '', var_export($order['callback_at'], true));

clearstatcache();
$after = filesize($logFile);
$logTail = $after > $before ? substr(file_get_contents($logFile), $before) : '';
check('來源站台確實收到回呼（檔案變大）', $after > $before, "{$before} → {$after}");
check('來源站台驗簽正確', strpos($logTail, '簽章錯誤') === false, nw_truncate($logTail, 300));
check('回呼內容含 PAID 狀態', strpos($logTail, '"status":"PAID"') !== false, nw_truncate($logTail, 300));

$cbs = PayGateway::callbacks($orderNo);
check('pay_callbacks 有紀錄且成功', count($cbs) >= 1 && $cbs[0]['is_success'] == 1 && $cbs[0]['response_code'] === '200',
    count($cbs) . ' rows, first=' . (isset($cbs[0]['response_code']) ? $cbs[0]['response_code'] : '-'));
check('pay_callbacks 記錄來源站台回應 OK', strpos($cbs[0]['response_body'], 'OK') !== false, $cbs[0]['response_body']);

// 重複通知不重複回呼
$cbCount = count(PayGateway::callbacks($orderNo));
Http::postForm($base . '/_dev/mock_newebpay_gateway.php?act=settle', array(
    'ono' => $orderNo, 'step' => 'paid', 'mode' => 'credit', 'amt' => 660,
), 25);
check('重複通知不會重複回呼來源站台', count(PayGateway::callbacks($orderNo)) === $cbCount,
    $cbCount . ' → ' . count(PayGateway::callbacks($orderNo)));

// ---------------------------------------------------------------- D ATM 虛擬帳號
section('D ATM 虛擬帳號流程');
$srcOrderAtm = 'E2E-' . $stamp . '-ATM';
$r = send_request(array(
    'source' => 'demo', 'source_order_no' => $srcOrderAtm, 'amount' => 3500,
    'item_desc' => 'E2E 測試 ATM', 'pay_type' => 'atm',
    'callback_url' => $base . '/demo/callback_receiver.php',
    'timestamp' => time(), 'mode' => 'json',
), null, $base);
$json = json_decode($r['body'], true);
$orderAtm = $json['order_no'];
$order = PayGateway::getOrder($orderAtm);
$tokenAtm = nw_hmac_sha256($secretUse, $orderAtm . '|3500');
$disp = Http::postForm($base . '/pay_request.php?step=dispatch', array('ono' => $orderAtm, 't' => $tokenAtm, 'pay_type' => 'atm'), 15);
preg_match('/name="TradeInfo" value="([^"]+)"/', $disp['body'], $m3);
preg_match('/name="TradeSha" value="([^"]+)"/', $disp['body'], $m4);
$mpgAtm = Http::postForm($base . '/_dev/mock_newebpay_gateway.php?act=mpg', array(
    'MerchantID' => NEWEB_MERCHANT_ID, 'TradeInfo' => $m3[1], 'TradeSha' => $m4[1], 'Version' => NEWEB_VERSION,
), 15);
check('ATM 訂單開啟 VACC', strpos($mpgAtm['body'], '>VACC<') !== false && strpos($mpgAtm['body'], '<td>1</td>') !== false, 'VACC missing');
check('ATM 訂單帶 CustomerURL', strpos($mpgAtm['body'], 'pay_notify.php?src=customer') !== false, 'customer url');
check('ATM 訂單帶 ExpireDate', strpos($mpgAtm['body'], 'name="ExpireDate"') !== false || strpos($mpgAtm['body'], 'ExpireDate') !== false, '');

Http::postForm($base . '/_dev/mock_newebpay_gateway.php?act=settle', array(
    'ono' => $orderAtm, 'step' => 'vacc', 'mode' => 'vacc', 'amt' => 3500,
), 25);
$order = PayGateway::getOrder($orderAtm);
check('ATM 虛擬帳號已入庫', $order['virtual_account'] !== '', var_export($order['virtual_account'], true));
check('ATM 銀行/分行已入庫', $order['bank_name'] === '臺灣銀行' && $order['branch_name'] === '臺北分行' && $order['bank_code'] === '004',
    $order['bank_name'] . '/' . $order['branch_name'] . '/' . $order['bank_code']);
check('取號後狀態 VACC_ISSUED', $order['status'] === 'VACC_ISSUED', $order['status']);
check('取號也回呼來源站台', count(PayGateway::callbacks($orderAtm)) >= 1, count(PayGateway::callbacks($orderAtm)) . '');
$cbLog = file_get_contents(dirname(__FILE__) . '/../demo/callback_log.txt');
check('回呼帶有虛擬帳號資訊', strpos($cbLog, '"virtual_account":"') !== false, 'no vaccount in log');

// 消費者結果頁
$res = Http::postForm($base . '/pay_result.php?ono=' . urlencode($orderAtm), array(), 15);
check('結果頁顯示 ATM 虛擬帳號', strpos($res['body'], $order['virtual_account']) !== false, 'vaccount missing');
check('結果頁提示繳費期限', strpos($res['body'], '繳費期限') !== false, '');
check('取號後尚未收到付款結果', strpos($res['body'], 'ATM 虛擬帳號已產生') !== false, '');

// 消費者完成 ATM 轉帳 → 藍新回傳付款成功
$cbBefore = count(PayGateway::callbacks($orderAtm));
Http::postForm($base . '/_dev/mock_newebpay_gateway.php?act=settle', array(
    'ono' => $orderAtm, 'step' => 'vacc_paid', 'mode' => 'vacc', 'amt' => 3500,
), 25);
$order = PayGateway::getOrder($orderAtm);
check('ATM 轉帳後狀態 PAID', $order['status'] === 'PAID', $order['status']);
check('ATM 轉帳後付款方式為 VACC', $order['payment_type'] === 'VACC', $order['payment_type']);
check('ATM 轉帳後再次回呼來源站台', count(PayGateway::callbacks($orderAtm)) > $cbBefore,
    $cbBefore . ' → ' . count(PayGateway::callbacks($orderAtm)));
check('ATM 虛擬帳號仍保留在訂單', $order['virtual_account'] !== '', '');

// ---------------------------------------------------------------- E 冪等
section('E 重複轉送付款需求（冪等）');
$r2 = send_request(array(
    'source' => 'demo', 'source_order_no' => $srcOrder, 'amount' => 660,
    'item_desc' => '重複', 'pay_type' => 'credit', 'timestamp' => time(), 'mode' => 'json',
), null, $base);
$j2 = json_decode($r2['body'], true);
check('重複來源訂單編號不會重複建單',
    is_array($j2) && $j2['ok'] !== true, $r2['body']);
$n = (int)DB::queryValue("SELECT COUNT(*) FROM `pay_orders` WHERE `source_order_no` = '{$srcOrder}'", 0);
check('資料庫中該來源訂單只有一筆', $n === 1, "count={$n}");

// ---------------------------------------------------------------- F 偽造通知
section('F 偽造 / 金額不符 的通知');
$f1 = fake_notify_fields($order, 'SUCCESS', '偽造', 'TFAKE1');
$f1['TradeSha'] = str_repeat('0', 64);
$fr = Http::postForm($base . '/pay_notify.php?src=notify', $f1, 15);
check('TradeSha 錯誤的通知被拒絕', strpos($fr['body'], 'FAIL') === 0, $fr['body']);
$ord2 = PayGateway::getOrder($orderNo);
check('拒絕後訂單狀態未被改動', $ord2['status'] === 'PAID', $ord2['status']);

$f2 = fake_notify_fields($ord2, 'SUCCESS', '金額不符', 'TFAKE2', 1);
$f2['Amt'] = 1;
$fr2 = Http::postForm($base . '/pay_notify.php?src=notify', $f2, 15);
check('金額不符的通知被拒絕', strpos($fr2['body'], 'amount') !== false, $fr2['body']);

// ---------------------------------------------------------------- G 查詢
section('G 查詢與後台頁面（含登入保護）');
$noAuth = Http::postForm($base . '/pay_query.php', array(), 15);
check('未登入時查詢頁顯示密碼輸入', strpos($noAuth['body'], 'admin_pwd') !== false && strpos($noAuth['body'], $orderNo) === false,
    '未登入不應看到訂單內容');
$jar = sys_get_temp_dir() . '/nw_e2e_cookie_' . getmypid() . '.txt';
Http::postForm($base . '/pay_query.php', array('admin_pwd' => ADMIN_PASSWORD), 15, $jar);
$logged = Http::postForm($base . '/pay_query.php?ono=' . urlencode($orderNo), array(), 15, $jar);
check('登入後可看到訂單明細', strpos($logged['body'], '訂單編號') !== false && strpos($logged['body'], '回呼狀態') !== false, '');

$q = PayGateway::queryNeweb($orderNo, '127.0.0.1');
check('向藍新查詢交易成功', $q['ok'] === true, print_r($q['msg'], true));
check('查詢回傳 CheckCode 驗證通過', !empty($q['result']['CheckCode']), '');

$page = $logged;
check('後台明細頁顯示訂單', strpos($page['body'], $orderNo) !== false, '');
check('後台明細頁顯示事件紀錄', strpos($page['body'], '事件紀錄') !== false, '');
check('後台明細頁顯示回呼紀錄', strpos($page['body'], '回呼來源站台紀錄') !== false, '');
check('後台明細頁顯示虛擬帳號欄位', strpos($page['body'], 'ATM 虛擬帳號') !== false, '');

$list = Http::postForm($base . '/pay_query.php?kw=' . urlencode($orderNo), array(), 15, $jar);
check('查詢列表可依訂單編號搜尋', strpos($list['body'], $orderNo) !== false, '');
$listStatus = Http::postForm($base . '/pay_query.php?status=PAID', array(), 15, $jar);
check('查詢列表可依狀態篩選', strpos($listStatus['body'], '付款成功') !== false, '');

@unlink($jar);

echo "\n結果：{$pass} 通過 / {$fail} 失敗\n";
echo "測試訂單：{$orderNo}（信用卡）、{$orderAtm}（ATM）\n";
exit($fail === 0 ? 0 : 1);