# 藍新金流付款閘道（PHP 5.2 + MySQL 5.x）

接收「公司其他網站」轉送過來的付款需求 → 顯示付款資訊給消費者 → 消費者確認後依藍新規範導向
藍新 MPG 付款閘道（線上刷卡 / ATM 虛擬帳號）→ 接收藍新付款結果 → 回呼來源站台並記錄成功與否。
全程寫入 MySQL 備查，並提供後台查詢交易狀態與結果。

- 無框架、無 composer、無 AJAX 依賴，`複製 → 改 config → 上傳 → 開 install.php` 即可。
- 程式碼寫在 PHP 5.2 可執行的範圍（無 namespace / 短陣列 / `??` / closure / `__DIR__`），
  但同時可在 PHP 5.3~8.x 執行（本機測試用 PHP 8.5 + MariaDB 跑過全套測試）。

---

## 一、三支主要頁面

| 檔案 | 角色 | 誰會呼叫 |
|---|---|---|
| `pay_request.php` | **驅動付款**：接收轉送的付款需求 → 顯示付款資訊 → 消費者確認 → 導向藍新 | 來源站台 POST / 消費者瀏覽 |
| `pay_notify.php` | **接收付款結果**：接收藍新通知（`?src=notify`）、前台返回（`?src=return`）、ATM 取號（`?src=customer`），更新 MySQL 並回呼來源站台 | 藍新伺服器 POST |
| `pay_query.php` | **查詢交易狀態與結果**：列表 / 明細 / 事件時間軸 / 回呼紀錄，可主動向藍新查詢與重送回呼 | 管理人員（需登入） |

輔助頁面：

| 檔案 | 說明 |
|---|---|
| `pay_result.php` | 消費者看的付款結果頁（成功 / 失敗 / ATM 虛擬帳號資訊） |
| `includes/` | `common.php`（工具與簽章）、`DB.php`、`NewebPayCrypto.php`（藍新 AES/SHA）、`Http.php`、`PayGateway.php`（業務邏輯）、`layout.php` |
| `sql/schema.sql` | 資料表定義 |
| `install.php` | 建立資料表、測試來源站台 |
| `demo/` | 模擬來源站台：送出付款需求、接收回呼（**上線請刪除**） |
| `_dev/` | 模擬藍新閘道、加密向量測試、端對端測試（**上線請刪除**） |

---

## 二、資料表

| 資料表 | 用途 |
|---|---|
| `pay_orders` | 訂單主檔：金額、付款方式、狀態、藍新交易序號、ATM 虛擬帳號、回呼網址與回呼狀態 |
| `pay_logs` | 事件紀錄：收到轉送（RECEIVE）、導向藍新（DISPATCH）、藍新結果（NOTIFY/RETURN/CUSTOMER）、主動查詢（QUERY）、回呼（CALLBACK），含失敗原因 |
| `pay_callbacks` | 回呼來源站台逐次紀錄：網址、送出內容、HTTP code、耗時、成功與否 |
| `pay_sources` | 來源站台設定：回呼網址、簽章金鑰、可回呼網域白名單 |

訂單狀態：`CREATED`（已收到需求）→ `CONFIRMED` → `DISPATCHED`（已導向藍新）→ `PAID` / `FAILED` /
`CANCELLED` / `REFUNDED`；ATM 取號完成時為 `VACC_ISSUED`。

---

## 三、安裝

1. 上傳所有檔案到網站目錄（需對外開放的只有 `pay_request.php`、`pay_notify.php`、`pay_result.php`）。
2. 複製 `config.sample.php` → `config.php`，填入：

   ```php
   define('DB_HOST', '...'); define('DB_USER', '...'); define('DB_PASS', '...'); define('DB_NAME', '...');
   define('NEWEB_ENV', 'test');          // test=ccore / live=core
   define('NEWEB_MERCHANT_ID', 'MS......');   // 藍新後台：會員中心>商店管理>商店資料設定>串接設定
   define('NEWEB_HASH_KEY', '32碼金鑰');
   define('NEWEB_HASH_IV',  '16碼金鑰');
   define('BASE_URL', 'https://pay.example.com.tw/gateway/');   // 需為藍新可連線的公開網址
   define('ADMIN_PASSWORD', '後台密碼');
   ```
3. 建立資料庫（utf8）後開 `install.php`（或 `php install.php`）。**安裝完成後請刪除 `install.php`，
   並把 `$allowRun` 改為 0。**
