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
| `pay_sources_admin.php` | 來源站台（子公司網站）基本資料設定：用戶代碼／允許 IP／來源網站／NOTIFY＋RESULT 回呼網址 |
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
| `pay_sources` | 來源站台（子公司）設定：用戶代碼、允許連入的 IP、接受的來源網站、NOTIFY／RESULT 回呼網址、簽章金鑰、可回呼網域白名單、啟用狀態 |

訂單狀態：`CREATED`（已收到需求）→ `CONFIRMED` → `DISPATCHED`（已導向藍新）→ `PAID` / `FAILED` /
`CANCELLED` / `REFUNDED`；ATM 取號完成時為 `VACC_ISSUED`。

---

## 三、Windows 一鍵自動部署

`deploy/windows-deploy.ps1` 會把整件事做完：檢查環境 → 佈置檔案 → 產生設定檔（放在網站根目錄之外）→
建立資料庫與資料表 → 建立來源站台 → 自我檢查 → 輸出部署報告。

**雙擊執行（最簡單）**

```
deploy\windows-deploy.cmd
```
（`.cmd` 會先把主控台切成 UTF-8，逐項詢問參數；中文不會顯示成亂碼）

**命令列執行**

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy\windows-deploy.ps1 `
    -DbPass 'Db#12345' -DbRootPass 'root密碼' `
    -MerchantId 'MS12345678' `
    -HashKey '32碼金鑰' -HashIv '16碼金鑰' `
    -BaseUrl 'https://pay.example.com.tw/gateway/' `
    -AdminPassword '後台密碼' `
    -SourceCode 'shop' -SourceName '公司購物網' `
    -SourceAllowIps '203.0.113.10,198.51.100.0/24' -RunE2E
```

常用參數：

| 參數 | 說明 |
|---|---|
| `-WebRoot` | 網站根目錄（預設 `C:\inetpub\wwwroot\newebpay-gateway`） |
| `-ConfigDir` | 設定檔目錄（預設 `C:\inetpub\newebpay-conf`，**刻意放在網站之外**） |
| `-DbHost/-DbPort/-DbName/-DbUser/-DbPass` | 資料庫連線資訊 |
| `-DbRootUser/-DbRootPass`、`-MysqlExe` | 建立資料庫用的管理員帳號與 mysql.exe 路徑 |
| `-NewebEnv` | `test` / `live` |
| `-MerchantId/-HashKey/-HashIv/-BaseUrl/-AdminPassword` | 藍新商店參數與後台密碼 |
| `-SourceCode/-SourceName/-SourceSecret` | 首個子公司站台；不給 secret 會自動產生 32 碼金鑰 |
| `-SourceCallbackUrl/-SourceResultUrl/-SourceAllowIps/-SourceAllowOrigins` | 該站台的 NOTIFY／RESULT 與白名單 |
| `-RunE2E` | 額外跑 91 項端對端測試（Windows 的內建伺服器單一執行緒會自動略過並提示改用 IIS 手動驗收） |
| `-SkipDb`、`-DryRun`、`-Interactive` | 跳過資料庫／只演練不實作／互動填參數 |

腳本做的事（安全相關）：

- **設定檔不放在網站根目錄**：`config.php` 寫到 `-ConfigDir`，網站根目錄只留 `_config_path.php` 指向它，
  並確認設定檔**沒有 BOM**（有 BOM 會讓 PHP 在輸出前吐字元，破壞 HTTP 標頭）。
- 自動寫入 `web.config`：隱藏 `includes/`、禁止下載 `.sql`/`.log`/`.md`、`Cache-Control: no-store`、
  `X-Content-Type-Options: nosniff`。
- 自動排除 `_dev/`、`demo/`、`sql/`、`deploy/`、`install.php`、`config.php`（舊部署殘留也會清掉）。
- **可重複執行**：不清空既有資料庫與資料表；`install.php` 會自動補上舊版缺少的欄位，
  且不再覆蓋既有測試站台的設定。
- 自我檢查後會把暫存的測試檔清乾淨，並還原測試站台設定。

