<?php
/**
 * _dev/test_crypto.php - 藍新加解密標準向量測試
 *
 * 驗證重點：
 *  1. 以藍新官方手冊的範例金鑰與請求字串加密，前 16 bytes 是否等於文件中的值
 *     （AES 第一個 block 只受前 16 bytes 明文影響，所以此比對足以驗證金鑰/IV/cipher 選項）
 *  2. 加密 → 解密 round-trip（query string 與 JSON 兩種格式）
 *  3. TradeSha 驗證、CheckValue、CheckCode
 *  4. mcrypt 與 openssl 後端產出是否一致（PHP 5.2 環境驗證用）
 *
 * 執行：php _dev/test_crypto.php
 */

require_once(dirname(__FILE__) . '/../includes/common.php');

$pass = 0;
$fail = 0;

function check($name, $cond, $info)
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] {$name}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$name}  → {$info}\n";
    }
}

echo "=== 藍新加解密測試 (backend=" . (function_exists('openssl_encrypt') ? 'openssl' : 'mcrypt') . ") ===\n";

// 官方手冊範例：https://www.newebpay.com 手冊 4.1.1 節
$key = 'Fs5cX1TGqYM2PpdbE14a9H83YQSQF5jn';
$iv = 'C6AcmfqJILwgnhIP';
// 官方文件中的加密結果（PDF 只列出前 16 bytes），故以「前綴」比對
$expectPrefix = 'f79eac33c4f3245d58f17b544c5d38';
$sampleQs = 'MerchantID=MS127874575&RespondType=String&TimeStamp=1695795410&Version=2.0'
    . '&MerchantOrderNo=Vanespl_ec_1695795410&Amt=30&ItemDesc=test'
    . '&NotifyURL=https%3A%2F%2Fwebhook.site%2Fd4db5ad1-2278-466a-9d66-78585c0dbadb';

echo "-- 官方範例金鑰 --\n";
foreach (array(32, 16) as $pad) {
    $c = new NewebPayCrypto($key, $iv, $pad);
    $hex = $c->aesEncrypt($sampleQs);
    check("AES-256-CBC 加密前 16 bytes 符合官方文件（pad={$pad}）",
        strpos($hex, $expectPrefix) === 0, "got=" . substr($hex, 0, 32) . " want 前綴=" . $expectPrefix);
}

echo "-- TradeSha --\n";
$c = new NewebPayCrypto(NEWEB_HASH_KEY, NEWEB_HASH_IV, NEWEB_PAD_BLOCK);
$sha = $c->makeTradeSha('abc123');
check('TradeSha 為 64 碼大寫 hex', preg_match('/^[0-9A-F]{64}$/', $sha) === 1, $sha);
check('TradeSha 驗證通過', $c->verifyTradeSha('abc123', $sha), '');
check('TradeSha 驗證抓到竄改', !$c->verifyTradeSha('abc124', $sha), '');
$manual = strtoupper(hash('sha256', 'HashKey=' . NEWEB_HASH_KEY . '&abc123&HashIV=' . NEWEB_HASH_IV));
check('TradeSha 公式正確', $sha === $manual, $sha . ' vs ' . $manual);

echo "-- round-trip --\n";
$params = array(
    'MerchantID' => 'MS127874575',
    'RespondType' => 'JSON',
    'TimeStamp' => time(),
    'Version' => '2.3',
    'MerchantOrderNo' => 'NW260615TEST0001',
    'Amt' => 1234,
    'ItemDesc' => '測試商品 & 特殊字元=+/%',
    'NotifyURL' => 'https://pay.example.com.tw/pay_notify.php?src=notify',
    'CREDIT' => '1',
    'VACC' => '1',
);
$ti = $c->encryptParams($params);
$back = $c->decryptParams($ti);
$same = true;
foreach ($params as $k => $v) {
    if (!isset($back[$k]) || (string)$back[$k] !== (string)$v) {
        $same = false;
        echo "    差異 {$k}: " . (isset($back[$k]) ? $back[$k] : '(無)') . " != {$v}\n";
    }
}
check('query string 加密→解密 round-trip', $same, '');
check('TradeInfo 為 hex 字串', preg_match('/^[0-9a-f]+$/', $ti) === 1, substr($ti, 0, 40));

echo "-- JSON 回傳格式 --\n";
$jsonPlain = json_encode(array('Status' => 'SUCCESS', 'Amt' => 100, 'MerchantOrderNo' => 'A1', '中文' => '測試'));
$tiJson = $c->aesEncrypt($jsonPlain);
$decoded = $c->decryptParams($tiJson);
check('JSON 明文解密後可解析', is_array($decoded) && $decoded['MerchantOrderNo'] === 'A1' && $decoded['中文'] === '測試', print_r($decoded, true));

echo "-- 長字串 / 多 block 補齊 --\n";
$long = array();
for ($i = 0; $i < 40; $i++) {
    $long['f' . $i] = '值' . $i . str_repeat('x', 20);
}
$rt = $c->decryptParams($c->encryptParams($long));
check('40 個欄位 round-trip', count($rt) === 40 && $rt['f39'] === $long['f39'], '');

echo "-- CheckValue / CheckCode --\n";
$cv = NewebPayCrypto::makeCheckValue(1000, 'MS127874575', 'NW260615TEST0001', $key, $iv);
$cvManual = strtoupper(hash('sha256', 'IV=' . $iv . '&Amt=1000&MerchantID=MS127874575&MerchantOrderNo=NW260615TEST0001&Key=' . $key));
check('CheckValue 公式正確', $cv === $cvManual, $cv);
$res = array('Amt' => '1000', 'MerchantID' => 'MS127874575', 'MerchantOrderNo' => 'NW260615TEST0001', 'TradeNo' => 'T123');
$cc = NewebPayCrypto::makeCheckCode($res, $key, $iv);
$ccManual = strtoupper(hash('sha256', 'HashIV=' . $iv . '&Amt=1000&MerchantID=MS127874575&MerchantOrderNo=NW260615TEST0001&TradeNo=T123&HashKey=' . $key));
check('CheckCode 公式正確', $cc === $ccManual, $cc);

echo "-- 錯誤處理 --\n";
$threw = false;
try {
    $c->aesDecrypt('zz');
} catch (Exception $e) {
    $threw = true;
}
check('非法 hex 會丟例外', $threw, '');
$threw2 = false;
try {
    $bad = new NewebPayCrypto('wrong-key-wrong-key-wrong-key12', NEWEB_HASH_IV, NEWEB_PAD_BLOCK);
    $bad->decryptParams($ti);
} catch (Exception $e) {
    $threw2 = true;
}
check('錯誤金鑰解密會失敗', $threw2, '');

echo "-- mcrypt / openssl 產出一致性（PHP 5.2 相容性驗證） --\n";
if (function_exists('mcrypt_encrypt')) {
    $mc = new NewebPayCrypto($key, $iv, 32);
    $mc->backend = 'mcrypt';
    $os = new NewebPayCrypto($key, $iv, 32);
    check('mcrypt 與 openssl 加密結果相同', $mc->aesEncrypt($sampleQs) === $os->aesEncrypt($sampleQs), '');
} else {
    echo "  [SKIP] 本機未安裝 mcrypt（本機為 PHP 8.x，PHP 5.2 才需要）\n";
}

echo "\n結果：{$pass} 通過 / {$fail} 失敗\n";
exit($fail === 0 ? 0 : 1);