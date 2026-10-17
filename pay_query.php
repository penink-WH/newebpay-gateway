<?php
/**
 * pay_query.php - 【第三支頁面】查詢交易狀態與結果
 *
 * - 依訂單編號 / 來源訂單編號 / 藍新交易序號 / 狀態 查詢
 * - 顯示：訂單資料、完整事件紀錄（收到需求→確認→導向→藍新結果→回呼）、
 *         回呼來源站台紀錄（成功與否、HTTP code、耗時）
 * - 可手動「向藍新查詢」與「重送回呼」
 */

require_once(dirname(__FILE__) . '/includes/common.php');
require_once(dirname(__FILE__) . '/includes/layout.php');

nw_require_admin();   // 訂單查詢含敏感資訊，需登入或帶 key

$ip = nw_client_ip();
$msg = '';

/** 動作：查藍新 / 重送回呼 */
$act = nw_req('act', '');
$ono = nw_req('ono', '');
if ($act !== '' && $ono !== '') {
    if ($act === 'query') {
        $r = PayGateway::queryNeweb($ono, $ip);
        $msg = $r['ok'] ? ('藍新查詢成功：' . $r['msg']) : ('藍新查詢失敗：' . $r['msg']);
    } elseif ($act === 'recallback') {
        $ok = PayGateway::sendCallback($ono, '管理頁手動重送', true);
        $msg = $ok ? '已重新回呼來源站台' : '回呼失敗，請查看下方回呼紀錄';
    }
    $back = nw_req('back', 'pay_query.php');
    nw_redirect($back . (strpos($back, '?') === false ? '?' : '&')
        . 'ono=' . urlencode($ono) . '&msg=' . urlencode($msg));
}

$flash = nw_get('msg');
$kw = trim(nw_req('kw', ''));
$status = nw_req('status', '');
$page = (int)nw_req('page', '1');
if ($page < 1) {
    $page = 1;
}
$size = 20;

