<?php
/**
 * pay_sources_admin.php - 來源站台（子公司網站）基本資料設定
 *
 * 閘道端要管的四件事都在這裡設定：
 *   1. 用戶代碼（source_code，來源站台轉送付款需求時帶的 source）
 *   2. 允許連入的 IP（allow_ips，支援 CIDR；空白 = 不限制）→ 第一階段篩選
 *   3. 接受的來源端網站（allow_origins，比對 Origin/Referer；空白 = 不檢查）
 *   4. 預設回呼網址：callback_url（NOTIFY 結果通知）／ result_url（RESULT 導回頁）
 *   另外：簽章金鑰（secret）、可回呼網域白名單（allow_hosts）、啟用與備註
 */

require_once(dirname(__FILE__) . '/includes/common.php');
require_once(dirname(__FILE__) . '/includes/layout.php');

nw_require_admin();

$ip = nw_client_ip();
$flash = nw_req('msg', '');
$err = '';
$act = nw_req('act', '');

/** 允許字元檢查：IP / 主機名清單 */
function clean_rule_list($s)
{
    $parts = preg_split('/[\s,;]+/', trim((string)$s), -1, PREG_SPLIT_NO_EMPTY);
    $out = array();
    foreach ($parts as $p) {
        $out[] = nw_truncate($p, 64);
    }
    return implode(',', $out);
}

function validate($code, $name, $secret, $cbUrl, $rsUrl, $allowIps, $allowOrigins)
{
    if (!preg_match('/^[0-9A-Za-z_]{2,32}$/', $code)) {
        return '用戶代碼限 2~32 碼英數或底線';
    }
    if ($name === '') {
        return '請填寫站台名稱';
    }
    if ($secret !== '' && strlen($secret) < 8) {
        return '簽章金鑰至少 8 碼（留空代表不驗簽，不建議）';
    }
    foreach (array('預設回呼網址 NOTIFY' => $cbUrl, '預設導回網址 RESULT' => $rsUrl) as $label => $u) {
        if ($u !== '' && !preg_match('#^https?://#i', $u)) {
            return $label . ' 必須是 http(s) 開頭';
        }
    }
    if ($allowOrigins !== '' && preg_match('#^(https?://|/|\*)#i', $allowOrigins)) {
        return '接受的來源端網站請填主機名（如 shop.example.com），不要填完整網址或萬用字元';
    }
    return '';
}

// ---------------------------------------------------------------- 儲存
if ($act === 'save') {
    $code = nw_post('source_code');
    $name = nw_post('source_name');
    $secret = nw_post('secret');
    $cbUrl = nw_post('callback_url');
    $rsUrl = nw_post('result_url');
    $allowIps = clean_rule_list(nw_post('allow_ips'));
    $allowOrigins = clean_rule_list(nw_post('allow_origins'));
    $allowHosts = clean_rule_list(nw_post('allow_hosts'));
    $isActive = nw_post('is_active') === '1' ? 1 : 0;
    $remark = nw_truncate(nw_post('remark'), 255);

    $err = validate($code, $name, $secret, $cbUrl, $rsUrl, $allowIps, $allowOrigins);

    if ($err === '') {
        $exist = DB::queryRow("SELECT * FROM `pay_sources` WHERE `source_code` = '" . DB::esc($code) . "' LIMIT 1");
        $data = array(
            'source_code' => $code,
            'source_name' => nw_truncate($name, 100),
            'callback_url' => nw_truncate($cbUrl, 255),
            'result_url' => nw_truncate($rsUrl, 255),
            'secret' => $secret,
            'allow_ips' => nw_truncate($allowIps, 255),
            'allow_origins' => nw_truncate($allowOrigins, 255),
            'allow_hosts' => nw_truncate($allowHosts, 255),
            'is_active' => $isActive,
            'remark' => $remark,
            'updated_at' => nw_now(),
        );
        if ($exist) {
            unset($data['source_code']);
            DB::update('pay_sources', $data, array('source_code' => $code));
            PayGateway::log('', 'SYSTEM', 'SOURCE_UPDATE', 'OK', '更新來源站台設定：' . $code, $data, $ip);
            nw_redirect('pay_sources_admin.php?msg=' . urlencode('已更新來源站台 ' . $code));
        } else {
            $data['created_at'] = nw_now();
            DB::insert('pay_sources', $data);
            PayGateway::log('', 'SYSTEM', 'SOURCE_CREATE', 'OK', '新增來源站台：' . $code, $data, $ip);
            nw_redirect('pay_sources_admin.php?msg=' . urlencode('已新增來源站台 ' . $code));
        }
    }
}

