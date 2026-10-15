<?php
/**
 * _dev/mock_newebpay_gateway.php - 本機模擬藍新金流（僅測試用，上線請刪除 _dev 目錄）
 *
 * 模擬三個行為：
 *   act=mpg    接收 MPG 表單 → 驗 TradeSha/解密 → 顯示「藍新付款頁」
 *              可選擇：信用卡付款成功 / 付款失敗 / ATM 取號
 *              之後模擬藍新 POST 回 ReturnURL 與 NotifyURL，ATM 另 POST 回 CustomerURL
 *   act=query  模擬 /API/QueryTradeInfo 回應
 */

require_once(dirname(__FILE__) . '/../includes/common.php');
require_once(dirname(__FILE__) . '/../includes/layout.php');

$ip = nw_client_ip();
$act = nw_req('act', '');

function decrypt_trade_info($hex)
{
    $c = new NewebPayCrypto(NEWEB_HASH_KEY, NEWEB_HASH_IV, NEWEB_PAD_BLOCK);
    return $c->decryptParams($hex);
}

// ---------------------------------------------------------------- 模擬藍新付款頁
if ($act === 'mpg') {
    $merchantId = nw_post('MerchantID');
    $tradeInfo = nw_post('TradeInfo');
    $tradeSha = nw_post('TradeSha');
    $ver = nw_post('Version');

    $c = new NewebPayCrypto(NEWEB_HASH_KEY, NEWEB_HASH_IV, NEWEB_PAD_BLOCK);
    $shaOk = $c->verifyTradeSha($tradeInfo, $tradeSha);
    $data = array();
    $err = '';
    try {
        $data = $c->decryptParams($tradeInfo);
    } catch (Exception $e) {
        $err = $e->getMessage();
    }

    nw_page_head('藍新金流 MPG（模擬）', 'Version ' . $ver . ' · MerchantID ' . $merchantId);
    echo '<p>TradeSha 驗證：' . ($shaOk ? '<span class="ok">正確</span>' : '<span class="bad">失敗</span>') . '</p>';
    if ($err !== '') {
        echo '<p class="bad">解密失敗：' . nw_e($err) . '</p>';
        nw_page_foot();
        exit;
    }
    echo '<h2>藍新收到的交易內容</h2><table>';
    foreach ($data as $k => $v) {
        echo '<tr><td class="k">' . nw_e($k) . '</td><td>' . nw_e(is_array($v) ? nw_json_encode($v) : $v) . '</td></tr>';
    }
    echo '</table>';

    $isAtm = (isset($data['VACC']) && $data['VACC'] == '1' && (!isset($data['CREDIT']) || $data['CREDIT'] != '1'));
    echo '<h2>模擬消費者操作</h2>';
    echo '<form method="get">';
    echo '<input type="hidden" name="act" value="settle">';
    echo '<input type="hidden" name="ono" value="' . nw_e($data['MerchantOrderNo']) . '">';
    echo '<input type="hidden" name="mode" value="' . ($isAtm ? 'vacc' : 'credit') . '">';
    echo '<input type="hidden" name="amt" value="' . nw_e($data['Amt']) . '">';
    if ($isAtm) {
        echo '<p class="notice">此訂單啟用了 ATM 虛擬帳號（VACC）。藍新會先產生虛擬帳號並 POST 到 CustomerURL，消費者看到帳號後再轉帳付款。</p>';
        echo '<button class="btn" type="submit" name="step" value="vacc">1. 產生虛擬帳號（回呼 CustomerURL）</button> ';
        echo '<button class="btn ghost" type="submit" name="step" value="vacc_paid">直接取號並完成付款</button>';
    } else {
        echo '<p class="notice">測試卡號：4000-2211-1111-1111，到期日與 CVV 任意。</p>';
        echo '<button class="btn" type="submit" name="step" value="paid">刷卡付款成功</button> ';
        echo '<button class="btn ghost" type="submit" name="step" value="fail">付款失敗</button>';
    }
    echo '</form>';
    nw_page_foot();
    exit;
}

