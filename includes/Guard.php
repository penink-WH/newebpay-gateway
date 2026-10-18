<?php
/**
 * Guard.php - 上線前防呆（live 環境的必要條件檢查）
 *
 * 目標：把「記得改設定」變成「沒改就跑不起來」。
 * 已知會賠錢的常見疏漏：
 *   1. 留著 _dev 模擬閘道 → 正式收款的 POST 打到模擬器，線上直接收不到錢
 *   2. 藍新金鑰還是官方手冊的公開範例值
 *   3. 後台密碼還是弱值
 *   4. 站台簽章金鑰還是公開示範值 DEMO-SECRET-123456
 *   5. NEWEB_ENV 還是 test
 */

class GoLiveGuard
{
    /** 藍新官方技術手冊公開的範例金鑰（任何人下載文件都有，絕不能用在正式收款） */
    static $publicKeys = array(
        'Fs5cX1TGqYM2PpdbE14a9H83YQSQF5jn',   // 手冊範例 HashKey
        'C6AcmfqJILwgnhIP',                     // 手冊範例 HashIV
        'MS127874575',                          // 手冊範例 MerchantID
    );

    /** 常見弱密碼 */
    static $weakPasswords = array(
        'demo-admin-2026', 'admin', 'admin123', 'password', '123456', '12345678',
        'demo', 'test', 'changeme', 'CHANGE-ME', 'root', '',
    );

    /**
     * 檢查所有「上線前必辦」項目
     * @return array 每筆為 array('level'=>'block'|'warn','item'=>'','msg'=>'','fix'=>'')
     */
    static function issues()
    {
        $out = array();

        // 1. 模擬閘道殘留（最危險）
        foreach (array('NEWEB_GATEWAY_URL', 'NEWEB_QUERY_URL') as $constName) {
            if (defined($constName) && constant($constName) !== '') {
                $url = constant($constName);
                $out[] = array(
                    'level' => 'block',
                    'item' => '模擬閘道設定殘留',
                    'msg' => $constName . ' 仍指向 ' . $url . '，正式收款會打到模擬器而不是藍新',
                    'fix' => '把 config.php 裡 ' . $constName . ' 這兩行整行刪除（連同前面的 // 註解）',
                );
            }
        }

        // 2. 環境
        $env = defined('NEWEB_ENV') ? NEWEB_ENV : 'test';
        if ($env !== 'live') {
            $out[] = array(
                'level' => 'block',
                'item' => '環境仍是測試',
                'msg' => 'NEWEB_ENV = ' . $env . '，正式收款必須改成 live',
                'fix' => "define('NEWEB_ENV', 'live');",
            );
        }

        // 3. 藍新參數
        $mid = defined('NEWEB_MERCHANT_ID') ? trim(NEWEB_MERCHANT_ID) : '';
        if ($mid === '') {
            $out[] = array('level' => 'block', 'item' => '商店代號未設定', 'msg' => 'NEWEB_MERCHANT_ID 為空', 'fix' => '填入藍新後台的商店代號');
        } elseif (in_array($mid, self::$publicKeys, true)) {
            $out[] = array('level' => 'block', 'item' => '商店代號是公開範例值',
                'msg' => 'MerchantID = ' . $mid . '（藍新手冊的公開範例）', 'fix' => '換成你自己的商店代號');
        }

        $key = defined('NEWEB_HASH_KEY') ? trim(NEWEB_HASH_KEY) : '';
        $iv = defined('NEWEB_HASH_IV') ? trim(NEWEB_HASH_IV) : '';
        if ($key === '' || strlen($key) !== 32) {
            $out[] = array('level' => 'block', 'item' => 'HashKey 長度異常',
                'msg' => '長度 ' . strlen($key) . ' 碼（應為 32）', 'fix' => '填入自己商店的 HashKey');
        } elseif (in_array($key, self::$publicKeys, true)) {
            $out[] = array('level' => 'block', 'item' => 'HashKey 是公開範例值',
                'msg' => 'HashKey 等於藍新手冊範例值，任何人都知道，不能用於正式收款',
                'fix' => '換成自己商店的 HashKey');
        }
        if ($iv === '' || strlen($iv) !== 16) {
            $out[] = array('level' => 'block', 'item' => 'HashIV 長度異常',
                'msg' => '長度 ' . strlen($iv) . ' 碼（應為 16）', 'fix' => '填入自己商店的 HashIV');
        } elseif (in_array($iv, self::$publicKeys, true)) {
            $out[] = array('level' => 'block', 'item' => 'HashIV 是公開範例值',
                'msg' => 'HashIV 等於藍新手冊範例值', 'fix' => '換成自己商店的 HashIV');
        }

        // 4. 後台密碼
        $pwd = defined('ADMIN_PASSWORD') ? trim(ADMIN_PASSWORD) : '';
        $weakReason = PayGateway::weakSecretReason($pwd);
        if ($pwd === '') {
            $out[] = array('level' => 'block', 'item' => '後台密碼未設定', 'msg' => 'ADMIN_PASSWORD 為空，後台等於沒有門禁', 'fix' => '設定一個夠長的密碼');
        } elseif (in_array(strtolower($pwd), self::$weakPasswords, true) || $weakReason !== '') {
            $out[] = array('level' => 'block', 'item' => '後台密碼是弱值',
                'msg' => 'ADMIN_PASSWORD = ' . $pwd . '（' . $weakReason . '）',
                'fix' => '換成一組 12 碼以上的隨機密碼');
        }

        // 5. BASE_URL / HTTPS
        $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/' : '';
        if ($base === '' ) {
            $out[] = array('level' => 'block', 'item' => 'BASE_URL 未設定', 'msg' => '藍新會打不進來', 'fix' => '設定公開可達的 HTTPS 網址');
        } elseif (stripos($base, 'https://') !== 0) {
            $out[] = array('level' => 'block', 'item' => 'BASE_URL 不是 HTTPS',
                'msg' => $base . '（藍新與回呼都走明文）', 'fix' => '改用 https:// 的公開網址並安裝 SSL 憑證');
        }
        if (preg_match('#^(https?://)?(127\.0\.0\.1|localhost|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)#i', $base)) {
            $out[] = array('level' => 'block', 'item' => 'BASE_URL 是本機或內網位址',
                'msg' => $base . '（藍新伺服器在別的機器上會打不進來，付款結果永遠收不到）',
                'fix' => '改成對外公開的網域，或讓藍新能連到這台機器的固定 IP＋憑證');
        }

        // 6. 其他
        if (defined('ALLOW_HTTP') && ALLOW_HTTP == 1) {
            $out[] = array('level' => 'block', 'item' => '允許非 HTTPS 回呼',
                'msg' => 'ALLOW_HTTP = 1', 'fix' => "define('ALLOW_HTTP', 0);");
        }

        // 7. 來源站台金鑰
        try {
            $rows = DB::queryAll("SELECT `source_code`, `secret`, `is_active` FROM `pay_sources` WHERE `is_active` = 1");
            foreach ($rows as $r) {
                $reason = PayGateway::weakSecretReason($r['secret']);
                if ($reason !== '') {
                    $out[] = array('level' => 'warn', 'item' => '來源站台金鑰待更換',
                        'msg' => '站台 ' . $r['source_code'] . '：' . $reason,
                        'fix' => '到 pay_sources_admin.php 按「重新產生金鑰」，並把新金鑰私下給該站台');
                }
                if (trim($r['secret']) === '') {
                    $out[] = array('level' => 'block', 'item' => '來源站台未設定簽章金鑰',
                        'msg' => '站台 ' . $r['source_code'] . ' 沒有金鑰，等於完全不驗簽',
                        'fix' => '到 pay_sources_admin.php 產生金鑰');
                }
            }
        } catch (Exception $e) {
            // 資料庫還沒建好（例如剛解壓縮完），安裝階段不阻擋
        }

        return $out;
    }

