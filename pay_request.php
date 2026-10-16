<?php
/**
 * pay_request.php - 【第一支頁面】驅動付款
 *
 * 1) 來源站台（公司其他網站）以 POST 轉送付款需求進來 → 建立訂單並記錄 MySQL
 *    參數：source, source_order_no, amount, item_desc, buyer_name, buyer_email,
 *          buyer_phone, pay_type(credit|atm|both), callback_url, return_url,
 *          timestamp, mode(redirect|json), sign
 * 2) 顯示付款資訊頁面，消費者按「確認付款」
 * 3) 依藍新規範以瀏覽器 POST 表單導向藍新 MPG 付款閘道（線上付款 / ATM 虛擬帳號）
 *    每一步（收到需求、確認、導向）都寫入 pay_logs
 */

require_once(dirname(__FILE__) . '/includes/common.php');
require_once(dirname(__FILE__) . '/includes/layout.php');

$ip = nw_client_ip();
// POST 進來（來源站台轉送付款需求）預設視為接收需求；GET 進來則顯示說明頁
$defaultStep = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') ? 'receive' : 'home';
$step = nw_req('step', $defaultStep);

/** 取得訂單的確認權杖 */
function req_token($order, $source)
{
    $secret = $source && $source['secret'] !== '' ? $source['secret'] : NEWEB_HASH_KEY;
    return nw_hmac_sha256($secret, $order['order_no'] . '|' . $order['amount']);
}

/** 檢查藍新付款方式代碼是否為訂單允許的 */
function allowed_pay_types($order)
{
    if ($order['pay_type'] === 'credit') {
        return array('credit');
    }
    if ($order['pay_type'] === 'atm') {
        return array('atm');
    }
    return array('credit', 'atm');
}

function home_page()
{
    nw_page_head('藍新金流付款閘道', '入口說明');
    echo '<h2>這支頁面怎麼用</h2>';
    echo '<div class="notice">來源站台以 <code>POST</code> 轉送付款需求到 <code>pay_request.php</code>，'
        . '系統建立訂單後會導到付款資訊確認頁；消費者確認後即依藍新規範導向藍新付款閘道。</div>';
    echo '<h2>准入管制（不通過一律回 403）</h2>';
    echo '<table>'
        . '<tr><th>階段</th><th>檢查項目</th><th>說明</th></tr>'
        . '<tr><td>第一階段</td><td>用戶代碼 → 允許連入的 IP → 接受的來源網站</td>'
        . '<td>先擋掉未註冊或非授權來源的請求，未通過不做簽章運算</td></tr>'
        . '<tr><td>第二階段</td><td>sign 簽章比對 → timestamp 時效</td>'
        . '<td>第一階段通過才執行；金鑰由雙方私下共用</td></tr>'
        . '</table>';
    echo '<p class="muted">各站台的清單與預設回呼網址，請由管理員在 '
        . '<a href="pay_sources_admin.php">來源站台設定</a> 維護。</p>';
    echo '<table>'
        . '<tr><th>參數</th><th>必填</th><th>說明</th></tr>'
        . '<tr><td>source</td><td>是</td><td>來源站台代號（需先登錄於 pay_sources 或 config.php）</td></tr>'
        . '<tr><td>source_order_no</td><td>是</td><td>來源站台訂單編號（同站台不可重複）</td></tr>'
        . '<tr><td>amount</td><td>是</td><td>金額（整數元）</td></tr>'
        . '<tr><td>item_desc</td><td>否</td><td>商品說明（50 字內）</td></tr>'
        . '<tr><td>buyer_name / buyer_email / buyer_phone</td><td>否</td><td>消費者資訊</td></tr>'
        . '<tr><td>pay_type</td><td>否</td><td>credit（線上付款）/ atm（ATM 虛擬帳號）/ both（預設）</td></tr>'
        . '<tr><td>callback_url</td><td>否</td><td>付款結果回呼來源站台網址 NOTIFY（需在允許網域清單）</td></tr>'
        . '<tr><td>result_url</td><td>否</td><td>付款完成後導回來源站頁面 RESULT（附加訂單資訊與 sign）</td></tr>'
        . '<tr><td>return_url</td><td>否</td><td>付款完成後導回來源站頁面</td></tr>'
        . '<tr><td>mode</td><td>否</td><td>redirect（預設，302 導到確認頁）/ json（回 JSON）</td></tr>'
        . '<tr><td>timestamp</td><td>是</td><td>Unix 時間戳（±300 秒）</td></tr>'
        . '<tr><td>sign</td><td>是</td><td>HMAC-SHA256(secret, 參數依 key 排序 k=v 以 & 連接)</td></tr>'
        . '</table>';
    echo '<p><a class="btn ghost sm" href="pay_query.php">訂單查詢</a> '
        . '<a class="btn ghost sm" href="demo/source_site.php">模擬來源站台</a></p>';
    nw_page_foot();
}