// ---------------------------------------------------------------- 模擬付款完成 / 取號
if ($act === 'settle') {
    $ono = nw_req('ono');
    $step = nw_req('step', 'paid');
    $mode = nw_req('mode', 'credit');
    $amt = nw_req('amt', '0');

    $order = PayGateway::getOrder($ono);
    if (!$order) {
        exit('order not found');
    }
    $c = new NewebPayCrypto(NEWEB_HASH_KEY, NEWEB_HASH_IV, NEWEB_PAD_BLOCK);
    $tradeNo = 'T' . date('YmdHis') . rand(100, 999);

    $done = array();
    $notify = nw_base('pay_notify.php?src=notify');
    $return = nw_base('pay_notify.php?src=return');
    $customer = nw_base('pay_notify.php?src=customer');

    if ($step === 'vacc' || $step === 'vacc_paid') {
        // 取號結果（藍新以明文 POST 通知 CustomerURL）
        $f = array(
            'MerchantID' => NEWEB_HASH_KEY === '' ? '' : NEWEB_MERCHANT_ID,
            'MerchantOrderNo' => $ono,
            'TradeNo' => $tradeNo,
            'PayBankCode' => '004',
            'PayBankName' => '臺灣銀行',
            'PayBranchName' => '臺北分行',
            'PayAccount' => substr($tradeNo . rand(1000, 9999), 0, 16),
            'PayAmount' => $amt,
            'ExpireDate' => date('Ymd', strtotime('+7 day')),
        );
        $r = Http::postForm($customer, $f, 10);
        $done[] = "CustomerURL（取號結果）→ HTTP {$r['code']} {$r['body']}";
        if ($step === 'vacc') {
            // 只取號，消費者尚未轉帳：藍新頁面停留在ATM帳號畫面
            nw_page_head('ATM 虛擬帳號（模擬）', '訂單 ' . $ono);
            echo '<div class="vacc">ATM 虛擬帳號：' . nw_e($f['PayAccount']) . '</div>';
            echo '<p>' . nw_e($f['PayBankName'] . ' ' . $f['PayBranchName'] . '（' . $f['PayBankCode'] . '）')
                . '，金額 NT$' . number_format($f['PayAmount']) . '，繳費期限 ' . nw_e($f['ExpireDate']) . '</p>';
            echo '<form method="get"><input type="hidden" name="act" value="settle">'
                . '<input type="hidden" name="ono" value="' . nw_e($ono) . '">'
                . '<input type="hidden" name="step" value="vacc_paid">'
                . '<input type="hidden" name="mode" value="vacc">'
                . '<input type="hidden" name="amt" value="' . nw_e($f['PayAmount']) . '">'
                . '<button class="btn" type="submit">消費者已完成 ATM 轉帳</button></form>';
            echo '<p><a class="btn ghost" href="' . nw_e(nw_base('pay_result.php') . '?ono=' . urlencode($ono)) . '">查看付款結果頁</a></p>';
            nw_page_foot();
            exit;
        }
    }

    // 付款結果（加密 POST 到 NotifyURL 與 ReturnURL）
    $isFail = ($step === 'fail');
    $result = array(
        'Status' => $isFail ? 'MPG03009' : 'SUCCESS',
        'Message' => $isFail ? '交易失敗' : '付款成功',
        'MerchantID' => NEWEB_MERCHANT_ID,
        'Amt' => (int)$amt,
        'TradeNo' => $tradeNo,
        'MerchantOrderNo' => $ono,
        'PaymentType' => ($mode === 'vacc') ? 'VACC' : 'CREDIT',
        'PayTime' => date('Y-m-d H:i:s'),
        'IP' => $ip,
        'EscrowBank' => '中國信託',
    );
    $ti = $c->encryptParams($result);
    $sha = $c->makeTradeSha($ti);
    $fields = array(
        'Status' => $result['Status'],
        'MerchantID' => NEWEB_MERCHANT_ID,
        'TradeInfo' => $ti,
        'TradeSha' => $sha,
        'Version' => NEWEB_VERSION,
    );

    $r1 = Http::postForm($notify, $fields, 15);
    $done[] = "NotifyURL（幕後通知）→ HTTP {$r1['code']} " . nw_truncate($r1['body'], 60);
    $r2 = Http::postForm($return, $fields, 15);
    $done[] = "ReturnURL（前台返回）→ HTTP {$r2['code']} " . nw_truncate($r2['body'], 60);

    nw_page_head('模擬完成', '訂單 ' . $ono);
    echo '<p>藍新已模擬送出以下通知：</p><ul>';
    foreach ($done as $d) {
        echo '<li>' . nw_e($d) . '</li>';
    }
    echo '</ul>';
    echo '<p><a class="btn" href="' . nw_e(nw_base('pay_result.php') . '?ono=' . urlencode($ono)) . '">查看付款結果頁</a> ';
    echo '<a class="btn ghost" href="' . nw_e(nw_base('pay_query.php') . '?ono=' . urlencode($ono)) . '">訂單明細（後台）</a></p>';
    nw_page_foot();
    exit;
}

// ---------------------------------------------------------------- 模擬單筆查詢 API
if ($act === 'query') {
    header('Content-Type: application/json; charset=utf-8');
    $checkValue = nw_post('CheckValue');
    $ono = nw_post('MerchantOrderNo');
    $amt = nw_post('Amt');
    $expect = NewebPayCrypto::makeCheckValue($amt, NEWEB_MERCHANT_ID, $ono, NEWEB_HASH_KEY, NEWEB_HASH_IV);
    if (!NewebPayCrypto::safeEquals($expect, $checkValue)) {
        echo json_encode(array('Status' => 'TRA10054', 'Message' => '檢查碼錯誤'));
        exit;
    }
    $order = PayGateway::getOrder($ono);
    $map = array('PAID' => '1', 'FAILED' => '2', 'CANCELLED' => '3', 'REFUNDED' => '6');
    $ts = $order ? (isset($map[$order['status']]) ? $map[$order['status']] : '0') : '0';
    $result = array(
        'MerchantID' => NEWEB_MERCHANT_ID,
        'Amt' => (int)$amt,
        'TradeNo' => $order ? $order['neweb_trade_no'] : '',
        'MerchantOrderNo' => $ono,
        'TradeStatus' => $ts,
        'PaymentType' => $order ? $order['payment_type'] : '',
        'CreateTime' => $order ? $order['created_at'] : '',
        'PayTime' => $order ? $order['pay_time'] : '',
        'FundTime' => date('Y-m-d', strtotime('+3 day')),
    );
    $result['CheckCode'] = NewebPayCrypto::makeCheckCode($result, NEWEB_HASH_KEY, NEWEB_HASH_IV);
    echo json_encode(array('Status' => 'SUCCESS', 'Message' => '查詢成功', 'Result' => $result));
    exit;
}

nw_page_head('模擬藍新金流', '');
echo '<p>請由付款流程進入，或直接指定動作：</p><ul>'
    . '<li><code>?act=mpg</code> 付款閘道</li>'
    . '<li><code>?act=settle&amp;ono=訂單編號&amp;step=paid|vacc|fail&amp;mode=credit|vacc&amp;amt=金額</code> 模擬付款完成</li>'
    . '<li><code>?act=query</code> 單筆查詢 API</li></ul>';
nw_page_foot();