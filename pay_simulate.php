<?php
/**
 * pay_simulate.php - 交易查詢頁的「自我測試 / 模擬各種回呼狀態」工具
 *
 * 給沒有藍新測試環境（或不想真的送出交易）時，用來驗證整條回呼鏈路：
 * 收款 → 藍新結果 → 更新訂單 → 回呼來源站台 → 紀錄成功與否。
 *
 * 安全限制：
 *   1. 需要管理密碼登入
 *   2. 只在測試環境（config.php 的 NEWEB_ENV = 'test'）可用，live 環境一律拒絕
 *
 * 所有動作都寫入 pay_logs，結果會回到交易查詢頁的時間軸與回呼紀錄表。
 */

require_once(dirname(__FILE__) . '/includes/common.php');
nw_go_live_guard();
require_once(dirname(__FILE__) . '/includes/layout.php');

nw_require_admin();

$ip = nw_client_ip();
$act = nw_req('act', '');
$ono = nw_req('ono', '');
$back = nw_req('back', 'pay_query.php?ono=' . rawurlencode($ono));

// 正式（live）環境禁止一切模擬
if (NEWEB_ENV !== 'test') {
    header('HTTP/1.1 403 Forbidden');
    header('Status: 403 Forbidden');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'FAIL: 模擬功能僅限測試環境（config.php 的 NEWEB_ENV=test）可用';
    exit;
}

/** 回到查詢頁並帶上訊息 */
function back_to($back, $ono, $msg)
{
    $sep = (strpos($back, '?') === false) ? '?' : '&';
    nw_redirect($back . $sep . 'ono=' . urlencode($ono) . '&msg=' . urlencode($msg));
}

/** 組出「藍新會送來的」加密結果 */
function make_result($order, $status, $message, $tradeNo, $paymentType, $amtOverride)
{
    $c = new NewebPayCrypto(NEWEB_HASH_KEY, NEWEB_HASH_IV, NEWEB_PAD_BLOCK);
    $result = array(
        'Status' => $status,
        'Message' => $message,
        'MerchantID' => NEWEB_MERCHANT_ID,
        'Amt' => ($amtOverride === null) ? (int)$order['amount'] : (int)$amtOverride,
        'TradeNo' => $tradeNo,
        'MerchantOrderNo' => $order['order_no'],
        'PaymentType' => $paymentType,
        'PayTime' => date('Y-m-d H:i:s'),
        'IP' => nw_client_ip(),
        'EscrowBank' => '中國信託',
    );
    $ti = $c->encryptParams($result);
    return array(
        'Status' => $result['Status'],
        'MerchantID' => NEWEB_MERCHANT_ID,
        'TradeInfo' => $ti,
        'TradeSha' => $c->makeTradeSha($ti),
        'Version' => NEWEB_VERSION,
    );
}

$order = PayGateway::getOrder($ono);
if (!$order) {
    back_to('pay_query.php', $ono, '找不到訂單 ' . $ono);
}

$tradeNo = 'SIM' . date('ymdHis') . rand(10, 99);
$notifyUrl = nw_base('pay_notify.php?src=notify');
$returnUrl = nw_base('pay_notify.php?src=return');
$customerUrl = nw_base('pay_notify.php?src=customer');