// ---------------------------------------------------------------- 步驟 1：接收轉送的付款需求
// 兩階段准入：先查來源站台與連線來源（IP / 來源網站），通過後才比對 sign，
// 任一階段不通過一律回 HTTP 403，並留下 ACCESS 階段的拒絕紀錄。
if ($step === 'receive') {
    $post = $_POST;
    $mode = isset($post['mode']) ? trim($post['mode']) : '';
    $sourceCode = isset($post['source']) ? trim($post['source']) : '';
    $siteHost = PayGateway::requestSiteHost();

    // ---- 第一階段：用戶代碼 + 允許連入的 IP + 接受的來源端網站
    $gate = PayGateway::accessStage1($sourceCode, $ip);
    if (!$gate['ok']) {
        PayGateway::log('', 'ACCESS', 'STAGE1_DENY', 'FAIL', $gate['reason'], array(
            'source' => $sourceCode,
            'remote_addr' => $ip,
            'origin' => isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '',
            'referer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '',
            'posted_keys' => array_keys($post),
        ), $ip);
        nw_deny(403, '未授權的來源端（第一階段）', $gate['reason'], $mode);
    }
    $source = $gate['source'];

    // ---- 第二階段：簽章比對（僅第一階段通過才執行）
    $sign = isset($post['sign']) ? trim($post['sign']) : '';
    if (!PayGateway::accessStage2($source, $post, $sign)) {
        PayGateway::log('', 'ACCESS', 'STAGE2_BAD_SIGN', 'FAIL',
            '來源站台 ' . $sourceCode . ' 簽章驗證失敗', array(
                'remote_addr' => $ip,
                'source_site' => $siteHost,
                'posted' => PayGateway::maskPost($post),
            ), $ip);
        nw_deny(403, '未授權的請求（第二階段）', 'sign 驗證失敗，請求已拒絕', $mode);
    }
    PayGateway::log('', 'ACCESS', 'STAGE_OK', 'OK',
        '來源站台 ' . $sourceCode . ' 通過兩階段准入（IP ' . $ip . ($siteHost === '' ? '' : ' / 來源網站 ' . $siteHost) . '）',
        array('stage1_ip' => $ip, 'stage1_site' => $siteHost), $ip);

    // ---- 重放防護：時間戳過期
    $ts = isset($post['timestamp']) ? trim($post['timestamp']) : '';
    if ($ts === '' || abs(time() - (int)$ts) > 300) {
        PayGateway::log('', 'ACCESS', 'BAD_TIMESTAMP', 'FAIL',
            'timestamp 過期或未帶：' . $ts, $post, $ip);
        nw_deny(403, '請求逾時', 'timestamp 過期或未帶，請重新發起付款', $mode);
    }

    $created = PayGateway::createOrder($source, array(
        'source_order_no' => isset($post['source_order_no']) ? trim($post['source_order_no']) : '',
        'amount' => isset($post['amount']) ? trim($post['amount']) : '',
        'item_desc' => isset($post['item_desc']) ? trim($post['item_desc']) : '',
        'buyer_name' => isset($post['buyer_name']) ? trim($post['buyer_name']) : '',
        'buyer_email' => isset($post['buyer_email']) ? trim($post['buyer_email']) : '',
        'buyer_phone' => isset($post['buyer_phone']) ? trim($post['buyer_phone']) : '',
        'pay_type' => isset($post['pay_type']) ? trim($post['pay_type']) : 'both',
        'callback_url' => isset($post['callback_url']) ? trim($post['callback_url']) : '',
        'result_url' => isset($post['result_url']) ? trim($post['result_url']) : '',
    ), $ip);

    if (!$created['ok']) {
        // 重複轉送同一來源訂單編號：以 409 回應，附既有訂單編號（不重複建單、不出貨）
        if ($created['order_no'] !== '') {
            header('HTTP/1.1 409 Conflict');
            header('Status: 409 Conflict');
            if ($mode === 'json') {
                nw_json_out(array('ok' => false, 'code' => 409, 'msg' => $created['msg'], 'order_no' => $created['order_no']));
            }
            nw_page_head('訂單已存在', '');
            echo '<p>' . nw_e($created['msg']) . '</p>';
            echo '<p><a class="btn ghost" href="' . nw_e(nw_base('pay_result.php') . '?ono=' . urlencode($created['order_no'])) . '">查看訂單狀態</a></p>';
            nw_page_foot();
            exit;
        }
        PayGateway::log('', 'RECEIVE', 'CREATE_FAIL', 'FAIL', $created['msg'], $post, $ip);
        nw_deny(422, '付款需求資料有誤', $created['msg'], $mode);
    }

    $confirmUrl = nw_base('pay_request.php') . '?step=confirm&ono=' . urlencode($created['order_no'])
        . '&t=' . urlencode(req_token($created['order'], $source));

    if ($mode === 'json') {
        nw_json_out(array(
            'ok' => true,
            'msg' => '訂單已建立',
            'order_no' => $created['order_no'],
            'amount' => (int)$created['order']['amount'],
            'pay_url' => $confirmUrl,
        ));
    }
    nw_redirect($confirmUrl);
}
// ---------------------------------------------------------------- 步驟 2：付款資訊確認
if ($step === 'confirm') {
    $orderNo = nw_get('ono');
    $order = PayGateway::getOrder($orderNo);
    if (!$order) {
        nw_page_head('訂單不存在', '');
        echo '<p class="bad">查無此訂單：' . nw_e($orderNo) . '</p>';
        nw_page_foot();
        exit;
    }
    $source = PayGateway::getSource($order['source_code']);
    // 權杖一律由伺服器端重算，不信任網址帶入的值；送出時再比對一次
    $token = req_token($order, $source);

    if (in_array($order['status'], array('PAID', 'CANCELLED', 'REFUNDED'), true)) {
        nw_page_head('訂單狀態', '訂單編號 ' . $orderNo);
        echo '<p>' . nw_status_tag($order['status']) . '</p>';
        echo '<p><a class="btn" href="' . nw_e(nw_base('pay_result.php') . '?ono=' . urlencode($orderNo)) . '">查看付款結果</a></p>';
        nw_page_foot();
        exit;
    }

    nw_page_head('付款資訊確認', '請確認下列資訊後進行付款');
    echo '<table>';
    echo '<tr><td class="k">訂單編號</td><td><code>' . nw_e($order['order_no']) . '</code></td></tr>';
    if ($order['source_order_no'] !== '') {
        echo '<tr><td class="k">來源訂單編號</td><td>' . nw_e($order['source_order_no']) . '</td></tr>';
    }
    echo '<tr><td class="k">付款說明</td><td>' . nw_e($order['item_desc']) . '</td></tr>';
    echo '<tr><td class="k">金額</td><td><span class="amount">NT$' . number_format($order['amount']) . '</span></td></tr>';
    if ($order['buyer_email'] !== '') {
        echo '<tr><td class="k">電子郵件</td><td>' . nw_e($order['buyer_email']) . '</td></tr>';
    }
    echo '<tr><td class="k">目前狀態</td><td>' . nw_status_tag($order['status']) . '</td></tr>';
    echo '</table>';

    echo '<form method="post" action="pay_request.php?step=dispatch">';
    echo '<input type="hidden" name="ono" value="' . nw_e($orderNo) . '">';
    echo '<input type="hidden" name="t" value="' . nw_e($token) . '">';
    echo '<h2 style="margin-top:18px">付款方式</h2>';
    $types = allowed_pay_types($order);
    if (count($types) === 1) {
        echo '<input type="hidden" name="pay_type" value="' . nw_e($types[0]) . '">';
        echo '<p>' . nw_e(nw_paytype_text($types[0])) . '</p>';
    } else {
        echo '<label style="display:block"><input type="radio" name="pay_type" value="credit" checked> 線上付款（信用卡）</label>';
        echo '<label style="display:block"><input type="radio" name="pay_type" value="atm"> ATM 虛擬帳號（取號後以ATM轉帳）</label>';
    }
    echo '<p style="margin-top:18px"><button class="btn" type="submit">確認付款</button> ';
    echo '<a class="btn ghost" href="pay_query.php?ono=' . urlencode($orderNo) . '">訂單查詢</a></p>';
    echo '</form>';
    nw_page_foot();
    exit;
}

