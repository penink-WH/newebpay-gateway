<#
.SYNOPSIS
    藍新金流付款閘道 — Windows 一鍵自動部署（IIS / Apache 共用）

.DESCRIPTION
    依序完成：
      1. 檢查環境（PHP CLI、openssl/mysqli 擴充、MySQL/MariaDB 客戶端）
      2. 佈置程式檔案（自動排除 _dev/、demo/、install.php 等測試檔）
      3. 產生 config.php（含資料庫與藍新金鑰），並放到「網站根目錄之外」，
         網站根目錄只留一個 _config_path.php 指向它 → 金鑰不會被下載
      4. 寫入 web.config（IIS 保護：隱藏設定指標、禁止下載 .sql、no-store、nosniff）
      5. 建立資料庫／帳號／資料表（含舊版欄位遷移）
      6. 建立來源站台（子公司）設定與簽章金鑰
      7. 自我檢查：加解密標準向量（一定跑）＋本機端對端流程測試（-RunE2E）
      8. 輸出部署報告與後續動作清單（UTF-8 檔案）

    支援重複執行（冪等）：不會清空既有資料庫或資料表。

.PARAMETER WebRoot
    網站根目錄。預設 C:\inetpub\wwwroot\newebpay-gateway

.PARAMETER ConfigDir
    設定檔目錄（預設 C:\inetpub\newebpay-conf），刻意放在網站根目錄之外