// ---------------------------------------------------------------- 停用 / 刪除
if ($act === 'toggle' || $act === 'delete') {
    $code = nw_req('code');
    $exist = DB::queryRow("SELECT * FROM `pay_sources` WHERE `source_code` = '" . DB::esc($code) . "' LIMIT 1");
    if ($exist) {
        if ($act === 'delete') {
            DB::exec("DELETE FROM `pay_sources` WHERE `source_code` = '" . DB::esc($code) . "'");
            PayGateway::log('', 'SYSTEM', 'SOURCE_DELETE', 'WARN', '刪除來源站台：' . $code, $exist, $ip);
            nw_redirect('pay_sources_admin.php?msg=' . urlencode('已刪除 ' . $code));
        } else {
            DB::update('pay_sources', array(
                'is_active' => $exist['is_active'] ? 0 : 1,
                'updated_at' => nw_now(),
            ), array('source_code' => $code));
            PayGateway::log('', 'SYSTEM', 'SOURCE_TOGGLE', 'OK',
                ($exist['is_active'] ? '停用' : '啟用') . '來源站台：' . $code, null, $ip);
            nw_redirect('pay_sources_admin.php?msg=' . urlencode(($exist['is_active'] ? '已停用 ' : '已啟用 ') . $code));
        }
    }
    nw_redirect('pay_sources_admin.php?msg=' . urlencode('找不到來源站台'));
}

$editCode = nw_req('edit', '');
$edit = false;
if ($editCode !== '') {
    $edit = DB::queryRow("SELECT * FROM `pay_sources` WHERE `source_code` = '" . DB::esc($editCode) . "' LIMIT 1");
}
$rows = DB::queryAll("SELECT * FROM `pay_sources` ORDER BY `source_code` ASC");

/** 產生來源站台金鑰（用於新增時） */
function new_secret()
{
    $chars = 'abcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 32; $i++) {
        $s .= $chars[mt_rand(0, strlen($chars) - 1)];
    }
    return $s;
}

function v($row, $key, $default)
{
    return isset($row[$key]) && $row[$key] !== '' ? $row[$key] : $default;
}

nw_page_head('來源站台設定', '子公司／各網站的准入與回呼設定');
if ($flash !== '') {
    echo '<div class="notice">' . nw_e($flash) . '</div>';
}
if ($err !== '') {
    echo '<p class="bad">' . nw_e($err) . '</p>';
}
?>
<div class="notice">
  <b>准入流程（pay_request.php 收到轉送時）</b>
  第一階段：查用戶代碼 → 比對允許連入的 IP → 比對來源網站（Origin/Referer）；<br>
  第二階段：第一階段通過後才比對 sign；<br>
  任一階段不通過 → 回 <b>HTTP 403</b> 並寫入 pay_logs（stage=ACCESS）。<br>
  <span class="muted">IP 與來源網站清單留空 = 不限制；憑證金鑰請以 https 私下傳給各站台。</span>
</div>

<h2>站台清單</h2>
<table>
  <tr><th>用戶代碼</th><th>站台名稱</th><th>允許 IP</th><th>來源網站</th><th>NOTIFY 回呼</th><th>RESULT 導回</th><th>狀態</th><th>操作</th></tr>
<?php if (count($rows) === 0) { ?>
  <tr><td colspan="8" class="muted">尚未建立來源站台</td></tr>
<?php } ?>
<?php foreach ($rows as $r) { ?>
  <tr>
    <td><code><?php echo nw_e($r['source_code']); ?></code></td>
    <td><?php echo nw_e($r['source_name']); ?></td>
    <td><?php echo $r['allow_ips'] === '' ? '<span class="muted">不限</span>' : nw_e($r['allow_ips']); ?></td>
    <td><?php echo $r['allow_origins'] === '' ? '<span class="muted">不檢查</span>' : nw_e($r['allow_origins']); ?></td>
    <td style="word-break:break-all"><?php echo $r['callback_url'] === '' ? '<span class="muted">-</span>' : nw_e($r['callback_url']); ?></td>
    <td style="word-break:break-all"><?php echo $r['result_url'] === '' ? '<span class="muted">-</span>' : nw_e($r['result_url']); ?></td>
    <td class="<?php echo $r['is_active'] ? 'ok' : 'bad'; ?>"><?php echo $r['is_active'] ? '啟用' : '停用'; ?></td>
    <td>
      <a class="btn ghost sm" href="pay_sources_admin.php?edit=<?php echo urlencode($r['source_code']); ?>">編輯</a>
      <a class="btn ghost sm" href="pay_sources_admin.php?act=toggle&code=<?php echo urlencode($r['source_code']); ?>"><?php echo $r['is_active'] ? '停用' : '啟用'; ?></a>
      <a class="btn ghost sm" href="pay_sources_admin.php?act=delete&code=<?php echo urlencode($r['source_code']); ?>">刪除</a>
    </td>
  </tr>
<?php } ?>
</table>