// ---------------------------------------------------------------- 步驟 3：導向藍新付款閘道
if ($step === 'dispatch') {
    $orderNo = nw_post('ono');
    $order = PayGateway::getOrder($orderNo);
    $source = $order ? PayGateway::getSource($order['source_code']) : false;
    $expect = $order ? req_token($order, $source) : '';
    if (!$order || !NewebPayCrypto::safeEquals($expect, nw_post('t'))) {
        PayGateway::log($orderNo, 'DISPATCH', 'BAD_TOKEN', 'FAIL', '確認權杖驗證失敗', $_POST, $ip);
        exit('FAIL: token error');
    }

    $payType = nw_post('pay_type', $order['pay_type']);
    if (!in_array($payType, allowed_pay_types($order), true)) {
        PayGateway::log($orderNo, 'DISPATCH', 'BAD_PAYTYPE', 'FAIL', '不允許的付款方式：' . $payType, $_POST, $ip);
        exit('FAIL: pay type error');
    }

    // 消費者按下「確認付款」→ 記錄確認動作
    PayGateway::updateOrder($orderNo, array(
        'status' => 'CONFIRMED',
        'status_msg' => '消費者已確認付款（' . nw_paytype_text($payType) . '）',
    ));
    PayGateway::log($orderNo, 'CONFIRM', 'USER_CONFIRM', 'OK',
        '消費者確認付款，選擇付款方式：' . nw_paytype_text($payType), array('pay_type' => $payType), $ip);

    $mpg = PayGateway::buildMpg($order, $payType);
    PayGateway::updateOrder($orderNo, array(
        'status' => 'DISPATCHED',
        'status_msg' => '消費者已確認付款，已導向藍新（' . nw_paytype_text($payType) . '）',
    ));
    PayGateway::log($orderNo, 'DISPATCH', 'TO_NEWEB', 'OK',
        '導向藍新 MPG：' . nw_paytype_text($payType) . ' → ' . $mpg['action'],
        array('pay_type' => $payType, 'trade_info_params' => $mpg['trade_info_params'], 'TradeSha' => $mpg['fields']['TradeSha']), $ip);

    // 藍新規範：必須由瀏覽器 POST 表單導向，不可背景 request
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="zh-Hant"><head><meta charset="utf-8"><title>前往藍新付款</title>
<style>body{font:15px/1.7 -apple-system,"PingFang TC",sans-serif;margin:60px auto;max-width:520px;color:#22262e}
button{background:#1a56db;color:#fff;border:0;border-radius:8px;padding:12px 22px;font-size:16px;cursor:pointer}</style>
</head><body>
<h3>即將前往藍新金流付款頁…</h3>
<p>若未自動跳轉，請按下方按鈕繼續（藍新規範需以 POST 表單送出）。</p>
<form id="np" method="post" action="<?php echo nw_e($mpg['action']); ?>">
<?php foreach ($mpg['fields'] as $k => $v) { ?>
  <input type="hidden" name="<?php echo nw_e($k); ?>" value="<?php echo nw_e($v); ?>">
<?php } ?>
<button type="submit">前往付款</button>
</form>
<script>document.getElementById('np').submit();</script>
</body></html>
<?php
    exit;
}

home_page();