    /** 有 block 級問題嗎 */
    static function blocked()
    {
        foreach (self::issues() as $i) {
            if ($i['level'] === 'block') {
                return true;
            }
        }
        return false;
    }

    /**
     * 正式環境逐項檢查；發現 block 就輸出說明頁並停止
     */
    static function enforce($entryScript)
    {
        if (NEWEB_ENV !== 'live') {
            return;                 // 測試環境不受限
        }
        $issues = self::issues();
        $blocks = array();
        foreach ($issues as $i) {
            if ($i['level'] === 'block') {
                $blocks[] = $i;
            }
        }
        if (count($blocks) === 0) {
            return;
        }

        $isNotify = (strpos($entryScript, 'pay_notify') !== false);
        if ($isNotify) {
            header('HTTP/1.1 503 Service Unavailable');
            header('Content-Type: text/plain; charset=utf-8');
            echo "FAIL: 系統尚未完成上線設定，暫不處理付款結果\n";
            foreach ($blocks as $b) {
                echo "- " . $b['item'] . "\n";
            }
            exit;
        }

        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: text/html; charset=utf-8');
        $html = '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><title>上線前必辦事項</title>'
            . '<style>body{font:15px/1.8 -apple-system,"PingFang TC",sans-serif;max-width:760px;margin:48px auto;padding:0 16px;color:#22262e}'
            . 'table{width:100%;border-collapse:collapse}td,th{padding:8px 10px;border-bottom:1px solid #e3e6ec;text-align:left;vertical-align:top}'
            . 'code{background:#f1f3f6;padding:1px 6px;border-radius:4px;word-break:break-all}</style></head><body>'
            . '<h2 style="color:#b42318">系統已進入正式（live）模式，但以下項目尚未完成</h2>'
            . '<p>為了避免收款金額錯誤、結果收不到、金鑰外洩等問題，這些項目未完成前系統會拒絕服務。</p><table>'
            . '<tr><th style="width:150px">項目</th><th>問題與處理方式</th></tr>';
        foreach ($blocks as $b) {
            $html .= '<tr><td>' . nw_e($b['item']) . '</td><td>' . nw_e($b['msg'])
                . '<br>→ ' . nw_e($b['fix']) . '</td></tr>';
        }
        $html .= '</table><p class="muted">全部修好後，重新整理本頁即可正常服務。'
            . '設定檔位置：' . nw_e(dirname(__FILE__) . '/../config.php') . '</p></body></html>';
        echo $html;
        exit;
    }
}
