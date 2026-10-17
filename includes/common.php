<?php
/**
 * common.php - 共同載入與工具函式（PHP 5.2 相容）
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Taipei');

if (!defined('BASE_URL')) {
    // 設定檔位置（優先序）：NW_CONFIG_FILE 環境變數 > _config_path.php > 專案內的 config.php
    // IIS/Windows 常見做法是把含金鑰的設定放在網站根目錄之外，用 _config_path.php 指向它；
    // 環境變數優先，方便部署腳本做本機自我檢查時暫時換一份設定。
    $__cfg = '';
    if (($__env = getenv('NW_CONFIG_FILE')) !== false && $__env !== '') {
        $__cfg = $__env;
    }
    if ($__cfg === '') {
        $__cfgMap = dirname(__FILE__) . '/../_config_path.php';
        if (file_exists($__cfgMap)) {
            require_once($__cfgMap);
        }
    }
    if ($__cfg === '' && defined('NW_CONFIG_FILE') && NW_CONFIG_FILE !== '') {
        $__cfg = NW_CONFIG_FILE;
    }
    if ($__cfg === '') {
        $__cfg = dirname(__FILE__) . '/../config.php';
    }
    if (!file_exists($__cfg)) {
        $__dir = str_replace('\\', '/', dirname(__FILE__) . '/..');
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        die('<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8">'
            . '<title>尚未建立設定檔</title>'
            . '<style>body{font:15px/1.8 -apple-system,"PingFang TC",sans-serif;max-width:640px;margin:60px auto;padding:0 16px;color:#22262e}'
            . 'pre{background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:8px;overflow:auto}</style></head><body>'
            . '<h2>尚未建立設定檔</h2>'
            . '<p>這是藍新金流付款閘道。請先複製一份設定檔並填入參數：</p>'
            . '<pre>cp ' . $__dir . '/config.sample.php ' . $__dir . '/config.php</pre>'
            . '<p>Windows：</p><pre>copy "' . str_replace('/', '\\', $__dir) . '\\config.sample.php" "'
            . str_replace('/', '\\', $__dir) . '\\config.php"</pre>'
            . '<p>接著用「範本 → 設定檔的對照表」填入：</p>'
            . '<ul><li><code>DB_*</code>：資料庫連線資訊</li>'
            . '<li><code>NEWEB_MERCHANT_ID / NEWEB_HASH_KEY / NEWEB_HASH_IV</code>：藍新金流後台「商店資料設定 → 串接設定」取得</li>'
            . '<li><code>NEWEB_ENV</code>：測試請用 <code>test</code>，正式收款改成 <code>live</code></li>'
            . '<li><code>BASE_URL</code>：這個網站的公開網址，<b>必須是藍新伺服器打得進來的 HTTPS 網址</b></li>'
            . '<li><code>ADMIN_PASSWORD</code>：後台（交易查詢、站台設定、模擬面板）的密碼</li></ul>'
            . '<p>完成後再開一次本頁即可。接著執行 <code>php install.php</code> 建立資料表，'
            . '或直接用 <code>deploy\\windows-deploy.ps1</code> 一鍵完成。</p></body></html>');
    }
    require_once($__cfg);
}

require_once(dirname(__FILE__) . '/DB.php');
require_once(dirname(__FILE__) . '/NewebPayCrypto.php');
require_once(dirname(__FILE__) . '/Http.php');
require_once(dirname(__FILE__) . '/PayGateway.php');
require_once(dirname(__FILE__) . '/RemoteConfig.php');

// ---------------------------------------------------------------- 基本工具

/** HTML escaping */
function nw_e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function nw_now()
{
    return date('Y-m-d H:i:s');
}

/** 取得來源站台代號的 payload 白名單值 */
function nw_post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function nw_get($key, $default = '')
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