// ---------------------------------------------------------------- 動作
switch ($act) {

    // 1. 模擬藍新回傳付款成功
    case 'paid':
        $f = make_result($order, 'SUCCESS', '付款成功（模擬）', $tradeNo, 'CREDIT', null);
        $r = Http::postForm($notifyUrl, $f, 15);
        $o2 = PayGateway::getOrder($ono);
        $msg = sprintf('模擬付款成功：HTTP %s，訂單狀態 %s，回呼 %s',
            $r['code'], $o2['status'], $o2['callback_state']);
        back_to($back, $ono, $msg);

    // 2. 模擬付款失敗
    case 'failed':
        $f = make_result($order, 'MPG03009', '交易失敗（模擬）', $tradeNo, 'CREDIT', null);
        $r = Http::postForm($notifyUrl, $f, 15);
        $o2 = PayGateway::getOrder($ono);
        back_to($back, $ono, sprintf('模擬付款失敗：HTTP %s，訂單狀態 %s，回呼 %s',
            $r['code'], $o2['status'], $o2['callback_state']));

    // 3. 模擬取消付款
    case 'cancelled':
        $f = make_result($order, 'MPG03011', '使用者取消付款（模擬）', $tradeNo, 'CREDIT', null);
        Http::postForm($notifyUrl, $f, 15);
        $o2 = PayGateway::getOrder($ono);
        back_to($back, $ono, '模擬取消付款：訂單狀態 ' . $o2['status']);

    // 4. 重複通知（驗證不會重複回呼）
    case 'duplicate':
        $f = make_result($order, 'SUCCESS', '付款成功（模擬，重複通知）', $order['neweb_trade_no'] !== '' ? $order['neweb_trade_no'] : $tradeNo, 'CREDIT', null);
        $before = count(PayGateway::callbacks($ono));
        Http::postForm($notifyUrl, $f, 15);
        Http::postForm($notifyUrl, $f, 15);
        $after = count(PayGateway::callbacks($ono));
        back_to($back, $ono, "模擬重複通知：回呼紀錄 {$before} → {$after} 筆（相同狀態不應增加）");

    // 5. 偽造 TradeSha（應被拒絕）
    case 'forged':
        $f = make_result($order, 'SUCCESS', '偽造通知', $tradeNo, 'CREDIT', null);
        $f['TradeSha'] = str_repeat('A', 64);
        $r = Http::postForm($notifyUrl, $f, 15);
        $o2 = PayGateway::getOrder($ono);
        back_to($back, $ono, sprintf('偽造 TradeSha：回應「%s」，訂單狀態仍為 %s（應維持原狀）',
            nw_truncate($r['body'], 40), $o2['status']));

    // 6. 金額不符（應被拒絕）
    case 'amtmismatch':
        $f = make_result($order, 'SUCCESS', '金額不符（模擬）', $tradeNo, 'CREDIT', 1);
        $r = Http::postForm($notifyUrl, $f, 15);
        $o2 = PayGateway::getOrder($ono);
        back_to($back, $ono, sprintf('金額不符：回應「%s」，訂單狀態仍為 %s（不應被竄改）',
            nw_truncate($r['body'], 40), $o2['status']));

    // 7. 模擬 ATM 取號（CustomerURL）
    case 'vac':
        $f = array(
            'MerchantID' => NEWEB_MERCHANT_ID,
            'MerchantOrderNo' => $ono,
            'TradeNo' => $tradeNo,
            'PayBankCode' => '004',
            'PayBankName' => '臺灣銀行',
            'PayBranchName' => '臺北分行',
            'PayAccount' => 'SIM' . date('ymdHis') . rand(100, 999),
            'PayAmount' => (int)$order['amount'],
            'ExpireDate' => date('Ymd', strtotime('+7 day')),
        );
        $r = Http::postForm($customerUrl, $f, 15);
        $o2 = PayGateway::getOrder($ono);
        back_to($back, $ono, sprintf('模擬 ATM 取號：HTTP %s，虛擬帳號 %s，狀態 %s',
            $r['code'], $o2['virtual_account'] !== '' ? $o2['virtual_account'] : '(無)', $o2['status']));

    // 8. 來源站台連線失敗（回呼重試後應記 FAILED）
    case 'cbfail':
        $orig = $order['callback_url'];
        $bad = 'http://127.0.0.1:9/dead';       // 該埠通常無服務 → 連線被拒
        PayGateway::updateOrder($ono, array('callback_url' => $bad, 'callback_state' => 'PENDING'));
        PayGateway::log($ono, 'SYSTEM', 'SIMULATE', 'WARN',
            '模擬情境：回呼網址暫時改為拒絕連線的位址（原：' . $orig . '）', null, $ip);
        $f = make_result($order, 'SUCCESS', '回呼失敗演練（模擬）', $tradeNo, 'CREDIT', null);
        Http::postForm($notifyUrl, $f, 30);
        PayGateway::updateOrder($ono, array('callback_url' => $orig));
        $o2 = PayGateway::getOrder($ono);
        $tries = count(PayGateway::callbacks($ono));
        back_to($back, $ono, sprintf('回呼失敗演練：狀態 %s，回呼 %s，共送出 %d 筆嘗試（已在 pay_callbacks 逐次記錄），回呼網址已還原',
            $o2['status'], $o2['callback_state'], $tries));

    // 9. 來源站台回應非 OK（需 demo/callback_receiver.php 且簽章對不上）
    case 'cbnoack':
        if (!file_exists(dirname(__FILE__) . '/demo/callback_receiver.php')) {
            back_to($back, $ono, '此情境需要 demo/callback_receiver.php，正式機已排除 demo/，請改用「回呼連線失敗」演練');
        }
        $orig = $order['callback_url'];
        $src = PayGateway::getSource($order['source_code']);
        $origSecret = $src ? $src['secret'] : '';
        $bad = nw_base('demo/callback_receiver.php?simulate=noack');
        // 暫時換掉簽章金鑰 → 模擬來源站台驗簽不過、回應「非 OK」
        if ($src) {
            DB::update('pay_sources', array('secret' => 'SIMULATE-WRONG-KEY', 'updated_at' => nw_now()),
                array('source_code' => $order['source_code']));
        }
        PayGateway::updateOrder($ono, array('callback_url' => $bad, 'callback_state' => 'PENDING'));
        PayGateway::log($ono, 'SYSTEM', 'SIMULATE', 'WARN',
            '模擬情境：來源站台會回應「非 OK」（驗證回應內容檢查）', null, $ip);
        $f = make_result($order, 'SUCCESS', '回應內容演練（模擬）', $tradeNo, 'CREDIT', null);
        Http::postForm($notifyUrl, $f, 30);
        PayGateway::updateOrder($ono, array('callback_url' => $orig));
        if ($src) {
            DB::update('pay_sources', array('secret' => $origSecret, 'updated_at' => nw_now()),
                array('source_code' => $order['source_code']));
        }
        $o2 = PayGateway::getOrder($ono);
        back_to($back, $ono, '回應非 OK 演練：回呼狀態 ' . $o2['callback_state']
            . '（應為 FAILED，因回應未包含 ' . CALLBACK_ACK . '）；金鑰與回呼網址均已還原');

    // 10. 手動重送回呼
    case 'recallback':
        $ok = PayGateway::sendCallback($ono, '模擬面板手動重送', true);
        back_to($back, $ono, $ok ? '已手動重送回呼，成功' : '手動重送回呼失敗，請看下方回呼紀錄');

    // 11. 向藍新查詢
    case 'queryneweb':
        $r = PayGateway::queryNeweb($ono, $ip);
        back_to($back, $ono, $r['ok'] ? ('向藍新查詢成功：' . $r['msg']) : ('向藍新查詢失敗：' . $r['msg']));

    // 12. 重設為未付款（方便重跑一次完整流程）
    case 'reset':
        PayGateway::updateOrder($ono, array(
            'status' => 'DISPATCHED',
            'status_msg' => '已由模擬面板重設',
            'neweb_trade_no' => '',
            'payment_type' => '',
            'pay_time' => '',
            'virtual_account' => '',
            'bank_code' => '',
            'bank_name' => '',
            'branch_name' => '',
            'expire_date' => '',
            'callback_state' => 'PENDING',
            'cb_sent_status' => '',
        ));
        PayGateway::log($ono, 'SYSTEM', 'SIMULATE_RESET', 'WARN', '模擬面板：訂單已重設為「已導向藍新」，可重新演練各種回呼情境', null, $ip);
        back_to($back, $ono, '訂單已重設，可重新演練各種回呼狀態');
}