> Windows 的 PHP 內建伺服器是單一執行緒，跑不了「模擬藍新回呼自己」的流程，
> 因此 `-RunE2E` 在 Windows 會自動略過並提示：部署到 IIS 後照第九節的清單手動點一次完整流程。

---

## 四、安裝

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

## 五、來源端准入管制（兩階段）

收到轉送的 POST 時，依序做兩階段檢查，**任一階段不通過一律回 `HTTP 403`**，
並寫入 `pay_logs`（`stage=ACCESS`）留下拒絕原因與來源特徵：

| 階段 | 檢查項目 | 設定位置 | 不通過時 |
|---|---|---|---|
| **第一階段** | 用戶代碼（來源站台是否存在且啟用） | `pay_sources.source_code` / `is_active` | 403 `STAGE1_DENY` |
| | 允許連入的 IP（支援完整 IP 與 CIDR，逗號分隔；留空＝不限制） | `pay_sources.allow_ips` | 403 `STAGE1_DENY` |
| | 接受的來源端網站（比對 `Origin`／`Referer` 主機名，留空＝不檢查） | `pay_sources.allow_origins` | 403 `STAGE1_DENY` |
| **第二階段** | `sign` 簽章比對（**只在第一階段通過才執行**） | `pay_sources.secret` | 403 `STAGE2_BAD_SIGN` |
| | `timestamp` 時效（±300 秒，防重放） | — | 403 `BAD_TIMESTAMP` |

設計重點：**先識別來源、再驗簽章**，避免對非授權流量做簽章運算，也減少被拿來
爆破簽章的機會。通過第一階段後會寫一筆 `ACCESS/STAGE_OK` 紀錄，方便稽核。

補充規則：

- 其他回應碼：`409 Conflict`（同一來源訂單編號重複轉送，附既有訂單編號，不重複出貨）、
  `422`（付款需求資料有誤，如金額格式）。
- `mode=json` 時回應格式為 `{"ok":false,"code":403,"msg":"…"}`，方便來源站台記錄原因。
- IP 比對支援 IPv4 與 CIDR，`::ffff:127.0.0.1` 會自動轉成 IPv4 比對；IPv6 請直接填完整位址。
- 主機名比對支援子網域：`shop.example.com` 命中 `example.com`，`.example.com` 亦可。
- `allow_origins` 一旦設定，**未帶 `Origin`／`Referer` 的純 API（伺服器對伺服器）呼叫也會被擋**；
  這是刻意與藍新「限制來源網站」一致的做法。若子公司走純 API，請留空並改以 `allow_ips` 管制。

### 子公司站台要設定的四項（皆在 `pay_sources_admin.php` 維護）

| 項目 | 欄位 | 說明 |
|---|---|---|
| 用戶代碼 | `source_code` | 轉送時帶的 `source` 參數，2~32 碼英數底線 |
| 允許連入的 IP | `allow_ips` | 第一階段篩選，如 `203.0.113.10, 198.51.100.0/24` |
| 接受的來源網站 | `allow_origins` | 瀏覽器來源網站稽核，如 `shop.example.com` |
| 預設回呼網址 NOTIFY | `callback_url` | 付款結果通知（機器對機器 POST，含 sign） |
| 預設導回網址 RESULT | `result_url` | 消費者付款完成後導回（附加訂單資訊與 sign） |

---

## 六、來源站台如何轉送付款需求

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

## 七、回呼來源站台（金流 → 來源站台）

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

## 八、藍新加解密實作要點

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

## 九、測試

### 一鍵測試（推薦給非工程人員）

```bash
bash _dev/run_tests.sh        # 自動起服務 → 補資料表 → 跑完 105 項測試 → 印出網址
bash _dev/run_tests.sh stop   # 關掉測試服務
```

它會自己處理：啟動測試用 MariaDB（資料放 `/tmp/nwmysql`，**不碰你正式資料庫**、
重開機自動消失）、補齊資料表、啟動 PHP 伺服器、跑兩套測試。
跑完服務會留著，你可以直接用瀏覽器操作：