4. 在藍新後台開通金流工具（信用卡、ATM/WebATM、超商代碼…），並確認本系統三個回呼網址設定正確：

   | 藍新欄位 | 請填 | 本系統入口 |
   |---|---|---|
   | （程式內帶入 ReturnURL） | — | `pay_notify.php?src=return` |
   | （程式內帶入 NotifyURL） | — | `pay_notify.php?src=notify` |
   | （程式內帶入 CustomerURL） | — | `pay_notify.php?src=customer` |

   藍新規範要求 ReturnURL 與 NotifyURL **不可相同**，本系統以 `src` 參數區分，網址不同即符合。
5. 來源站台請於 `pay_sources` 建立一筆（代號、名稱、回呼網址、簽章金鑰、可回呼網域白名單）。

---

## 四、來源站台如何轉送付款需求

`POST` 打到 `https://你的網域/pay_request.php`（form 或 query string 皆可）：

| 參數 | 必填 | 說明 |
|---|---|---|
| `source` | 是 | 來源站台代號（對應 `pay_sources.source_code`） |
| `source_order_no` | 是 | 來源站台訂單編號；同站台不可重複（重複會被忽略並記錄 `DUPLICATE_ORDER`） |
| `amount` | 是 | 金額，整數元（`"480"`） |
| `item_desc` | 否 | 商品說明，50 字內 |
| `buyer_name` / `buyer_email` / `buyer_phone` | 否 | 消費者資訊 |
| `pay_type` | 否 | `credit` 線上付款 / `atm` ATM 虛擬帳號 / `both`（預設，讓消費者在藍新頁選） |
| `callback_url` | 否 | 付款結果回呼網址；留空則用 `pay_sources.callback_url`。必須在允許網域白名單內 |
| `timestamp` | 是 | Unix 時間戳，與伺服器時間差 ±300 秒（防重送） |
| `mode` | 否 | `redirect`（預設，302 到付款資訊確認頁）/ `json`（回 JSON） |
| `sign` | 是 | 見下方簽章規則 |

回應（`mode=json`）：

```json
{"ok":true,"msg":"訂單已建立","order_no":"NW2610152043253BF77BF9","amount":480,
 "pay_url":"https://你的網域/pay_request.php?step=confirm&ono=NW...&t=..."}
```

`pay_url` 帶 `t` 權杖，來源站台可以 302 直接把消費者送過去（消費者看到付款資訊 → 按「確認付款」）。

### 簽章規則（雙方一致）

1. 取所有參數（**不含 `sign`**），以 key 由小到大排序（`ksort`）。
2. 串成 `k1=v1&k2=v2...`（值不做 urlencode）。
3. `sign = strtoupper(hash_hmac('sha256', 上一步字串, secret))`（hex 大寫）。

PHP 範例：

```php
$fields = array('source'=>'shop', 'source_order_no'=>'SO20260001', 'amount'=>480,
                'pay_type'=>'both', 'timestamp'=>time());
ksort($fields);
$parts = array();
foreach ($fields as $k => $v) { $parts[] = $k . '=' . $v; }
$fields['sign'] = strtoupper(hash_hmac('sha256', implode('&', $parts), $shopSecret));
// 然後把 $fields 整包 POST 出去
```

> 來源站台若自行實作簽章有困難，可直接沿用本專案 `includes/common.php` 的 `nw_sign()`。

---

## 五、回呼來源站台（金流 → 來源站台）

結果確認後，本系統會 `POST`（`application/x-www-form-urlencoded`）到 `callback_url`：

```
event, order_no, source_code, source_order_no, status, status_text, message, amount, currency,
neweb_trade_no, payment_type, payment_type_text, pay_time,
virtual_account, bank_code, bank_name, branch_name, expire_date, timestamp, sign
```

- `status`：`PAID` / `FAILED` / `CANCELLED` / `REFUNDED` / `VACC_ISSUED`（ATM 已取號）
- 簽章同第四節（不含 `sign`，ksort，HMAC-SHA256 轉大寫）。
- **來源站台務必回應字串包含 `OK`**（可在 `config.php` 的 `CALLBACK_ACK` 調整；設空字串則只看 HTTP code）。
- 每次嘗試（含重試）都寫入 `pay_callbacks`：HTTP code、回應內容、耗時、成功與否。

重送與冪等：

