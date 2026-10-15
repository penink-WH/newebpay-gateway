<?php
/**
 * NewebPayCrypto.php - 藍新金流加解密
 *
 * 演算法（規格書 NDNF 4.1）：
 *   加密：參數 → urlencoded query string → AES-256-CBC(HashKey, HashIV) → bin2hex
 *         藍新官方外掛是以 32 bytes 補齊（非標準 PKCS#7 的 16），故預設沿用 32。
 *   驗證：strtoupper(sha256("HashKey={key}&{TradeInfo}&HashIV={iv}"))
 *   解密：hex2bin → AES-256-CBC → 依最後一個 byte 去除補齊（容許 1~32）
 *
 * PHP 版本差異處理：
 *   - PHP >= 5.3：openssl_*（建議）
 *   - PHP 5.2：mcrypt（MCRYPT_RIJNDAEL_128 + 256-bit key 即 AES-256；注意不可用
 *               MCRYPT_RIJNDAEL_256，那是 32-byte block 的 Rijndael，不是 AES）
 */

class NewebPayCrypto
{
    /** @var string */
    var $hashKey;
    /** @var string */
    var $hashIV;
    /** @var int */
    var $padBlock;
    /** @var string */
    var $backend;

    function __construct($hashKey, $hashIV, $padBlock)
    {
        $this->hashKey = $hashKey;
        $this->hashIV = $hashIV;
        $this->padBlock = $padBlock ? $padBlock : 32;
        if (function_exists('openssl_encrypt')) {
            $this->backend = 'openssl';
        } elseif (function_exists('mcrypt_encrypt')) {
            $this->backend = 'mcrypt';
        } else {
            $this->backend = 'none';
        }
    }

    /**
     * 產生 query string（固定用 RFC1738 urlencode + & 連接，避免 server 的
     * arg_separator.output 設定造成與藍新不一致）
     * @param array $params
     * @return string
     */
    static function buildQuery($params)
    {
        $parts = array();
        foreach ($params as $k => $v) {
            if ($v === null) {
                continue;
            }
            $parts[] = urlencode($k) . '=' . urlencode((string)$v);
        }
        return implode('&', $parts);
    }

    /** @return array urldecode 後的參數 */
    static function parseQuery($qs)
    {
        $out = array();
        if ($qs === '' || $qs === null) {
            return $out;
        }
        $pairs = explode('&', $qs);
        foreach ($pairs as $p) {
            if ($p === '') {
                continue;
            }
            $pos = strpos($p, '=');
            if ($pos === false) {
                $out[urldecode($p)] = '';
            } else {
                $out[urldecode(substr($p, 0, $pos))] = urldecode(substr($p, $pos + 1));
            }
        }
        return $out;
    }

    /**
     * 加密參數陣列 → TradeInfo(hex)
     * @return string
     */
    function encryptParams($params)
    {
        return $this->aesEncrypt(self::buildQuery($params));
    }

    /**
     * 解密 TradeInfo(hex) → 參數陣列
     * 回傳格式若為 JSON（RespondType=JSON）會自動解析成陣列
     * @return array
     */
    function decryptParams($tradeInfo)
    {
        $plain = $this->aesDecrypt($tradeInfo);
        $decoded = json_decode($plain, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return self::parseQuery($plain);
    }

    function aesEncrypt($plain)
    {
        $block = $this->padBlock;
        $pad = $block - (strlen($plain) % $block);
        $data = $plain . str_repeat(chr($pad), $pad);

        if ($this->backend === 'openssl') {
            $out = openssl_encrypt($data, 'AES-256-CBC', $this->hashKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->hashIV);
            if ($out === false) {
                throw new Exception('openssl_encrypt failed');
            }
            return trim(bin2hex($out));
        }
        if ($this->backend === 'mcrypt') {
            $out = mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $data, $this->hashKey, MCRYPT_MODE_CBC, $this->hashIV);
            if ($out === false) {
                throw new Exception('mcrypt_encrypt failed');
            }
            return trim(bin2hex($out));
        }
        throw new Exception('No AES backend: 請安裝 openssl（PHP 5.3+）或 mcrypt 擴充功能');
    }

    function aesDecrypt($hex)
    {
        $hex = trim((string)$hex);
        if ($hex === '' || (strlen($hex) % 2) !== 0 || !preg_match('/^[0-9a-fA-F]+$/', $hex)) {
            throw new Exception('TradeInfo 格式錯誤（需為 hex 字串）');
        }
        $bin = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $bin .= chr(hexdec(substr($hex, $i, 2)));
        }
        if ($this->backend === 'openssl') {
            $out = openssl_decrypt($bin, 'AES-256-CBC', $this->hashKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->hashIV);
            if ($out === false) {
                throw new Exception('openssl_decrypt failed（HashKey / HashIV 是否正確？）');
            }
        } elseif ($this->backend === 'mcrypt') {
            $out = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $bin, $this->hashKey, MCRYPT_MODE_CBC, $this->hashIV);
            if ($out === false || $out === '') {
                throw new Exception('mcrypt_decrypt failed（HashKey / HashIV 是否正確？）');
            }
        } else {
            throw new Exception('No AES backend');
        }
        return $this->stripPadding($out);
    }

    function stripPadding($s)
    {
        if ($s === '') {
            return $s;
        }
        $pad = ord(substr($s, -1));
        if ($pad < 1 || $pad > 32 || substr($s, -$pad) !== str_repeat(chr($pad), $pad)) {
            throw new Exception('解密後 padding 不合法（HashKey / HashIV 是否正確？）');
        }
        return substr($s, 0, strlen($s) - $pad);
    }

    /** 產生 TradeSha */
    function makeTradeSha($tradeInfo)
    {
        $raw = 'HashKey=' . $this->hashKey . '&' . $tradeInfo . '&HashIV=' . $this->hashIV;
        return strtoupper(hash('sha256', $raw));
    }

    /** 驗證回傳的 TradeSha */
    function verifyTradeSha($tradeInfo, $tradeSha)
    {
        return self::safeEquals($this->makeTradeSha($tradeInfo), strtoupper(trim((string)$tradeSha)));
    }

    /**
     * 單筆交易查詢 API 的 CheckValue
     */
    static function makeCheckValue($amt, $merchantId, $merchantOrderNo, $hashKey, $hashIV)
    {
        $raw = 'IV=' . $hashIV . '&Amt=' . $amt . '&MerchantID=' . $merchantId
             . '&MerchantOrderNo=' . $merchantOrderNo . '&Key=' . $hashKey;
        return strtoupper(hash('sha256', $raw));
    }

    /** 回傳結果的 CheckCode 驗證（規格書 4.1.5） */
    static function makeCheckCode($params, $hashKey, $hashIV)
    {
        $p = array(
            'Amt' => isset($params['Amt']) ? $params['Amt'] : '',
            'MerchantID' => isset($params['MerchantID']) ? $params['MerchantID'] : '',
            'MerchantOrderNo' => isset($params['MerchantOrderNo']) ? $params['MerchantOrderNo'] : '',
            'TradeNo' => isset($params['TradeNo']) ? $params['TradeNo'] : '',
        );
        ksort($p);
        $raw = 'HashIV=' . $hashIV . '&' . self::buildQuery($p) . '&HashKey=' . $hashKey;
        return strtoupper(hash('sha256', $raw));
    }

    /** PHP 5.2 沒有 hash_equals */
    static function safeEquals($a, $b)
    {
        $a = (string)$a;
        $b = (string)$b;
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        $diff = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $diff |= (ord($a[$i]) ^ ord($b[$i]));
        }
        return $diff === 0;
    }
}