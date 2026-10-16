<?php
/**
 * install.php - 建立資料表 / 測試來源站台 / 選填測試資料
 *
 * 執行方式：瀏覽器開 install.php，或
 *   php install.php            （CLI）
 * 正式環境請於安裝後刪除本檔（或把 $allowRun 設為 0）。
 */

require_once(dirname(__FILE__) . '/includes/common.php');

$allowRun = 1;   // ← 上線後請改為 0（並刪除本檔）

$isCli = isset($argv) && isset($argv[0]) && substr($argv[0], -4) !== '';
if (!$allowRun) {
    exit('install.php 已停用');
}
// 網頁安裝只允許在「尚未建立來源站台」時執行，避免日後被他人重跑
if (!$isCli) {
    $already = false;
    try {
        $already = ((int)DB::queryValue("SELECT COUNT(*) FROM `pay_sources`", 0)) > 0;
    } catch (Exception $e) {
        $already = false;
    }
    if ($already && nw_req('force') !== '1') {
        exit('系統已安裝過，網頁安裝已停用（要重跑請改用 CLI：php install.php，或加 ?force=1）');
    }
}

$out = array();

/** 執行 SQL 檔（以 ; 分句） */
function run_sql_file($file)
{
    $sql = file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);   // 移除註解
    $parts = explode(';', $sql);
    $n = 0;
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        DB::exec($p);
        $n++;
    }
    return $n;
}

$out[] = '建立資料表：執行 ' . run_sql_file(dirname(__FILE__) . '/sql/schema.sql') . ' 個語句';

/** 舊版資料庫補欄位（相容 MySQL 4.1+，不用 ADD COLUMN IF NOT EXISTS） */
function ensure_column($table, $column, $definition)
{
    $cols = DB::queryAll('SHOW COLUMNS FROM `' . $table . '`');
    foreach ($cols as $c) {
        if ($c['Field'] === $column) {
            return false;
        }
    }
    DB::exec('ALTER TABLE `' . $table . '` ADD `' . $column . '` ' . $definition);
    return true;
}

$added = 0;
$added += ensure_column('pay_sources', 'result_url', "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '預設付款完成後導回來源站頁面'") ? 1 : 0;
$added += ensure_column('pay_sources', 'allow_ips', "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '允許連入的 IP，支援 CIDR'") ? 1 : 0;
$added += ensure_column('pay_sources', 'allow_origins', "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '接受的來源端網站'") ? 1 : 0;
$added += ensure_column('pay_orders', 'result_url', "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '付款完成後導回來源站頁面'") ? 1 : 0;
$out[] = '舊版資料庫欄位遷移：新增 ' . $added . ' 個欄位';

// 測試來源站台（正式環境請自行建立）
$demoSecret = 'DEMO-SECRET-123456';
$demo = array(
    'source_code' => 'demo',
    'source_name' => '測試來源站台',
    'callback_url' => nw_base('demo/callback_receiver.php'),
    'result_url' => nw_base('demo/callback_receiver.php?type=result'),
    'secret' => $demoSecret,
    'allow_ips' => '',
    'allow_origins' => '',
    'allow_hosts' => '',
    'is_active' => 1,
    'remark' => '安裝時自動建立，僅供測試',
    'created_at' => nw_now(),
    'updated_at' => nw_now(),
);
DB::upsert('pay_sources', $demo, array('source_name', 'callback_url', 'result_url', 'secret', 'allow_ips', 'allow_origins', 'allow_hosts', 'is_active', 'remark', 'updated_at'));
$out[] = '測試來源站台 demo（secret=' . $demoSecret . '，callback=' . $demo['callback_url'] . '）';

// 補幾筆測試資料（已有資料則跳過）
$has = (int)DB::queryValue("SELECT COUNT(*) FROM `pay_orders` WHERE `source_code` = 'demo'", 0);
if ($has === 0 && (!isset($argv) || isset($argv[1]) && $argv[1] === 'seed')) {
    $seed = array(
        array('order_no' => nw_make_order_no(), 'source_order_no' => 'DEMO-' . date('ymd') . '-001', 'amount' => 480,
            'item_desc' => '測試商品 A', 'buyer_email' => 'buyer@example.com', 'pay_type' => 'both', 'status' => 'CREATED',
            'status_msg' => '測試資料', 'created_at' => nw_now(), 'updated_at' => nw_now()),
        array('order_no' => nw_make_order_no(), 'source_order_no' => 'DEMO-' . date('ymd') . '-002', 'amount' => 1290,
            'item_desc' => '測試商品 B', 'buyer_email' => 'buyer2@example.com', 'pay_type' => 'atm', 'status' => 'CREATED',
            'status_msg' => '測試資料', 'created_at' => nw_now(), 'updated_at' => nw_now()),
    );
    foreach ($seed as $s) {
        $s['source_code'] = 'demo';
        $s['callback_url'] = $demo['callback_url'];
        $s['callback_state'] = 'PENDING';
        DB::insert('pay_orders', $s);
    }
    $out[] = '已寫入 2 筆測試訂單';
}

echo implode("\n", $out) . "\n";