// ---------------------------------------------------------------- 詳細
if ($ono !== '') {
    $order = PayGateway::getOrder($ono);
    if (!$order) {
        nw_page_head('訂單查詢', '查無此訂單');
        echo '<p class="bad">查無訂單：' . nw_e($ono) . '</p><p><a class="btn ghost sm" href="pay_query.php">回列表</a></p>';
        nw_page_foot();
        exit;
    }

    nw_page_head('訂單明細', '訂單編號 ' . $order['order_no']);
    if ($flash !== '') {
        echo '<div class="notice">' . nw_e($flash) . '</div>';
    }
    $back = 'pay_query.php?ono=' . urlencode($order['order_no']);
    echo '<p>' . nw_status_tag($order['status'])
        . ' <a class="btn ghost sm" href="' . nw_e($back . '&back=' . rawurlencode($back) . '&act=query') . '">向藍新查詢</a> '
        . '<a class="btn ghost sm" href="' . nw_e($back . '&back=' . rawurlencode($back) . '&act=recallback') . '">重送回呼來源站台</a> ';
    echo '<a class="btn ghost sm" href="pay_result.php?ono=' . urlencode($order['order_no']) . '">消費者結果頁</a>';
    echo '<a class="btn ghost sm" href="pay_query.php">回列表</a></p>';
    if (NEWEB_ENV === 'test') {
        echo '<div class="notice"><b>測試環境</b>：可用模擬面板自己產生各種回呼狀態（成功／失敗／取消／取號／重複通知／偽造／金額不符／回呼失敗演練），'
            . '驗證回呼是否有成功送出。<a href="pay_simulate.php?ono=' . urlencode($order['order_no'])
            . '&back=' . rawurlencode('pay_query.php?ono=' . $order['order_no']) . '">開啟模擬面板 →</a></div>';
    }

    echo '<h2>訂單資料</h2><table>';
    $rows = array(
        '訂單編號' => $order['order_no'],
        '來源站台' => $order['source_code'],
        '來源訂單編號' => $order['source_order_no'],
        '金額' => 'NT$' . number_format($order['amount']),
        '付款說明' => $order['item_desc'],
        '付款方式（要求）' => nw_paytype_text($order['pay_type']),
        '藍新回傳付款方式' => nw_payment_type_text($order['payment_type']),
        '狀態訊息' => $order['status_msg'],
        '藍新交易序號' => $order['neweb_trade_no'],
        '付款時間' => $order['pay_time'],
        'ATM 銀行 / 分行' => trim($order['bank_name'] . ' ' . $order['branch_name']),
        'ATM 銀行代號' => $order['bank_code'],
        'ATM 虛擬帳號' => $order['virtual_account'],
        '繳費期限' => $order['expire_date'],
        '回呼網址' => $order['callback_url'],
        '回呼狀態' => $order['callback_state'] . '（嘗試 ' . (int)$order['callback_tries'] . ' 次）',
        '最後成功回呼時間' => $order['callback_at'],
        '來源 IP' => $order['request_ip'],
        '建立時間' => $order['created_at'],
        '更新時間' => $order['updated_at'],
    );
    foreach ($rows as $k => $v) {
        echo '<tr><td class="k">' . nw_e($k) . '</td><td>' . ($v === '' ? '<span class="muted">-</span>' : nw_e($v)) . '</td></tr>';
    }
    echo '</table>';

    echo '<h2>事件紀錄（收到需求 / 確認 / 導向 / 結果 / 回呼）</h2><div class="timeline">';
    $logs = PayGateway::logs($order['order_no'], 200);
    if (count($logs) === 0) {
        echo '<div class="muted">尚無紀錄</div>';
    }
    foreach ($logs as $l) {
        $cls = $l['result'] === 'OK' ? 'ok' : ($l['result'] === 'FAIL' ? 'bad' : 'warn');
        echo '<div><b>' . nw_e($l['created_at']) . '</b> <span class="' . $cls . '">[' . nw_e($l['stage'] . '/' . $l['action']) . ']</span> '
            . nw_e($l['message']);
        if ($l['remote_ip'] !== '') {
            echo ' <span class="muted">from ' . nw_e($l['remote_ip']) . '</span>';
        }
        if ($l['detail'] !== '' && $l['detail'] !== null) {
            echo '<details><summary class="muted">詳細內容</summary><pre>' . nw_e($l['detail']) . '</pre></details>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '<h2>回呼來源站台紀錄</h2>';
    $cbs = PayGateway::callbacks($order['order_no']);
    echo '<table><tr><th>時間</th><th>網址</th><th>第幾次</th><th>HTTP</th><th>耗時</th><th>結果</th><th>回應</th></tr>';
    if (count($cbs) === 0) {
        echo '<tr><td colspan="7" class="muted">尚未回呼</td></tr>';
    }
    foreach ($cbs as $c) {
        echo '<tr><td>' . nw_e($c['created_at']) . '</td><td style="word-break:break-all">' . nw_e($c['url']) . '</td>'
            . '<td>' . (int)$c['attempt'] . '</td><td>' . nw_e($c['response_code']) . '</td><td>' . (int)$c['cost_ms'] . ' ms</td>'
            . '<td class="' . ($c['is_success'] ? 'ok' : 'bad') . '">' . ($c['is_success'] ? '成功' : '失敗') . '</td>'
            . '<td>' . nw_e(nw_truncate($c['response_body'], 60)) . '</td></tr>';
    }
    echo '</table>';

    nw_page_foot();
    exit;
}

// ---------------------------------------------------------------- 列表
$where = array('1=1');
if ($kw !== '') {
    $like = '%' . DB::esc($kw) . '%';
    $where[] = "(`order_no` LIKE '" . $like . "' OR `source_order_no` LIKE '" . $like
        . "' OR `neweb_trade_no` LIKE '" . $like . "' OR `source_code` LIKE '" . $like . "')";
}
if ($status !== '') {
    $where[] = "`status` = '" . DB::esc($status) . "'";
}
$whereSql = implode(' AND ', $where);

$total = (int)DB::queryValue("SELECT COUNT(*) FROM `pay_orders` WHERE " . $whereSql, 0);
$offset = ($page - 1) * $size;
$list = DB::queryAll("SELECT * FROM `pay_orders` WHERE " . $whereSql . " ORDER BY `id` DESC LIMIT " . $size . " OFFSET " . $offset);
$pages = max(1, (int)ceil($total / $size));

nw_page_head('交易查詢', '共 ' . $total . ' 筆訂單');
if ($flash !== '') {
    echo '<div class="notice">' . nw_e($flash) . '</div>';
}

echo '<p><a class="btn ghost sm" href="pay_sources_admin.php">來源站台設定（子公司網站）</a></p>';
echo '<form method="get" class="card" style="background:#fafbfc">';
echo '<input type="hidden" name="kw" value="' . nw_e($kw) . '">';
echo '<input type="hidden" name="status" value="' . nw_e($status) . '">';
echo '<span style="display:inline-block;margin-right:8px">狀態</span><select name="status" onchange="this.form.submit()">';
echo '<option value="">全部</option>';
foreach (array('CREATED', 'CONFIRMED', 'DISPATCHED', 'VACC_ISSUED', 'PAID', 'FAILED', 'CANCELLED', 'REFUNDED') as $s) {
    echo '<option value="' . $s . '"' . ($status === $s ? ' selected' : '') . '>' . nw_e(nw_status_text($s)) . '（' . $s . '）</option>';
}
echo '</select> ';
echo '<input type="text" name="kw" placeholder="訂單編號 / 來源訂單編號 / 藍新交易序號" value="' . nw_e($kw) . '" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px"> ';
echo '<button class="btn sm" type="submit">查詢</button></form>';

echo '<table><tr><th>訂單編號</th><th>來源站台</th><th>來源訂單</th><th>金額</th><th>付款方式</th><th>狀態</th><th>回呼</th><th>建立時間</th></tr>';
if (count($list) === 0) {
    echo '<tr><td colspan="8" class="muted">查無資料</td></tr>';
}
foreach ($list as $o) {
    $cbCls = $o['callback_state'] === 'SENT' ? 'ok' : ($o['callback_state'] === 'FAILED' ? 'bad' : 'warn');
    echo '<tr><td><a href="pay_query.php?ono=' . urlencode($o['order_no']) . '"><code>' . nw_e($o['order_no']) . '</code></a></td>'
        . '<td>' . nw_e($o['source_code']) . '</td><td>' . nw_e($o['source_order_no']) . '</td>'
        . '<td>' . number_format($o['amount']) . '</td>'
        . '<td>' . nw_e($o['payment_type'] !== '' ? nw_payment_type_text($o['payment_type']) : nw_paytype_text($o['pay_type'])) . '</td>'
        . '<td>' . nw_status_tag($o['status']) . '</td>'
        . '<td class="' . $cbCls . '">' . nw_e($o['callback_state']) . '</td>'
        . '<td>' . nw_e($o['created_at']) . '</td></tr>';
}
echo '</table>';

if ($pages > 1) {
    echo '<p>';
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === $page) {
            echo '<b>' . $i . '</b> ';
        } else {
            echo '<a href="pay_query.php?kw=' . urlencode($kw) . '&status=' . urlencode($status) . '&page=' . $i . '">' . $i . '</a> ';
        }
    }
    echo '</p>';
}

nw_page_foot();