/** POST + GET 皆可取 */
function nw_req($key, $default = '')
{
    if (isset($_POST[$key])) {
        return trim((string)$_POST[$key]);
    }
    if (isset($_GET[$key])) {
        return trim((string)$_GET[$key]);
    }
    return $default;
}

function nw_client_ip()
{
    $ip = '';
    if (isset($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    if ($ip === '' || $ip === '::1' || $ip === '::ffff:127.0.0.1') {
        $ip = '127.0.0.1';
    }
    return substr($ip, 0, 64);
}

function nw_truncate($s, $len)
{
    $s = (string)$s;
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $len, 'UTF-8');
    }
    return substr($s, 0, $len);
}

/** 產生訂單編號：MerchantOrderNo 限 30 碼英數與底線 */
function nw_make_order_no()
{
    $prefix = defined('ORDER_PREFIX') ? ORDER_PREFIX : 'NW';
    $rand = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));
    return substr($prefix . date('ymdHis') . $rand, 0, 30);
}

/** 檢查訂單編號格式（藍新：英數底線，1~30） */
function nw_valid_order_no($no)
{
    return (bool)preg_match('/^[0-9A-Za-z_]{1,30}$/', $no);
}

/** 金額：整數元，> 0 */
function nw_money($v)
{
    if (!preg_match('/^[0-9]{1,10}$/', (string)$v)) {
        return 0;
    }
    $i = (int)$v;
    return $i > 0 ? $i : 0;
}

function nw_json_encode($arr)
{
    $flag = defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0;
    return json_encode($arr, $flag);
}

function nw_json_out($arr)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo nw_json_encode($arr);
    exit;
}

/**
 * 未授權回應（HTTP 403）。PHP 5.2 沒有 http_response_code()，用 header 指定狀態碼。
 * @param string $mode json | html
 */
function nw_deny($status, $title, $reason, $mode)
{
    $status = (int)$status;
    header('HTTP/1.1 ' . $status . ' Forbidden');
    header('Status: ' . $status . ' Forbidden');
    header('Content-Type: ' . ($mode === 'json' ? 'application/json' : 'text/html') . '; charset=utf-8');
    header('Cache-Control: no-store');

    if ($mode === 'json') {
        echo nw_json_encode(array('ok' => false, 'code' => $status, 'msg' => $reason));
        exit;
    }

    require_once(dirname(__FILE__) . '/layout.php');
    nw_page_head($title, 'HTTP ' . $status);
    echo '<p class="bad">' . nw_e($reason) . '</p>';
    echo '<p class="muted">此請求已被拒絕並記錄於本系統日誌；若有疑問請洽系統管理員。</p>';
    nw_page_foot();
    exit;
}

function nw_redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function nw_base($path = '')
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

/** 狀態代碼 → 中文 */
function nw_status_text($s)
{
    $map = array(
        'CREATED' => '已收到付款需求',
        'CONFIRMED' => '消費者已確認付款',
        'DISPATCHED' => '已導向藍新付款頁',
        'VACC_ISSUED' => 'ATM 虛擬帳號已取號',
        'PAID' => '付款成功',
        'FAILED' => '付款失敗',
        'CANCELLED' => '已取消',
        'REFUNDED' => '已退款',
    );
    return isset($map[$s]) ? $map[$s] : $s;
}

/** 付款方式代碼 → 中文 */
function nw_paytype_text($t)
{
    $map = array(
        'credit' => '線上付款（信用卡）',
        'atm' => 'ATM 虛擬帳號',
        'both' => '線上付款 / ATM 虛擬帳號',
    );
    return isset($map[$t]) ? $map[$t] : $t;
}