// ---------------------------------------------------------------- 面板
nw_page_head('模擬各種回呼狀態', '訂單 ' . $order['order_no']);
echo '<div class="notice"><b>測試環境專用</b>：這裡送出的是「藍新格式」的測試資料，不會真的收到錢。
訂單金額 ' . number_format($order['amount']) . ' 元、目前狀態 ' . nw_status_tag($order['status']) . '</div>';

/** 產生一個按鈕（不用 sprintf：URL 編碼後含 % 會被誤判為格式符號） */
function sim_btn($act, $label, $ono, $back)
{
    return '<a class="btn ghost sm" href="pay_simulate.php?act=' . urlencode($act)
        . '&ono=' . urlencode($ono) . '&back=' . rawurlencode($back) . '">'
        . nw_e($label) . '</a> ';
}

echo '<h2>正常情境（應收到回呼並回呼成功）</h2><p>';
echo sim_btn('paid', '① 藍新回傳付款成功', $order['order_no'], $back);
echo sim_btn('vac', '② ATM 取號（虛擬帳號）', $order['order_no'], $back);
echo sim_btn('recallback', '③ 手動重送回呼', $order['order_no'], $back);
echo sim_btn('queryneweb', '④ 向藍新查詢', $order['order_no'], $back);
echo '</p>';

echo '<h2>異常與防重複情境</h2><p>';
echo sim_btn('failed', '⑤ 藍新回傳付款失敗', $order['order_no'], $back);
echo sim_btn('cancelled', '⑥ 藍新回傳取消', $order['order_no'], $back);
echo sim_btn('duplicate', '⑦ 重複通知（不應重複回呼）', $order['order_no'], $back);
echo sim_btn('forged', '⑧ 偽造 TradeSha（應被拒）', $order['order_no'], $back);
echo sim_btn('amtmismatch', '⑨ 金額不符（應被拒）', $order['order_no'], $back);
echo '</p>';

echo '<h2>回呼失敗演練（驗證重試與紀錄）</h2><p>';
echo sim_btn('cbfail', '⑩ 來源站台連線失敗（重試後 FAILED）', $order['order_no'], $back);
echo sim_btn('cbnoack', '⑪ 來源站台回應非 OK（需 demo/；會暫時換金鑰，因此接收端日誌會出現一次「簽章錯誤」，屬預期）', $order['order_no'], $back);
echo '</p>';

echo '<h2>重設</h2><p>';
echo sim_btn('reset', '⑫ 重設訂單為「已導向藍新」', $order['order_no'], $back);
echo '</p>';

echo '<p class="muted">每一個動作都會寫入 pay_logs，執行完請看 '
    . '<a href="' . nw_e($back) . '">交易明細的時間軸</a>與回呼紀錄表。</p>';
echo '<p><a class="btn ghost" href="' . nw_e($back) . '">回到交易明細</a></p>';
nw_page_foot();