- 同一狀態不重複回呼（避免來源站台重複出貨）；狀態變更時自動再回呼。
- `pay_query.php` 可手動「重送回呼來源站台」（強制）。
- 重複轉送的同一筆來源訂單編號不會重複建單。

---

## 六、藍新加解密實作要點

`includes/NewebPayCrypto.php`，與藍新官方外掛一致：

- `TradeInfo` = `bin2hex(AES-256-CBC(http_build_query 風格的字串, HashKey, HashIV))`，
  以 **32 bytes 補齊**（`NEWEB_PAD_BLOCK`，官方外掛用法）；解密時容忍 1~32 的 padding。
- `TradeSha` = `strtoupper(sha256("HashKey={key}&{TradeInfo}&HashIV={iv}"))`
- `CheckValue`（查詢 API）= `strtoupper(sha256("IV={iv}&Amt=..&MerchantID=..&MerchantOrderNo=..&Key={key}"))`
- `CheckCode`（查詢結果）= `strtoupper(sha256("HashIV={iv}&" + ksort 後的 Amt/MerchantID/MerchantOrderNo/TradeNo + "&HashKey={key}"))`
- PHP 5.3+ 用 `openssl_*`；PHP 5.2 自動改用 `mcrypt`（`MCRYPT_RIJNDAEL_128` + 32 碼金鑰 = AES-256）。

**驗收依據**：`_dev/test_crypto.php` 會用藍新技術手冊的範例金鑰加密手冊中的請求字串，
加密結果前 16 bytes 必須等於手冊列出的 `f79eac33c4f3245d58f17b544c5d38`。

---

## 七、測試

```bash
php _dev/test_crypto.php    # 加解密標準向量 + 公式驗證（14 項）
php _dev/test_e2e.php       # 端對端：轉送→確認→藍新→結果→回呼（含防偽，共 62 項）
```

`_dev/mock_newebpay_gateway.php` 模擬藍新（MPG 付款頁、ATM 取號、付款結果回呼、單筆查詢 API），
`demo/source_site.php` 模擬來源站台。測試時 `config.php` 把
`NEWEB_GATEWAY_URL` / `NEWEB_QUERY_URL` 指到模擬閘道即可，**上線請刪掉這兩行改用真實藍新網址**。

啟動本機測試環境：

```bash
brew install php mariadb
mariadb-install-db --datadir=/tmp/nwmysql/data --auth-root-authentication-method=normal
mariadbd --datadir=/tmp/nwmysql/data --socket=/tmp/nwmysql/run/my.sock --port=13306 &
mariadb -u root --socket=/tmp/nwmysql/run/my.sock -e "CREATE DATABASE newebpay_gateway DEFAULT CHARACTER SET utf8;"
php install.php
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8811 -t .     # 需要多 worker：模擬閘道會反向呼叫本機
```
> `PHP_CLI_SERVER_WORKERS` 是必要的：內建伺服器單一執行緒會讓「藍新回呼自己」互相等待。

---

## 八、安全注意事項與上線檢查

- [ ] `config.php` 加入金鑰與資料庫密碼後**不可從網站下載**（已附 `.htaccess`；nginx 請自行設 deny 規則）。
- [ ] 安裝後刪除 `install.php`、`_dev/`、`demo/`、`sql/`。
- [ ] `BASE_URL` 必須是藍新可連線的 HTTPS 公開網址；`ALLOW_HTTP` 正式環境必須為 0（僅測試用 1）。
- [ ] `pay_query.php` 已加密碼保護（`ADMIN_PASSWORD` 或 `ADMIN_KEY`），務必設定。
- [ ] 來源站台 `pay_sources.allow_hosts` 務必填（限制回呼網域，避免訂單資訊被外送）。
- [ ] 來源站台金鑰與藍新金鑰分開保管、不可寫進前端 JS 或公開檔。
- [ ] 通知端點需允許外站 POST（不要用 IP 白名單擋掉藍新 IP，也不要擋 query string 的 `src`）。
- [ ] 付款成功以 `NotifyURL`（幕後通知）為準；`ReturnURL` 只用於前台顯示，兩者不可合成同一支程式邏輯。
- [ ] 資料表 `pay_logs` / `pay_callbacks` 會成長，請依貴司個資法規設定保存期限並定期封存。
- [ ] 日誌中的卡號/token 欄位已遮蔽（`maskPost`），但仍建議設定 `LOG_RAW_POST = 0` 減少敏感資料落地。