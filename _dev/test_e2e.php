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

// 測試目標網址：可用 NW_TEST_BASE_URL 覆寫（例如部署到新機器時的本機自我檢查）
$__envBase = getenv('NW_TEST_BASE_URL');
$base = ($__envBase !== false && $__envBase !== '') ? rtrim($__envBase, '/') : rtrim(BASE_URL, '/');
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

/** 跨平台的「空輸出目的地」：Windows 沒有 /dev/null */
function curl_null_out()
{
    return (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';
}

/** POST 後取出狀態碼與 Location（PHP 的 HTTP 類別拿不到標頭，這裡借用 curl CLI） */
function post_and_location($url, $fields)
{
    $parts = array();
    foreach ($fields as $k => $v) {
        $parts[] = '--data-urlencode ' . escapeshellarg($k . '=' . $v);
    }
    $cmd = 'curl -s -o ' . curl_null_out() . ' -w "%{http_code}|%{redirect_url}" -X POST '
        . implode(' ', $parts) . ' ' . escapeshellarg($url);
    $out = shell_exec($cmd);
    $out = trim((string)$out);
    $pos = strpos($out, '|');
    return array('code' => (int)substr($out, 0, $pos), 'location' => $pos === false ? '' : substr($out, $pos + 1));
}

/** 以指定 Origin/Referer 標頭 POST 一次，只看 HTTP 狀態碼 */
function post_with_header($url, $payload, $header)
{
    if (!function_exists('shell_exec')) {
        return -1;
    }
    $cmd = 'curl -s -o ' . curl_null_out() . ' -w "%{http_code}" -X POST -H ' . escapeshellarg($header)
        . ' --data ' . escapeshellarg($payload) . ' ' . escapeshellarg($url);
    return (int)trim((string)shell_exec($cmd));
}


/**
 * 呼叫模擬藍新的 settle，取得「藍新要送出的欄位」，再由測試端自己送出。
 * 這樣即使 PHP 內建伺服器是單一執行緒（Windows）也不會自我阻塞。
 * @return array array('notify'=>fields,'return'=>fields,'customer'=>fields|null,'notify_url'=>,'return_url'=>)
 */
function mock_settle($base, $orderNo, $step, $mode, $amt)
{
    $resp = Http::postForm($base . '/_dev/mock_newebpay_gateway.php', array(
        'act' => 'settle', 'format' => 'json', 'ono' => $orderNo,
        'step' => $step, 'mode' => $mode, 'amt' => $amt,
    ), 20);
    $json = json_decode($resp['body'], true);
    if (!is_array($json)) {
        return array('error' => $resp['body']);
    }
    return $json;
}

/** 送出模擬藍新的取號結果（CustomerURL） */
function send_customer($base, $customerFields)
{
    $resp = Http::postForm($base . '/pay_notify.php?src=customer', $customerFields, 20);
    return $resp['body'];
}

/** 送出模擬藍新的付款結果（NotifyURL + ReturnURL） */
function send_result($base, $payload)
{
    $n = Http::postForm($base . '/pay_notify.php?src=notify', $payload['notify'], 20);
    $r = Http::postForm($base . '/pay_notify.php?src=return', $payload['return'], 20);
    return array('notify' => $n['body'], 'return' => $r['code']);
}

/** 直接改 pay_sources 某站台設定欄位（測試用） */
function set_source_field($code, $field, $value)
{
    DB::update('pay_sources', array($field => $value, 'updated_at' => nw_now()), array('source_code' => $code));
}

/** 還原 demo 站台設定 */
function reset_demo_source()
{
    DB::update('pay_sources', array(
        'allow_ips' => '', 'allow_origins' => '', 'is_active' => 1, 'updated_at' => nw_now(),
    ), array('source_code' => 'demo'));
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
check('不存在的來源站台被拒絕（403）', $r['code'] === 403 && strpos($r['body'], '未註冊') !== false, $r['code'] . ' ' . $r['body']);

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
$settle = mock_settle($base, $orderNo, 'paid', 'credit', 660);
check('模擬藍新產生付款結果欄位', isset($settle['notify']['TradeInfo']), nw_truncate(isset($settle['error']) ? $settle['error'] : '', 300));
$sent = send_result($base, $settle);
check('模擬藍新回應 NotifyURL 成功', strpos($sent['notify'], 'SUCCESS') === 0, $sent['notify']);
check('模擬藍新回應 ReturnURL（302 導回結果頁）', $sent['return'] === 302, 'code=' . $sent['return']);

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
$dup = mock_settle($base, $orderNo, 'paid', 'credit', 660);
send_result($base, $dup);
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

$vacc = mock_settle($base, $orderAtm, 'vacc', 'vacc', 3500);
check('模擬藍新產生 ATM 虛擬帳號欄位', isset($vacc['customer']['PayAccount']), nw_truncate(isset($vacc['error']) ? $vacc['error'] : '', 300));
$custResp = send_customer($base, $vacc['customer']);
check('取號結果已送達 CustomerURL', $custResp === 'SUCCESS', $custResp);
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
$vaccPaid = mock_settle($base, $orderAtm, 'vacc_paid', 'vacc', 3500);
send_result($base, $vaccPaid);
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
check('重複來源訂單編號以 409 回應（不重複建單）',
    is_array($j2) && $j2['ok'] === false && $j2['code'] === 409 && $j2['order_no'] === $orderNo,
    $r2['code'] . ' ' . $r2['body']);
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

// ---------------------------------------------------------------- H 兩階段准入管制
section('H 兩階段准入管制（來源端管控）');
reset_demo_source();
$okFields = array(
    'source' => 'demo', 'source_order_no' => 'GATE-' . $stamp . '-OK', 'amount' => 300,
    'item_desc' => '准入測試', 'pay_type' => 'credit', 'timestamp' => time(), 'mode' => 'json',
);
$signOk = nw_sign('DEMO-SECRET-123456', $okFields, 'sign');
$badSign = array_merge($okFields, array('sign' => 'deadbeef'));
$goodSign = array_merge($okFields, array('sign' => $signOk));

// H1 第一階段：用戶代碼
$r = Http::postForm($base . '/pay_request.php', array('source' => 'ghost_site', 'amount' => '100', 'timestamp' => time(), 'mode' => 'json', 'sign' => $x = 'x'), 15);
check('第一階段：未註冊的用戶代碼 → 403', $r['code'] === 403 && strpos($r['body'], '未註冊') !== false, $r['code'] . ' ' . $r['body']);
$lg = PayGateway::logs('', 1);
$n = (int)DB::queryValue("SELECT COUNT(*) FROM `pay_logs` WHERE `stage`='ACCESS' AND `action`='STAGE1_DENY' AND `result`='FAIL'", 0);
check('第一階段拒絕有寫入 ACCESS/STAGE1_DENY 紀錄', $n > 0, "count={$n}");

// H2 停用站台 → 第一階段擋掉
set_source_field('demo', 'is_active', 0);
$r = Http::postForm($base . '/pay_request.php', $goodSign, 15);
check('第一階段：已停用的站台 → 403', $r['code'] === 403 && strpos($r['body'], '停用') !== false, $r['code'] . ' ' . $r['body']);
set_source_field('demo', 'is_active', 1);

// H3 第二階段：sign（第一階段通過後才比對）
$r = Http::postForm($base . '/pay_request.php', $badSign, 15);
check('第二階段：sign 錯誤 → 403', $r['code'] === 403 && strpos($r['body'], 'sign') !== false, $r['code'] . ' ' . $r['body']);
$n2 = (int)DB::queryValue("SELECT COUNT(*) FROM `pay_logs` WHERE `action`='STAGE2_BAD_SIGN'", 0);
check('第二階段拒絕有寫入 STAGE2_BAD_SIGN 紀錄', $n2 > 0, "count={$n2}");

// H4 第一階段先擋：IP 不符時，即使 sign 正確也不准進入第二階段
set_source_field('demo', 'allow_ips', '203.0.113.0/24');
$r = Http::postForm($base . '/pay_request.php', $goodSign, 15);
check('IP 不在清單 → 403（即使 sign 正確）', $r['code'] === 403 && strpos($r['body'], 'IP 不在允許清單') !== false, $r['code'] . ' ' . $r['body']);
$n3 = (int)DB::queryValue("SELECT COUNT(*) FROM `pay_logs` WHERE `action`='STAGE1_DENY' AND `message` LIKE '%IP 不在允許清單%'", 0);
check('IP 拒絕判定在第二階段之前（STAGE1 而非 STAGE2）', $n3 > 0, "count={$n3}");

// H5 CIDR 比對
set_source_field('demo', 'allow_ips', '127.0.0.0/8, 10.0.0.0/8');
$cidrFields = array_merge($okFields, array('source_order_no' => 'GATE-' . $stamp . '-CIDR'));
$r = Http::postForm($base . '/pay_request.php', array_merge($cidrFields, array('sign' => nw_sign('DEMO-SECRET-123456', $cidrFields, 'sign'))), 15);
check('CIDR 允許（127.0.0.0/8）→ 訂單建立成功', strpos($r['body'], '"ok":true') !== false, $r['body']);
set_source_field('demo', 'allow_ips', '127.0.0.1');
$r = Http::postForm($base . '/pay_request.php', $goodSign, 15);
check('完整 IP 允許 → 訂單建立成功', strpos($r['body'], '"ok":true') !== false, $r['body']);

// H6 接受的來源網站（Origin/Referer）
set_source_field('demo', 'allow_ips', '');
set_source_field('demo', 'allow_origins', 'shop.example.com');
$r = Http::postForm($base . '/pay_request.php', $goodSign, 15);
check('設定來源網站但請求未帶 Origin/Referer → 403', $r['code'] === 403 && strpos($r['body'], 'Origin') !== false, $r['code'] . ' ' . $r['body']);

$originFields = array_merge($okFields, array('source_order_no' => 'GATE-' . $stamp . '-ORIGIN'));
$payload = http_build_query(array_merge($originFields, array('sign' => nw_sign('DEMO-SECRET-123456', $originFields, 'sign'))));
$codeOk = post_with_header($base . '/pay_request.php', $payload, 'Origin: https://shop.example.com');
check('Origin 符合允許網站 → 通過（建立訂單）', $codeOk === 200, 'code=' . $codeOk);

$badFields = array_merge($okFields, array('source_order_no' => 'GATE-' . $stamp . '-EVIL'));
$payloadBad = http_build_query(array_merge($badFields, array('sign' => nw_sign('DEMO-SECRET-123456', $badFields, 'sign'))));
$codeBad = post_with_header($base . '/pay_request.php', $payloadBad, 'Origin: https://evil.example.org');
check('Origin 不在允許網站 → 403', $codeBad === 403, 'code=' . $codeBad);

$refFields = array_merge($okFields, array('source_order_no' => 'GATE-' . $stamp . '-REFERER'));
$payloadRef = http_build_query(array_merge($refFields, array('sign' => nw_sign('DEMO-SECRET-123456', $refFields, 'sign'))));
$codeRef = post_with_header($base . '/pay_request.php', $payloadRef, 'Referer: https://shop.example.com/cart?id=1');
check('Referer 符合允許網站 → 通過（無 Origin 時以 Referer 判斷）', $codeRef === 200, 'code=' . $codeRef);
reset_demo_source();

// H7 來源站台管理頁（登入後可新增站台）
$newCode = 'sub_' . substr($stamp, -6);
$jar2 = sys_get_temp_dir() . '/nw_e2e_cookie2_' . getmypid() . '.txt';
$noAuth2 = Http::postForm($base . '/pay_sources_admin.php', array(), 15);
check('未登入無法管理來源站台', strpos($noAuth2['body'], 'admin_pwd') !== false && strpos($noAuth2['body'], $newCode) === false, '');
Http::postForm($base . '/pay_sources_admin.php', array('admin_pwd' => ADMIN_PASSWORD), 15, $jar2);
$save = Http::postForm($base . '/pay_sources_admin.php', array(
    'act' => 'save', 'source_code' => $newCode, 'source_name' => '測試子公司站台',
    'secret' => 'SUB-SECRET-123456', 'allow_ips' => '198.51.100.5, 192.0.2.0/24',
    'allow_origins' => 'shop.example.com', 'callback_url' => 'https://sub.example.com/notify.php',
    'result_url' => 'https://sub.example.com/thanks.php', 'allow_hosts' => 'sub.example.com',
    'is_active' => '1', 'remark' => 'e2e',
), 15, $jar2);
$list = Http::postForm($base . '/pay_sources_admin.php', array(), 15, $jar2);
check('可新增來源站台（用戶代碼/允許IP/來源網站/回呼網址）',
    strpos($list['body'], $newCode) !== false && strpos($list['body'], '198.51.100.5') !== false
    && strpos($list['body'], 'https://sub.example.com/notify.php') !== false, '');
$srcRow = DB::queryRow("SELECT * FROM `pay_sources` WHERE `source_code` = '" . $newCode . "' LIMIT 1");
check('新增後資料確實寫入 MySQL', $srcRow !== false && $srcRow['allow_ips'] === '198.51.100.5,192.0.2.0/24', $srcRow ? $srcRow['allow_ips'] : 'not found');

// 新站台可正常收款並依其設定回呼（先把本機測試 IP 加進白名單，驗證 IP 管制對新站台同樣生效）
set_source_field($newCode, 'allow_ips', '198.51.100.5, 127.0.0.1');
set_source_field($newCode, 'allow_origins', '');
$subFields = array(
    'source' => $newCode, 'source_order_no' => 'SUB-' . $stamp, 'amount' => 880,
    'item_desc' => '子公司訂單', 'pay_type' => 'credit', 'timestamp' => time(), 'mode' => 'json',
);
$r = Http::postForm($base . '/pay_request.php', array_merge($subFields, array('sign' => nw_sign('SUB-SECRET-123456', $subFields, 'sign'))), 15);
$subJson = json_decode($r['body'], true);
check('新增站台可直接收款', is_array($subJson) && $subJson['ok'] === true, $r['body']);
$subOrder = PayGateway::getOrder($subJson['order_no']);
check('訂單帶入該站台設定的 NOTIFY 回呼網址', $subOrder['callback_url'] === 'https://sub.example.com/notify.php', $subOrder['callback_url']);
check('訂單帶入該站台設定的 RESULT 導回網址', $subOrder['result_url'] === 'https://sub.example.com/thanks.php', $subOrder['result_url']);

// H8 RESULT：藍新 ReturnURL 導回後，消費者被導向來源站的 RESULT 頁（含簽章）
// （RESULT 網址在建單當下就寫入訂單，所以要先設定站台設定再建立新訂單）
reset_demo_source();
set_source_field('demo', 'result_url', $base . '/demo/callback_receiver.php?type=result');
$resFields0 = array(
    'source' => 'demo', 'source_order_no' => 'GATE-' . $stamp . '-RESULT', 'amount' => 260,
    'item_desc' => 'RESULT 導回測試', 'pay_type' => 'credit', 'timestamp' => time(), 'mode' => 'json',
);
$r = Http::postForm($base . '/pay_request.php', array_merge($resFields0, array('sign' => nw_sign('DEMO-SECRET-123456', $resFields0, 'sign'))), 15);
$resOrderNo = json_decode($r['body'], true)['order_no'];
$resOrder = PayGateway::getOrder($resOrderNo);
check('建立訂單時即帶入來源站的 RESULT 網址',
    $resOrder['result_url'] === $base . '/demo/callback_receiver.php?type=result', $resOrder['result_url']);

$resFields = fake_notify_fields($resOrder, 'SUCCESS', 'RESULT 測試', 'TRESULT1');
$loc = post_and_location($base . '/pay_notify.php?src=return', $resFields);
check('ReturnURL 以 302 導向來源站 RESULT 頁', $loc['code'] === 302 && strpos($loc['location'], 'type=result') !== false,
    'code=' . $loc['code'] . ' loc=' . $loc['location']);
check('RESULT 網址帶有訂單編號', strpos($loc['location'], 'order_no=' . $resOrderNo) !== false, $loc['location']);
check('RESULT 網址帶有簽章 sign', strpos($loc['location'], 'sign=') !== false, $loc['location']);
check('RESULT 網址帶有付款狀態', strpos($loc['location'], 'status=PAID') !== false, $loc['location']);

// 未設定 RESULT 的站台 → 導回本站結果頁
$noResult = array(
    'source' => 'demo', 'source_order_no' => 'GATE-' . $stamp . '-NORESULT', 'amount' => 260,
    'pay_type' => 'credit', 'timestamp' => time(), 'mode' => 'json',
);
set_source_field('demo', 'result_url', '');
$r = Http::postForm($base . '/pay_request.php', array_merge($noResult, array('sign' => nw_sign('DEMO-SECRET-123456', $noResult, 'sign'))), 15);
$noResOrderNo = json_decode($r['body'], true)['order_no'];
$loc2 = post_and_location($base . '/pay_notify.php?src=return', fake_notify_fields(PayGateway::getOrder($noResOrderNo), 'SUCCESS', 'ok', 'TRESULT2'));
check('未設定 RESULT 的站台改導回本站結果頁', $loc2['code'] === 302 && strpos($loc2['location'], 'pay_result.php') !== false,
    'code=' . $loc2['code'] . ' loc=' . $loc2['location']);

set_source_field('demo', 'result_url', '');
DB::exec("DELETE FROM `pay_sources` WHERE `source_code` = '" . DB::esc($newCode) . "'");
@unlink($jar2);
reset_demo_source();

// ---------------------------------------------------------------- J 金額防呆（元 vs 分）
section('J 金額上限與「元/分」防呆');
$srcRow = DB::queryRow("SELECT * FROM `pay_sources` WHERE `source_code` = 'demo' LIMIT 1");
DB::update('pay_sources', array('max_amount' => 5000, 'updated_at' => nw_now()), array('source_code' => 'demo'));

$over = array(
    'source' => 'demo', 'source_order_no' => 'AMT-' . $stamp . '-OVER', 'amount' => 14200,
    'pay_type' => 'credit', 'timestamp' => time(), 'mode' => 'json',
);
$r = Http::postForm($base . '/pay_request.php', array_merge($over, array('sign' => nw_sign('DEMO-SECRET-123456', $over, 'sign'))), 15);
$j = json_decode($r['body'], true);
check('超過站台上限的金額被拒（元/分 防呆）', $r['code'] === 422 && $j['ok'] === false, $r['code'] . ' ' . $r['body']);
check('拒絕訊息明確提示單位問題', strpos($r['body'], '分') !== false, $r['body']);
$n = (int)DB::queryValue("SELECT COUNT(*) FROM `pay_orders` WHERE `source_order_no` = 'AMT-{$stamp}-OVER'", 0);
check('被拒的訂單不會寫入資料庫', $n === 0, "count={$n}");

$okAmt = array(
    'source' => 'demo', 'source_order_no' => 'AMT-' . $stamp . '-OK', 'amount' => 142,
    'pay_type' => 'credit', 'timestamp' => time(), 'mode' => 'json',
);
$r = Http::postForm($base . '/pay_request.php', array_merge($okAmt, array('sign' => nw_sign('DEMO-SECRET-123456', $okAmt, 'sign'))), 15);
$j = json_decode($r['body'], true);
check('上限內的正常金額可建單', isset($j['ok']) && $j['ok'] === true, $r['body']);

DB::update('pay_sources', array('max_amount' => (int)$srcRow['max_amount'], 'updated_at' => nw_now()), array('source_code' => 'demo'));

// 未設上限時：可疑金額照收，但留下 WARN 提醒（避免誤擋合法的高額訂單）
$suspicious = array(
    'source' => 'demo', 'source_order_no' => 'AMT-' . $stamp . '-SUSP', 'amount' => 14200,
    'pay_type' => 'credit', 'timestamp' => time(), 'mode' => 'json',
);
$r = Http::postForm($base . '/pay_request.php', array_merge($suspicious, array('sign' => nw_sign('DEMO-SECRET-123456', $suspicious, 'sign'))), 15);
$jSusp = json_decode($r['body'], true);
check('未設上限時可疑金額仍可建單（不誤擋）', isset($jSusp['ok']) && $jSusp['ok'] === true, $r['body']);
$warn = (int)DB::queryValue("SELECT COUNT(*) FROM `pay_logs` WHERE `action` = 'AMOUNT_SUSPICIOUS'", 0);
check('可疑金額（整百且金額大）會留下 WARN 紀錄', $warn > 0, "count={$warn}");
$warnMsg = (string)DB::queryValue("SELECT message FROM `pay_logs` WHERE `action` = 'AMOUNT_SUSPICIOUS' ORDER BY id DESC LIMIT 1", '');
check('WARN 紀錄有提示元/分換算', strpos($warnMsg, '142 元') !== false, $warnMsg);

check('弱金鑰偵測：示範金鑰會被標示', PayGateway::weakSecretReason('DEMO-SECRET-123456') !== '', '');
check('弱金鑰偵測：過短金鑰會被標示', PayGateway::weakSecretReason('abc') !== '', '');
check('弱金鑰偵測：正常金鑰不標示', PayGateway::weakSecretReason('4f8a1c93d7b26e05a9f3c1d84e7b2065') === '', '');

$diag = Http::postForm($base . '/pay_sources_admin.php', array(), 15, $jar);
check('站台設定頁有設定診斷區塊', strpos($diag['body'], '設定診斷') !== false, '');
check('站台設定頁列出金額上限欄位', strpos($diag['body'], '單筆金額上限') !== false, '');
check('站台設定頁對示範金鑰提出警告', strpos($diag['body'], '示範用的測試金鑰') !== false, '');

// ---------------------------------------------------------------- I 模擬面板（回呼各種狀態）
section('I 模擬面板：各種回呼狀態');
$simOrderNo = $json['order_no'];
$simBack = 'pay_query.php?ono=' . $simOrderNo;

// 未登入不可使用
$simNoAuth = Http::postForm($base . '/pay_simulate.php?act=paid&ono=' . $simOrderNo, array(), 15);
check('未登入無法使用模擬面板', strpos($simNoAuth['body'], 'admin_pwd') !== false, '');

// 情境：重設 → 偽造通知被拒
Http::postForm($base . '/pay_simulate.php', array('act' => 'reset', 'ono' => $simOrderNo, 'back' => $simBack), 20, $jar);
$o3 = PayGateway::getOrder($simOrderNo);
check('模擬面板：重設後狀態為 DISPATCHED', $o3['status'] === 'DISPATCHED', $o3['status']);
Http::postForm($base . '/pay_simulate.php', array('act' => 'forged', 'ono' => $simOrderNo, 'back' => $simBack), 20, $jar);
$o3 = PayGateway::getOrder($simOrderNo);
check('模擬面板：偽造 TradeSha 被拒且狀態不變', $o3['status'] === 'DISPATCHED', $o3['status']);

Http::postForm($base . '/pay_simulate.php', array('act' => 'amtmismatch', 'ono' => $simOrderNo, 'back' => $simBack), 20, $jar);
$o3 = PayGateway::getOrder($simOrderNo);
check('模擬面板：金額不符被拒且狀態不變', $o3['status'] === 'DISPATCHED', $o3['status']);

// 情境：ATM 取號
Http::postForm($base . '/pay_simulate.php', array('act' => 'vac', 'ono' => $simOrderNo, 'back' => $simBack), 20, $jar);
$o3 = PayGateway::getOrder($simOrderNo);
check('模擬面板：ATM 取號寫入虛擬帳號', $o3['virtual_account'] !== '' && $o3['status'] === 'VACC_ISSUED',
    $o3['virtual_account'] . '/' . $o3['status']);

// 情境：回呼失敗演練（來源站台連線失敗）
$cbBefore = count(PayGateway::callbacks($simOrderNo));
$oBefore = PayGateway::getOrder($simOrderNo);
Http::postForm($base . '/pay_simulate.php', array('act' => 'cbfail', 'ono' => $simOrderNo, 'back' => $simBack), 40, $jar);
$o3 = PayGateway::getOrder($simOrderNo);
check('模擬面板：回呼失敗演練後回呼狀態 FAILED', $o3['callback_state'] === 'FAILED', $o3['callback_state']);
check('模擬面板：回呼失敗有逐次嘗試紀錄（3 次）', count(PayGateway::callbacks($simOrderNo)) >= $cbBefore + 3,
    ($cbBefore) . ' → ' . count(PayGateway::callbacks($simOrderNo)));
check('模擬面板：失敗後回呼網址已還原', $o3['callback_url'] === $oBefore['callback_url'], $o3['callback_url']);

// 情境：付款成功後狀態與回呼
Http::postForm($base . '/pay_simulate.php', array('act' => 'paid', 'ono' => $simOrderNo, 'back' => $simBack), 25, $jar);
$o3 = PayGateway::getOrder($simOrderNo);
check('模擬面板：付款成功演練後狀態 PAID', $o3['status'] === 'PAID', $o3['status']);
check('模擬面板：付款成功後回呼成功 SENT', $o3['callback_state'] === 'SENT', $o3['callback_state']);

// 情境：重複通知不重複回呼
$cbBefore = count(PayGateway::callbacks($simOrderNo));
Http::postForm($base . '/pay_simulate.php', array('act' => 'duplicate', 'ono' => $simOrderNo, 'back' => $simBack), 25, $jar);
check('模擬面板：重複通知不增加回呼紀錄', count(PayGateway::callbacks($simOrderNo)) === $cbBefore,
    $cbBefore . ' → ' . count(PayGateway::callbacks($simOrderNo)));

// 情境：回應非 OK（需要 demo/callback_receiver.php）
// 純檔案檢查即可，不要用 HTTP 探測（否則會在來源站台日誌留下一筆假的簽章錯誤）
$receiverFile = dirname(__FILE__) . '/../demo/callback_receiver.php';
if (file_exists($receiverFile)) {
    Http::postForm($base . '/pay_simulate.php', array('act' => 'reset', 'ono' => $simOrderNo, 'back' => $simBack), 20, $jar);
    Http::postForm($base . '/pay_simulate.php', array('act' => 'cbnoack', 'ono' => $simOrderNo, 'back' => $simBack), 40, $jar);
    $o3 = PayGateway::getOrder($simOrderNo);
    check('模擬面板：來源站台回應非 OK 判為 FAILED', $o3['callback_state'] === 'FAILED', $o3['callback_state']);
    $last = PayGateway::callbacks($simOrderNo);
    $tail = count($last) - 1;
    check('模擬面板：非 OK 的嘗試有記錄錯誤原因',
        $last[$tail]['is_success'] == 0 && $last[$tail]['error'] !== '', $last[$tail]['error']);
} else {
    echo "  [SKIP] 本機沒有 demo/callback_receiver.php，跳過「回應非 OK」演練\n";
}

// 交易明細頁要有模擬面板入口
$pageWithSim = Http::postForm($base . '/pay_query.php?ono=' . $simOrderNo, array(), 15, $jar);
check('交易明細頁提供模擬面板入口', strpos($pageWithSim['body'], 'pay_simulate.php') !== false, '');

@unlink($jar);

echo "\n結果：{$pass} 通過 / {$fail} 失敗\n";
echo "測試訂單：{$orderNo}（信用卡）、{$orderAtm}（ATM）\n";
exit($fail === 0 ? 0 : 1);