| 網址 | 用途 |
|---|---|
| `http://127.0.0.1:8811/demo/source_site.php` | 模擬公司其他網站送出付款需求 |
| `http://127.0.0.1:8811/pay_request.php` | 付款閘道說明頁 |
| `http://127.0.0.1:8811/pay_query.php` | 交易查詢（後台密碼見 `config.php` 的 `ADMIN_PASSWORD`） |
| `http://127.0.0.1:8811/pay_sources_admin.php` | 子公司站台設定（IP／來源網站／回呼網址） |
| `http://127.0.0.1:8811/demo/callback_receiver.php` | 看來源站台實際收到的回呼 |
| `http://127.0.0.1:8811/_dev/mock_newebpay_gateway.php` | 模擬藍新（可測刷卡成功／失敗／ATM 取號） |

> 全部輸出為 UTF-8；若你的終端機不是 UTF-8，`run_tests.sh` 會自動設 `LANG=en_US.UTF-8`。
> Windows 上的部署腳本同樣會把主控台切成 UTF-8（`chcp 65001`）並以 UTF-8 寫報告檔。

### 個別測試

```bash
php _dev/test_crypto.php    # 加解密標準向量 + 公式驗證（14 項）
php _dev/test_e2e.php       # 端對端：轉送→准入管制→確認→藍新→結果→回呼（含防偽，共 91 項）
```

> `test_e2e.php` 會在資料庫建立測試訂單、並實際送出回呼給 `demo` 站台，
> 請在**測試資料庫**上執行。若把 `config.php` 的 `NEWEB_GATEWAY_URL`／`NEWEB_QUERY_URL`
> 指向藍新正式或測試主機，「向藍新查詢」那一段會真的打過去（其餘流程都只在本機跑）。

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

## 十、安全注意事項與上線檢查

- [ ] `config.php` 加入金鑰與資料庫密碼後**不可從網站下載**（已附 `.htaccess`；nginx 請自行設 deny 規則）。
- [ ] 安裝後刪除 `install.php`、`_dev/`、`demo/`、`sql/`。
- [ ] `BASE_URL` 必須是藍新可連線的 HTTPS 公開網址；`ALLOW_HTTP` 正式環境必須為 0（僅測試用 1）。
- [ ] `pay_query.php` 已加密碼保護（`ADMIN_PASSWORD` 或 `ADMIN_KEY`），務必設定。
- [ ] 來源站台 `pay_sources.allow_hosts` 務必填（限制回呼網域，避免訂單資訊被外送）。
- [ ] 對外服務的來源站台務必設定 `allow_ips`（IP 白名單）；走瀏覽器導向的站台再加 `allow_origins`。
- [ ] 來源站台金鑰與藍新金鑰分開保管、不可寫進前端 JS 或公開檔。
- [ ] 通知端點需允許外站 POST（不要用 IP 白名單擋掉藍新 IP，也不要擋 query string 的 `src`）。
- [ ] 付款成功以 `NotifyURL`（幕後通知）為準；`ReturnURL` 只用於前台顯示，兩者不可合成同一支程式邏輯。
- [ ] 資料表 `pay_logs` / `pay_callbacks` 會成長，請依貴司個資法規設定保存期限並定期封存。
- [ ] 日誌中的卡號/token 欄位已遮蔽（`maskPost`），但仍建議設定 `LOG_RAW_POST = 0` 減少敏感資料落地。
---

## 十一、授權與安全聲明

- 程式碼以 **MIT** 授權釋出（見 `LICENSE`），可自由修改再用於商業專案。
- **金流實作務必自行審查後再上線**：本專案提供了簽章驗證、金額核對、重放防護與回呼記錄，
  但金流系統的風險由部署者自行承擔。請至少完成第八節的上線檢查清單。
- `config.php` 不在版控內，請以 `config.sample.php` 建立並填入**自己商店**的
  MerchantID / HashKey / HashIV 與資料庫密碼。
- 開源版 `config.sample.php` 中的金鑰為藍新技術手冊的公開範例值，
  `_dev/` 與 `demo/` 內的金鑰僅供測試，**不可用於正式收款**。