.PARAMETER RunE2E
    額外在 WebRoot 起一個本機 PHP 伺服器跑 87 項端對端測試（約 30 秒）

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\deploy\windows-deploy.ps1 `
        -DbPass 'Db#12345' -HashKey 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' `
        -HashIv 'xxxxxxxxxxxxxxxx' -MerchantId 'MS12345678' `
        -BaseUrl 'https://pay.example.com.tw/gateway/' -AdminPassword 'my-admin-pwd'

.EXAMPLE
    .\deploy\windows-deploy.ps1 -Interactive

.NOTES
    本檔以 UTF-8（含 BOM）儲存，Windows PowerShell 5.1 才能正確顯示中文。
#>

[CmdletBinding()]
param(
    [string]$WebRoot = 'C:\inetpub\wwwroot\newebpay-gateway',
    [string]$ConfigDir = '',

    [string]$DbHost = '127.0.0.1',
    [int]$DbPort = 3306,
    [string]$DbName = 'newebpay_gateway',
    [string]$DbUser = 'newebpay',
    [string]$DbPass = '',
    [string]$DbRootUser = 'root',
    [string]$DbRootPass = '',
    [string]$MysqlExe = '',

    [ValidateSet('test', 'live')]
    [string]$NewebEnv = 'test',
    [string]$MerchantId = '',
    [string]$HashKey = '',
    [string]$HashIv = '',
    [string]$BaseUrl = '',
    [string]$AdminPassword = '',

    [string]$SourceCode = 'shop',
    [string]$SourceName = '公司購物網',
    [string]$SourceSecret = '',
    [string]$SourceCallbackUrl = '',
    [string]$SourceResultUrl = '',
    [string]$SourceAllowIps = '',
    [string]$SourceAllowOrigins = '',

    [string]$PhpExe = 'php',
    [int]$SelfTestPort = 8822,

    [switch]$Interactive,
    [switch]$SkipDb,
    [switch]$RunE2E,
    [switch]$DryRun,
    [string]$LogPath = ''
)

$ErrorActionPreference = 'Stop'

# 腳本路徑必須在腳本層級抓取（函式內的 $MyInvocation 指向函式本身，會是空的）
$script:ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path

# 暫存目錄（Windows 是 TEMP，其他系統是 TMPDIR / GetTempPath）
$script:TempDir = if ($env:TEMP) { $env:TEMP } elseif ($env:TMPDIR) { $env:TMPDIR } else { [System.IO.Path]::GetTempPath() }

# ------------------------------------------------------------------ UTF-8 輸出
try {
    [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
    $OutputEncoding = New-Object System.Text.UTF8Encoding($false)
} catch { }

$script:Report = New-Object System.Collections.Generic.List[string]
$script:Failed = 0

function Write-Step { param([string]$Message) Write-Host "▶ $Message" -ForegroundColor Cyan; $script:Report.Add("▶ $Message") }
function Write-Ok   { param([string]$Message) Write-Host "✔ $Message" -ForegroundColor Green;  $script:Report.Add("✔ $Message") }
function Write-Warn { param([string]$Message) Write-Host "⚠ $Message" -ForegroundColor Yellow; $script:Report.Add("⚠ $Message") }
function Write-Err  { param([string]$Message) Write-Host "✘ $Message" -ForegroundColor Red;    $script:Report.Add("✘ $Message"); $script:Failed++ }

function Read-Utf8File { param([string]$Path) return [System.IO.File]::ReadAllText($Path, [System.Text.Encoding]::UTF8) }

function Write-Utf8File {
    param([string]$Path, [string]$Content, [switch]$WithBom)
    [System.IO.File]::WriteAllText($Path, $Content, (New-Object System.Text.UTF8Encoding($WithBom.IsPresent)))
}

function Get-SourceDir { return (Split-Path -Parent $script:ScriptDir) }

function Ask {
    param([string]$Prompt, [string]$Default = '')
    $label = if ($Default) { "$Prompt [$Default]" } else { $Prompt }
    $val = Read-Host -Prompt $label
    if ([string]::IsNullOrWhiteSpace($val)) { return $Default }
    return $val.Trim()
}

function Test-HasBom {
    param([string]$Path)
    $bytes = [System.IO.File]::ReadAllBytes($Path)
    if ($bytes.Length -lt 3) { return $false }
    return ($bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
}

# ------------------------------------------------------------------ 0. 參數補齊
$src = Get-SourceDir
Write-Host ''
Write-Host '============================================================' -ForegroundColor Cyan
Write-Host '  藍新金流付款閘道 — Windows 自動部署' -ForegroundColor Cyan
Write-Host "  來源程式：$src" -ForegroundColor DarkGray
Write-Host '============================================================' -ForegroundColor Cyan

if ($Interactive) {
    Write-Step '互動模式（直接按 Enter 使用預設值）'
    $WebRoot = Ask '網站根目錄' $WebRoot
    $DbName = Ask '資料庫名稱' $DbName
    $DbUser = Ask '資料庫帳號' $DbUser
    $DbPass = Ask '資料庫密碼（輸入時不會顯示）' $DbPass
    $NewebEnv = Ask '藍新環境 test / live' $NewebEnv
    $MerchantId = Ask '藍新商店代號 MerchantID' $MerchantId
    $HashKey = Ask '藍新 HashKey（32 碼）' $HashKey
    $HashIv = Ask '藍新 HashIV（16 碼）' $HashIv
    $BaseUrl = Ask 'BASE_URL（例 https://pay.example.com.tw/gateway/）' $BaseUrl
    $AdminPassword = Ask '後台管理密碼' $AdminPassword
}

foreach ($pair in @(@('MerchantId', $MerchantId), @('HashKey', $HashKey), @('HashIv', $HashIv), @('AdminPassword', $AdminPassword), @('BaseUrl', $BaseUrl))) {
    if ([string]::IsNullOrWhiteSpace($pair[1])) { Write-Err "未提供必要參數：-$($pair[0])" }
}
if ($HashKey.Length -ne 32) { Write-Warn "HashKey 長度應為 32，目前 $($HashKey.Length) 碼" }
if ($HashIv.Length -ne 16) { Write-Warn "HashIV 長度應為 16，目前 $($HashIv.Length) 碼" }
if ($script:Failed -gt 0) {
    Write-Host ''
    Write-Err '必要參數不足，部署中止。可加 -Interactive 互動輸入。'
    exit 2
}

if ([string]::IsNullOrWhiteSpace($ConfigDir)) {
    $ConfigDir = 'C:\inetpub\newebpay-conf'
}
if ([string]::IsNullOrWhiteSpace($SourceSecret)) {
    $chars = 'abcdefghijkmnpqrstuvwxyz23456789'
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()   # PS 5.1 / 7 通用
    $bytes = New-Object byte[] 32
    $rng.GetBytes($bytes)
    $rng.Dispose()
    $sb = New-Object System.Text.StringBuilder
    foreach ($b in $bytes) { [void]$sb.Append($chars[$b % $chars.Length]) }
    $SourceSecret = $sb.ToString()
}
if (-not $BaseUrl.EndsWith('/')) { $BaseUrl = $BaseUrl + '/' }

$dry = [bool]$DryRun
$configPath = Join-Path $ConfigDir 'config.php'

# ------------------------------------------------------------------ 1. 環境檢查
Write-Step '檢查環境'
$phpOk = $false
$phpVer = ''
try {
    $phpVer = (& $PhpExe -r 'echo PHP_VERSION;' 2>$null)
    if ($LASTEXITCODE -eq 0 -and $phpVer) { $phpOk = $true; Write-Ok "PHP $phpVer（$PhpExe）" }
} catch { }
if (-not $phpOk) {
    Write-Err "找不到可用的 PHP CLI（$PhpExe）。請安裝 PHP 7.4 或 8.x 並加入 PATH"
}

if ($phpOk) {
    $exts = (& $PhpExe -r 'echo implode(",", get_loaded_extensions());' 2>$null) -split ','
    $missing = @()
    foreach ($n in @('openssl', 'mysqli')) { if ($exts -notcontains $n) { $missing += $n } }
    if ($missing.Count -eq 0) {
        $hasCurl = ($exts -contains 'curl')
        Write-Ok ("必要擴充齊全（openssl, mysqli" + $(if ($hasCurl) { ', curl' } else { '；未裝 curl，回呼會改用內建 HTTP，建議補上' }) + '）')
    } else {
        Write-Err ("缺少 PHP 擴充：" + ($missing -join ', ') + '，請在 php.ini 開啟後重啟服務')
    }
}

$mysql = $MysqlExe
if ([string]::IsNullOrWhiteSpace($mysql)) {
    $cmd = Get-Command mysql.exe -ErrorAction SilentlyContinue
    if ($cmd) { $mysql = $cmd.Source }
}
if ([string]::IsNullOrWhiteSpace($mysql)) {
    foreach ($base in @("$env:ProgramFiles\MySQL", "${env:ProgramFiles(x86)}\MySQL", "$env:ProgramFiles\MariaDB 11.4", "$env:ProgramFiles\MariaDB 10.11")) {
        if ($base -and (Test-Path $base)) {
            $hit = Get-ChildItem -Path $base -Recurse -Filter 'mysql.exe' -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($hit) { $mysql = $hit.FullName; break }
        }
    }
}
if (-not [string]::IsNullOrWhiteSpace($mysql)) { Write-Ok "MySQL 客戶端：$mysql" }
elseif (-not $SkipDb) { Write-Warn '找不到 mysql.exe，可帶 -MysqlExe 指定，或自行建立資料庫後用 -SkipDb' }

# ------------------------------------------------------------------ 2. 佈置檔案
Write-Step "佈置程式檔案到 $WebRoot"
if (-not $dry) {
    if (-not (Test-Path $WebRoot)) { New-Item -ItemType Directory -Path $WebRoot -Force | Out-Null }
    if (-not (Test-Path $ConfigDir)) { New-Item -ItemType Directory -Path $ConfigDir -Force | Out-Null }

    $skipDirs = @('.git', 'demo', '_dev', 'sql', 'deploy')
    $skipFiles = @('config.php', '_config_path.php', 'install.php', '作品介紹.txt')
    foreach ($item in (Get-ChildItem -Path $src -Force)) {
        if ($item.PSIsContainer) {
            if ($skipDirs -notcontains $item.Name) {
                Copy-Item -Path $item.FullName -Destination (Join-Path $WebRoot $item.Name) -Recurse -Force
            }
        } elseif (($skipFiles -notcontains $item.Name) -and ($item.Name -notlike '*.md') -and ($item.Name -ne 'LICENSE')) {
            Copy-Item -Path $item.FullName -Destination (Join-Path $WebRoot $item.Name) -Force
        }
    }
    # 確保測試檔不在正式目錄（舊版部署可能留著）
    foreach ($d in @('_dev', 'demo', 'sql', 'deploy', '.git')) {
        $p = Join-Path $WebRoot $d
        if (Test-Path $p) { Remove-Item -Path $p -Recurse -Force }
    }
    Write-Ok '檔案已複製（已排除 _dev/、demo/、sql/、deploy/、install.php、config.php）'
} else {
    Write-Ok "（DryRun）將複製到：$WebRoot"
}

# ------------------------------------------------------------------ 3. 產生設定檔
Write-Step '產生設定檔（放在網站根目錄之外，含金鑰者不落地在網站目錄）'
if (-not $dry) {
    $tpl = Read-Utf8File (Join-Path $src 'config.sample.php')
    $map = [ordered]@{
        'DB_HOST'           = $DbHost
        'DB_PORT'           = [string]$DbPort
        'DB_USER'           = $DbUser
        'DB_PASS'           = $DbPass
        'DB_NAME'           = $DbName
        'NEWEB_ENV'         = $NewebEnv
        'NEWEB_MERCHANT_ID' = $MerchantId
        'NEWEB_HASH_KEY'    = $HashKey
        'NEWEB_HASH_IV'     = $HashIv
        'BASE_URL'          = $BaseUrl
        'ADMIN_PASSWORD'    = $AdminPassword
        'ALLOW_HTTP'        = '0'
    }
    foreach ($k in $map.Keys) {
        # 範本裡的 define 有帶引號（字串）與沒帶引號（數字，如 DB_PORT、ALLOW_HTTP）兩種寫法
        $pattern = "(?m)^define\('$k',\s*('[^']*'|[^)]*)\);"
        $value = "'" + $map[$k].Replace("'", "\'") + "'"
        $tpl = [regex]::Replace($tpl, $pattern, "define('$k', $value);")
    }
    $tpl = [regex]::Replace($tpl, "(?m)^\s*//\s*define\('NEWEB_(GATEWAY|QUERY)_URL'.*\r?\n", '')

    Write-Utf8File -Path $configPath -Content $tpl    # 無 BOM：BOM 會讓 PHP 在輸出前吐字元，破壞標頭
    $mapPhp = "<?php`r`n// 由部署腳本產生：讓程式讀取網站根目錄之外的設定檔`r`ndefine('NW_CONFIG_FILE', '"
    $mapPhp += $configPath.Replace('\', '\\') + "');`r`n"
    Write-Utf8File -Path (Join-Path $WebRoot '_config_path.php') -Content $mapPhp

    Write-Ok "設定檔：$configPath"
    Write-Ok "指向檔：$(Join-Path $WebRoot '_config_path.php')"

    # 檢查：設定檔沒有 BOM 且 PHP 可解析
    if (Test-HasBom $configPath) { Write-Err '設定檔含 BOM，會破壞 HTTP 標頭，已中止' ; exit 3 }
    $lint = & $PhpExe -l $configPath 2>&1
    if ($LASTEXITCODE -eq 0) { Write-Ok '設定檔語法檢查通過（無 BOM）' }
    else { Write-Err ('設定檔語法錯誤：' + ($lint -join ' ')) }
} else {
    Write-Ok "（DryRun）將寫入：$configPath"
}

# ------------------------------------------------------------------ 4. web.config
if (-not $dry) {
    $webConfig = @'
<?xml version="1.0" encoding="utf-8"?>
<configuration>
  <system.webServer>
    <defaultDocument>
      <files><clear /><add value="pay_request.php" /></files>
    </defaultDocument>
    <security>
      <requestFiltering>
        <hiddenSegments>
          <clear />
          <add segment="bin" />
          <add segment="App_Data" />
          <add segment="includes" />
          <add segment="deploy" />
          <add segment="_config_path.php" />
        </hiddenSegments>
        <fileExtensions allowUnlisted="true">
          <add fileExtension=".sql" allowed="false" />
          <add fileExtension=".log" allowed="false" />
          <add fileExtension=".md" allowed="false" />
        </fileExtensions>
      </requestFiltering>
    </security>
    <httpProtocol>
      <customHeaders>
        <add name="X-Content-Type-Options" value="nosniff" />
        <add name="Cache-Control" value="no-store" />
      </customHeaders>
    </httpProtocol>
    <handlers>
      <clear />
      <add name="PHP_via_FastCGI" path="*.php" verb="GET,POST" modules="FastCgiModule" resourceType="Unspecified" requireAccess="Script" />
    </handlers>
  </system.webServer>
</configuration>
'@
    Write-Utf8File -Path (Join-Path $WebRoot 'web.config') -Content $webConfig
    Write-Ok '已寫入 web.config（隱藏設定指標與 includes/、禁止下載 .sql/.log/.md、no-store、nosniff）'
    Write-Warn ("請確認 IIS 已安裝 PHP Handler，且網站根目錄指向 " + $WebRoot)
}

# ------------------------------------------------------------------ 5. 資料庫
if ($SkipDb) {
    Write-Warn '已指定 -SkipDb，跳過資料庫建立（請自行確認資料表已存在）'
} elseif (-not $dry -and [string]::IsNullOrWhiteSpace($mysql)) {
    Write-Warn '找不到 mysql.exe，跳過資料庫建立'
} elseif (-not $dry) {
    Write-Step '建立資料庫與帳號'
    $myArgs = @("-h$DbHost", "-P$DbPort", "-u$DbRootUser")
    if ($DbRootPass) { $myArgs += "-p$DbRootPass" }
    $myArgs += '--default-character-set=utf8mb4'
    $sql = "CREATE DATABASE IF NOT EXISTS ``$DbName`` DEFAULT CHARACTER SET utf8mb4;" +
           "CREATE USER IF NOT EXISTS '$DbUser'@'%' IDENTIFIED BY '$($DbPass.Replace("'", "\'"))';" +
           "ALTER USER '$DbUser'@'%' IDENTIFIED BY '$($DbPass.Replace("'", "\'"))';" +
           "GRANT ALL PRIVILEGES ON ``$DbName``.* TO '$DbUser'@'%';" +
           "FLUSH PRIVILEGES;"
    $out = & $mysql @($myArgs + @('-e', $sql)) 2>&1
    if ($LASTEXITCODE -eq 0) { Write-Ok "資料庫 $DbName 與帳號 $DbUser 已就緒" }
    else { Write-Warn ('建立資料庫時有訊息（可能是權限不足，請確認 -DbRootPass）：' + (($out | Out-String).Trim())) }
}

if ($phpOk -and -not $SkipDb -and -not $dry) {
    Write-Step '建立資料表 / 欄位遷移'
    $env:NW_CONFIG_FILE = $configPath
    $out = & $PhpExe (Join-Path $src 'install.php') 2>&1
    if ($LASTEXITCODE -eq 0) { Write-Ok ('資料表就緒：' + (($out | Select-Object -First 2) -join ' / ')) }
    else { Write-Err ('install.php 執行失敗：' + (($out | Out-String).Trim())) }
}

# ------------------------------------------------------------------ 6. 來源站台
if ($phpOk -and -not $dry) {
    Write-Step '建立來源站台（子公司）設定'
    $seedTpl = @'
<?php
require_once('__WEBROOT__/includes/common.php');
$code = '__CODE__';
$existing = PayGateway::getSource($code);
$data = array(
    'source_code'   => $code,
    'source_name'   => '__NAME__',
    'callback_url'  => '__CB__',
    'result_url'    => '__RS__',
    'secret'        => '__SECRET__',
    'allow_ips'     => '__IPS__',
    'allow_origins' => '__ORIGINS__',
    'allow_hosts'   => '',
    'is_active'     => 1,
    'remark'        => '部署腳本建立',
    'created_at'    => nw_now(),
    'updated_at'    => nw_now(),
);
if ($existing) {
    unset($data['source_code']);
    DB::update('pay_sources', $data, array('source_code' => $code));
    echo 'updated';
} else {
    DB::insert('pay_sources', $data);
    echo 'created';
}
'@
    $q = { param($s) $s.Replace("'", "\'") }
    $seed = $seedTpl.
        Replace('__CODE__', $SourceCode).
        Replace('__NAME__', (& $q $SourceName)).
        Replace('__CB__', (& $q $SourceCallbackUrl)).
        Replace('__RS__', (& $q $SourceResultUrl)).
        Replace('__SECRET__', (& $q $SourceSecret)).
        Replace('__IPS__', (& $q $SourceAllowIps)).
        Replace('__ORIGINS__', (& $q $SourceAllowOrigins))
    $seed = $seed.Replace('__WEBROOT__', $WebRoot.Replace('\', '/'))
    $seedFile = Join-Path $ConfigDir 'seed_source.php'
    Write-Utf8File -Path $seedFile -Content $seed
    $env:NW_CONFIG_FILE = $configPath
    $out = & $PhpExe $seedFile 2>&1
    if ($LASTEXITCODE -eq 0 -and ($out -join '') -match 'created|updated') {
        Write-Ok ("來源站台 $SourceCode 已" + ($out -join '') + "（簽章金鑰：$SourceSecret）")
    } else {
        Write-Warn ('建立來源站台失敗：' + (($out | Out-String).Trim()))
    }
    Remove-Item $seedFile -Force -ErrorAction SilentlyContinue
}

# ------------------------------------------------------------------ 7. 自我檢查
$serverProc = $null
try {
    if ($phpOk -and -not $dry) {
        Write-Step '自我檢查 1/2：藍新加解密標準向量'
        # 暫時把 _dev/ 與 demo/ 複製進 WebRoot，用「已部署的那份」測試；結束後移除
        # （demo/ 是測試用的模擬來源站台，正式機不需要）
        Copy-Item -Path (Join-Path $src '_dev') -Destination (Join-Path $WebRoot '_dev') -Recurse -Force
        Copy-Item -Path (Join-Path $src 'demo') -Destination (Join-Path $WebRoot 'demo') -Recurse -Force
        $env:NW_CONFIG_FILE = $configPath
        $out = & $PhpExe (Join-Path $WebRoot '_dev/test_crypto.php') 2>&1
        if ($LASTEXITCODE -eq 0) { Write-Ok (($out | Select-Object -Last 1) -join '') }
        else { Write-Err ('加解密測試未通過：' + (($out | Out-String).Trim())) }

        if ($RunE2E -and $env:OS -eq 'Windows_NT') {
            Write-Warn 'Windows 的 PHP 內建伺服器為單一執行緒，無法在本機自我呼叫回呼（會阻塞），因此略過端對端測試'
            Write-Warn '建議：正式部署到 IIS 後，依 README 的驗收清單手動點一次完整流程（來源站台 → 確認付款 → 藍新測試卡 → 後台查結果）'
        } elseif ($RunE2E) {
            $env:PHP_CLI_SERVER_WORKERS = '8'   # 多執行緒，模擬藍新回呼自己才不會阻塞（僅 POSIX 支援）
            Write-Step "自我檢查 2/2：端對端流程測試（本機 $SelfTestPort 埠，約 30 秒）"
            # 測試用設定：沿用部署設定，但把 BASE_URL 指向本機、允許 http 回呼，並接上模擬藍新閘道
            # 注意：必須「取代」既有的 define，不能重複定義（PHP 先定義者為準）
            $testCfg = Read-Utf8File $configPath
            $testCfg = [regex]::Replace($testCfg, "(?m)^define\('BASE_URL',\s*'[^']*'\);", "define('BASE_URL', 'http://127.0.0.1:$SelfTestPort/');")
            $testCfg = [regex]::Replace($testCfg, "(?m)^define\('ALLOW_HTTP',\s*[^)]*\);", "define('ALLOW_HTTP', 1);")
            $testCfg = $testCfg + "`r`n" +
                "define('NEWEB_GATEWAY_URL', 'http://127.0.0.1:$SelfTestPort/_dev/mock_newebpay_gateway.php?act=mpg');" + "`r`n" +
                "define('NEWEB_QUERY_URL', 'http://127.0.0.1:$SelfTestPort/_dev/mock_newebpay_gateway.php?act=query');" + "`r`n"
            $testCfgPath = Join-Path $ConfigDir 'config_selftest.php'
            Write-Utf8File -Path $testCfgPath -Content $testCfg
            $env:NW_CONFIG_FILE = $testCfgPath
            $env:NW_TEST_BASE_URL = "http://127.0.0.1:$SelfTestPort"

            # 把測試用 demo 站台的回呼網址指向本機（測完後還原），避免測到正式網址而逾時
            $tplFile = Join-Path $ConfigDir 'tmp_selftest_source.php'
            $tplPhp = @'
<?php
require_once('__WEBROOT__/includes/common.php');
$prev = PayGateway::getSource('demo');
$file = __DIR__ . '/prev_demo_source.json';
if (isset($argv[1]) && $argv[1] === 'restore') {
    if (file_exists($file)) {
        $prev = json_decode(file_get_contents($file), true);
        if ($prev) { DB::update('pay_sources', $prev, array('source_code' => 'demo')); }
        unlink($file);
    }
    echo 'restored';
    exit;
}
if ($prev) { file_put_contents($file, json_encode($prev)); }
DB::update('pay_sources', array(
    'callback_url' => '__BASE__/demo/callback_receiver.php',
    'result_url'   => '__BASE__/demo/callback_receiver.php?type=result',
    'updated_at'   => nw_now(),
), array('source_code' => 'demo'));
echo 'set';
'@
            $tplPhp = $tplPhp.Replace('__WEBROOT__', $WebRoot.Replace('\', '/')).Replace('__BASE__', "http://127.0.0.1:$SelfTestPort")
            Write-Utf8File -Path $tplFile -Content $tplPhp
            $null = & $PhpExe $tplFile 2>&1

            $serverProc = Start-Process -FilePath $PhpExe `
                -ArgumentList @('-S', "127.0.0.1:$SelfTestPort", '-t', $WebRoot) `
                -PassThru `
                -RedirectStandardOutput (Join-Path $script:TempDir 'nw_selftest_php.log') `
                -RedirectStandardError (Join-Path $script:TempDir 'nw_selftest_php_err.log')
            $ready = $false
            for ($i = 0; $i -lt 20; $i++) {
                Start-Sleep -Milliseconds 500
                try {
                    $null = Invoke-WebRequest -Uri "http://127.0.0.1:$SelfTestPort/pay_request.php" -UseBasicParsing -TimeoutSec 3
                    $ready = $true; break
                } catch { }
            }
            if (-not $ready) {
                Write-Err '測試用 PHP 伺服器沒有啟動，請檢查是否已被占用'
            } else {
                $out = & $PhpExe (Join-Path $WebRoot '_dev/test_e2e.php') 2>&1
                if ($LASTEXITCODE -eq 0) { Write-Ok (($out | Select-Object -Last 2) -join ' ') }
                else { Write-Err ('端對端測試未通過：' + (($out | Select-String 'FAIL' | Select-Object -First 5) -join ' / ')) }
            }
            # 還原 demo 站台設定並清除暫存檔
            $null = & $PhpExe $tplFile 'restore' 2>&1
            Remove-Item $tplFile -Force -ErrorAction SilentlyContinue
            Remove-Item $testCfgPath -Force -ErrorAction SilentlyContinue
            $env:NW_CONFIG_FILE = $configPath
            $env:NW_TEST_BASE_URL = $null
        } else {
            Write-Warn '未加 -RunE2E，已跳過端對端測試（確定要跑請加 -RunE2E）'
        }
    }
} finally {
    if ($serverProc) { try { Stop-Process -Id $serverProc.Id -Force -ErrorAction SilentlyContinue } catch { } }
    foreach ($d in @('_dev', 'demo')) {
        $p = Join-Path $WebRoot $d
        if (Test-Path $p) { Remove-Item -Path $p -Recurse -Force -ErrorAction SilentlyContinue }
    }
    foreach ($f in @('nw_selftest_php.log', 'nw_selftest_php_err.log')) {
        $p = Join-Path $script:TempDir $f
        if (Test-Path $p) { Remove-Item $p -Force -ErrorAction SilentlyContinue }
    }
}

# ------------------------------------------------------------------ 8. 報告
Write-Host ''
Write-Host '============================================================' -ForegroundColor Cyan
if ($script:Failed -eq 0) { Write-Host '  部署完成，檢查項目全數通過' -ForegroundColor Green }
else { Write-Host "  部署完成，但有 $($script:Failed) 項錯誤，請檢視上方 ✘ 行" -ForegroundColor Yellow }
Write-Host '============================================================' -ForegroundColor Cyan
Write-Host ''
Write-Host '後續動作' -ForegroundColor Cyan
Write-Host "  1. 網站根目錄        : $WebRoot"
Write-Host "  2. 設定檔（根目錄外）: $configPath"
Write-Host "  3. 線上測試網址      : $BaseUrl"
Write-Host "  4. 交易查詢後台      : $($BaseUrl)pay_query.php          （密碼：你設定的 -AdminPassword）"
Write-Host "  5. 子公司站台設定    : $($BaseUrl)pay_sources_admin.php"
Write-Host '  6. 藍新後台要填的三個回呼網址：'
Write-Host "       $($BaseUrl)pay_notify.php?src=notify     幕後通知（以此為準）"
Write-Host "       $($BaseUrl)pay_notify.php?src=return     前台返回"
Write-Host "       $($BaseUrl)pay_notify.php?src=customer   ATM／超商取號"
Write-Host "  7. 來源站台簽章金鑰  : $SourceSecret"
Write-Host '       （請以 https 或密碼管理工具私下給該站台，勿貼在公開文件）'
Write-Host ''
Write-Host '上線前務必確認' -ForegroundColor Yellow
Write-Host "  · 正式收款前把 config.php 的 NEWEB_ENV 改成 live 並換成正式商店參數"
Write-Host '  · 對外服務的來源站台，請到 pay_sources_admin.php 設定「允許連入的 IP」'
Write-Host '  · pay_query.php / pay_sources_admin.php 請務必改掉預設管理密碼'
Write-Host '  · 確認防火牆已開放 443，並檢查 SSL 憑證'
Write-Host '  · 確認正式機不需要 _dev/、demo/、install.php（腳本已自動排除）'

$logDir = if ([string]::IsNullOrWhiteSpace($LogPath)) { $ConfigDir } else { Split-Path -Parent $LogPath }
if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
$logFile = if ([string]::IsNullOrWhiteSpace($LogPath)) { Join-Path $ConfigDir ('deploy-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log') } else { $LogPath }
Write-Utf8File -Path $logFile -Content (($script:Report -join "`r`n") + "`r`n")
Write-Host "部署紀錄（UTF-8）：$logFile" -ForegroundColor DarkGray

if ($script:Failed -eq 0) { exit 0 } else { exit 1 }