<h2><?php echo $edit ? '編輯來源站台：' . nw_e($editCode) : '新增來源站台'; ?></h2>
<form method="post" action="pay_sources_admin.php">
  <input type="hidden" name="act" value="save">
  <table>
    <tr><td class="k">用戶代碼 *</td><td><input name="source_code" value="<?php echo nw_e(v($edit, 'source_code', '')); ?>" <?php echo $edit ? 'readonly' : ''; ?> style="padding:6px 10px;border:1px solid var(--line);border-radius:6px" placeholder="shop_a"></td></tr>
    <tr><td class="k">站台名稱 *</td><td><input name="source_name" value="<?php echo nw_e(v($edit, 'source_name', '')); ?>" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px" placeholder="XX 公司購物網"></td></tr>
    <tr><td class="k">簽章金鑰 *</td><td><input name="secret" size="40" value="<?php echo nw_e(v($edit, 'secret', new_secret())); ?>"><br><span class="muted">來源站台用同一組金鑰計算 sign（HMAC-SHA256）。<?php echo $edit ? '（留空不會變更既有金鑰）' : '已自動產生，請複製給該站台。'; ?></span></td></tr>
    <tr><td class="k">允許連入的 IP</td><td><input name="allow_ips" size="60" value="<?php echo nw_e(v($edit, 'allow_ips', '')); ?>" placeholder="203.0.113.10, 198.51.100.0/24"><br><span class="muted">第一階段篩選；留空 = 不限制。對外服務請盡量設定。</span></td></tr>
    <tr><td class="k">接受的來源網站</td><td><input name="allow_origins" size="60" value="<?php echo nw_e(v($edit, 'allow_origins', '')); ?>" placeholder="shop.example.com, .example.com"><br><span class="muted">比對瀏覽器的 Origin / Referer 主機名（同藍新限制來源網站的作法）。留空 = 不檢查；設定後未帶 Origin/Referer 的純 API 呼叫會被擋。</span></td></tr>
    <tr><td class="k">預設回呼網址 NOTIFY</td><td><input name="callback_url" size="60" value="<?php echo nw_e(v($edit, 'callback_url', '')); ?>" placeholder="https://shop.example.com/pay_notify.php"><br><span class="muted">付款結果通知（機器對機器 POST，含 sign）。</span></td></tr>
    <tr><td class="k">預設導回網址 RESULT</td><td><input name="result_url" size="60" value="<?php echo nw_e(v($edit, 'result_url', '')); ?>" placeholder="https://shop.example.com/thanks.php"><br><span class="muted">消費者付款完成後導回此頁（附加訂單資訊與 sign）。</span></td></tr>
    <tr><td class="k">可回呼網域</td><td><input name="allow_hosts" size="60" value="<?php echo nw_e(v($edit, 'allow_hosts', '')); ?>" placeholder="shop.example.com, api.example.com"><br><span class="muted">限制請求自帶的 callback_url / result_url 只能指向這些網域；留空 = 不檢查。</span></td></tr>
    <tr><td class="k">啟用</td><td><label><input type="checkbox" name="is_active" value="1" <?php echo $edit && !$edit['is_active'] ? '' : 'checked'; ?>> 啟用（取消勾選＝拒絕所有付款需求）</label></td></tr>
    <tr><td class="k">備註</td><td><input name="remark" size="60" value="<?php echo nw_e(v($edit, 'remark', '')); ?>"></td></tr>
  </table>
  <p><button class="btn" type="submit">儲存</button>
  <?php if ($edit) { ?>
    <a class="btn ghost" href="pay_sources_admin.php">取消編輯</a>
  <?php } ?>
  <a class="btn ghost" href="pay_query.php">交易查詢</a></p>
</form>
<?php
nw_page_foot();