function nw_payment_type_text($t)
{
    $map = array(
        'CREDIT' => '信用卡',
        'VACC' => 'ATM 虛擬帳號',
        'WEBATM' => '網路ATM',
        'BARCODE' => '超商條碼',
        'CVS' => '超商代碼',
        'LINEPAY' => 'LINE Pay',
        'ESUNWALLET' => '玉山Wallet',
        'TAIWANPAY' => '台灣Pay',
        'TWQR' => '簡單付',
        'CVSCOM' => '超商取貨',
        'AFTEE' => '先享後付',
        'OPPAY' => '大哥付你分期',
        'BITOPAY' => 'BitoPay',
    );
    return isset($map[$t]) ? $map[$t] : ($t === '' ? '' : $t);
}

// ---------------------------------------------------------------- 簽章工具
// 來源站台 <-> 本站 之間的簽章（雙方共用同一組規則，實作請見 README）

function nw_hmac_sha256($secret, $data)
{
    if (function_exists('hash_hmac')) {
        return hash_hmac('sha256', $data, $secret);
    }
    return md5($secret . $data);   // 極端舊環境退階
}

/** 產生簽章用的標準字串：ksort 後 k=v 以 & 連接（不含 sign 欄位） */
function nw_canonical($fields, $skipKey)
{
    $copy = array();
    foreach ($fields as $k => $v) {
        if ($k === $skipKey || $v === null) {
            continue;
        }
        $copy[$k] = (string)$v;
    }
    ksort($copy);
    $parts = array();
    foreach ($copy as $k => $v) {
        $parts[] = $k . '=' . $v;
    }
    return implode('&', $parts);
}

function nw_sign($secret, $fields, $signKey)
{
    // 統一以大寫 hex 表示（來源站台即使傳小寫也比對得過）
    return strtoupper(nw_hmac_sha256($secret, nw_canonical($fields, $signKey)));
}

function nw_verify_sign($secret, $fields, $sign, $signKey)
{
    return NewebPayCrypto::safeEquals(
        nw_sign($secret, $fields, $signKey),
        strtoupper(trim((string)$sign))
    );
}
// ---------------------------------------------------------------- 後台權限
// pay_query.php 會顯示訂單、消費者信箱、ATM 虛擬帳號等敏感資訊，務必保護。
// 兩種方式：session 登入（ADMIN_PASSWORD）或 直接帶 key（ADMIN_KEY，供程式化查詢）

function nw_admin_password()
{
    return defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : '';
}

function nw_admin_key()
{
    return defined('ADMIN_KEY') ? ADMIN_KEY : '';
}

/**
 * 後台存取檢查；未通過時直接輸出登入畫面並結束
 */
function nw_require_admin()
{
    $key = nw_admin_key();
    if ($key !== '' && nw_req('key') === $key) {
        return true;
    }

    if (function_exists('session_start')) {
        @session_start();
    }

    if (isset($_SESSION['nw_admin_ok']) && $_SESSION['nw_admin_ok'] === true) {
        return true;
    }

    // 登入後要回到原本的頁面（而不是固定回到查詢頁）
    $self = (isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] !== '')
        ? basename($_SERVER['SCRIPT_NAME']) : 'pay_query.php';
    $back = nw_req('back', $self);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_pwd'])) {
        $want = nw_admin_password();
        if ($want !== '' && NewebPayCrypto::safeEquals($want, (string)$_POST['admin_pwd'])) {
            $_SESSION['nw_admin_ok'] = true;
            nw_redirect($back);
        }
        $error = '密碼錯誤';
    } else {
        $error = '';
    }

    require_once(dirname(__FILE__) . '/layout.php');
    nw_page_head('管理後台', '請先輸入管理密碼');
    if ($error !== '') {
        echo '<p class="bad">' . nw_e($error) . '</p>';
    }
    echo '<form method="post" action="' . nw_e($self . '?back=' . rawurlencode($back)) . '">';
    echo '<p><input type="password" name="admin_pwd" placeholder="管理密碼" autofocus '
        . 'style="padding:8px 12px;border:1px solid var(--line);border-radius:6px"> ';
    echo '<button class="btn" type="submit">登入</button></p>';
    echo '</form>';
    nw_page_foot();
    exit;
}
