<?php
/**
 * 藍新金流（NewebPay）付款閘道 - 設定檔範本
 *
 * 複製成 config.php 後填入實際值。config.php 不應外流到公開版本庫。
 * 本系統可執行於 PHP 5.2 / MySQL 5.x（實際最低需求：PHP 5.2 + openssl 或 mcrypt）。
 *
 * @author  payment team
 */

// ---------------------------------------------------------------- 資料庫
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_USER', 'newebpay');
define('DB_PASS', 'put-your-password');
define('DB_NAME', 'newebpay_gateway');
define('DB_CHARSET', 'utf8');        // MySQL 5.5 以下請用 utf8（=utf8mb3）

// ---------------------------------------------------------------- 藍新金流
// 測試環境 https://ccore.newebpay.com ｜ 正式環境 https://core.newebpay.com
define('NEWEB_ENV', 'test');          // test | live
define('NEWEB_MERCHANT_ID', 'MS00000000');
define('NEWEB_HASH_KEY', '');         // 32 碼
define('NEWEB_HASH_IV', '');          // 16 碼
define('NEWEB_VERSION', '2.3');       // 串接版本
define('NEWEB_PAD_BLOCK', 32);        // 藍新官方外掛以 32 bytes 補齊；若改 16 請一併確認藍新端
// 測試時可指向模擬閘道；正式環境請刪除或留空
// define('NEWEB_GATEWAY_URL', 'https://ccore.newebpay.com/MPG/mpg_gateway');
// define('NEWEB_QUERY_URL', 'https://ccore.newebpay.com/API/QueryTradeInfo');
define('NEWEB_LANG', 'zh-tw');

// ---------------------------------------------------------------- 本站網址
// 需為藍新伺服器可連線的公開網址（HTTPS 建議）
define('BASE_URL', 'https://pay.example.com.tw/gateway/');

define('ORDER_PREFIX', 'NW');         // 訂單編號前綴（MerchantOrderNo 限 30 碼英數底線）

// ---------------------------------------------------------------- 後台管理
// pay_query.php 會顯示訂單、消費者信箱、ATM 虛擬帳號，務必設定
define('ADMIN_PASSWORD', 'change-this-password');
define('ADMIN_KEY', '');              // 選用：程式化查詢用（?key=...），留空則只能登入

// wh-console 後台設定（僅顯示用：公告、客服信箱；取不到會自動退回預設值，不影響付款）
define('ADMIN_ENDPOINT', '');         // 例如 https://wh-console.pages.dev/api/admin/config/newebpay-gateway
define('ADMIN_TOKEN', '');            // 該設定文件的唯讀 token

// ---------------------------------------------------------------- 來源站台白名單
// 來源站台（公司其他網站）若未在資料庫 pay_sources 建立，允許此處靜態定義
$NEWEB_INLINE_SOURCES = array(
    // 'partner_a' => array('name' => 'XX公司購物網', 'callback_url' => 'https://shop.example.com.tw/pay_notify.php', 'secret' => 'CHANGE-ME'),
);

// ---------------------------------------------------------------- 其它
define('CALLBACK_TIMEOUT', 10);       // 秒
define('CALLBACK_RETRY', 2);          // 回呼失敗重試次數（不含第一次）
define('CALLBACK_ACK', 'OK');         // 來源站台回應內容包含此字串視為成功（留空＝只看 HTTP code）
define('ATM_EXPIRE_DAYS', 7);         // ATM 虛擬帳號繳費期限
define('LOG_RAW_POST', 1);            // 是否記錄藍新回傳原始 POST（除卡號/token 外一律不落卡號）
define('ALLOW_HTTP', 0);              // 1 = 允許非 HTTPS 回呼（僅測試環境用，正式務必 0）