<?php
/**
 * PayGateway.php - 付款業務邏輯（訂單、事件紀錄、藍新交易、回呼）
 * PHP 5.2 相容；不依賴任何框架或 composer
 */

class PayGateway
{
    // ------------------------------------------------------------ 藍新基本

    /** 加解密物件 */
    static function crypto()
    {
        static $c = null;
        if ($c === null) {
            $c = new NewebPayCrypto(NEWEB_HASH_KEY, NEWEB_HASH_IV, NEWEB_PAD_BLOCK);
        }
        return $c;
    }

    /** 藍新主機 */
    static function newebHost()
    {
        $env = defined('NEWEB_ENV') ? NEWEB_ENV : 'test';
        return $env === 'live' ? 'https://core.newebpay.com' : 'https://ccore.newebpay.com';
    }

    /** 付款閘道（發動付款用，POST 表單目的地） */
    static function mpgUrl()
    {
        if (defined('NEWEB_GATEWAY_URL') && NEWEB_GATEWAY_URL !== '') {
            return NEWEB_GATEWAY_URL;
        }
        return self::newebHost() . '/MPG/mpg_gateway';
    }

    /** 單筆交易查詢 API */
    static function queryUrl()
    {
        if (defined('NEWEB_QUERY_URL') && NEWEB_QUERY_URL !== '') {
            return NEWEB_QUERY_URL;
        }
        return self::newebHost() . '/API/QueryTradeInfo';
    }

    static function returnUrl()
    {
        return nw_base('pay_notify.php?src=return');
    }

    static function notifyUrl()
    {
        return nw_base('pay_notify.php?src=notify');
    }

    static function customerUrl()
    {
        return nw_base('pay_notify.php?src=customer');
    }

    static function clientBackUrl()
    {
        return nw_base('pay_request.php');
    }

    // ------------------------------------------------------------ 來源站台

    /** 取得來源站台設定；回傳 false 表示不存在或未啟用 */
    static function getSource($code)
    {
        $code = trim((string)$code);
        if ($code === '') {
            return false;
        }
        $row = DB::queryRow("SELECT * FROM `pay_sources` WHERE `source_code` = '" . DB::esc($code) . "' LIMIT 1");
        if ($row) {
            return $row['is_active'] ? $row : false;
        }
        global $NEWEB_INLINE_SOURCES;
        if (isset($NEWEB_INLINE_SOURCES[$code])) {
            $s = $NEWEB_INLINE_SOURCES[$code];
            return array(
                'source_code' => $code,
                'source_name' => isset($s['name']) ? $s['name'] : $code,
                'callback_url' => isset($s['callback_url']) ? $s['callback_url'] : '',
                'result_url' => isset($s['result_url']) ? $s['result_url'] : '',
                'secret' => isset($s['secret']) ? $s['secret'] : '',
                'allow_ips' => isset($s['allow_ips']) ? $s['allow_ips'] : '',
                'allow_origins' => isset($s['allow_origins']) ? $s['allow_origins'] : '',
                'allow_hosts' => isset($s['allow_hosts']) ? $s['allow_hosts'] : '',
                'max_amount' => isset($s['max_amount']) ? $s['max_amount'] : 0,
                'is_active' => 1,
                'remark' => 'config 內建',
            );
        }
        return false;
    }

    // ------------------------------------------------------------ 准入管制（兩階段）

    /**
     * 第一階段：來源站台識別 + 連線來源篩選（IP、Origin/Referer）
     * 先擋掉「根本不是我們的子公司站台」或「從不該來的地方來的請求」，
     * 通過後才進入第二階段比對 sign，避免對非授權流量做簽章運算。
     *
     * @param string $sourceCode 來源端用戶代碼
     * @param string $ip         REMOTE_ADDR
     * @return array array('ok'=>bool,'reason'=>string,'source'=>array|false)
     */
    static function accessStage1($sourceCode, $ip)
    {
        $source = self::getSource($sourceCode);
        if (!$source) {
            return array('ok' => false, 'reason' => '來源端用戶代碼未註冊或已停用：' . ($sourceCode === '' ? '(空白)' : $sourceCode), 'source' => false);
        }

        // 允許連入的 IP（可留空 = 不限制）
        $allowIps = trim($source['allow_ips']);
        if ($allowIps !== '') {
            $rules = self::splitList($allowIps);
            $hit = false;
            foreach ($rules as $r) {
                if (self::ipMatch($ip, $r)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return array('ok' => false, 'reason' => '來源 IP 不在允許清單：' . $ip . '（允許：' . $allowIps . '）', 'source' => $source);
            }
        }

        // 接受的來源端網站（藍新同款規則；留空 = 不檢查）
        $allowOrigins = trim($source['allow_origins']);
        if ($allowOrigins !== '') {
            $host = self::requestSiteHost();
            if ($host === '') {
                return array('ok' => false, 'reason' => '要求來源網站稽核，但請求未帶 Origin/Referer（允許：' . $allowOrigins . '）', 'source' => $source);
            }
            $rules = self::splitList($allowOrigins);
            $hit = false;
            foreach ($rules as $r) {
                if (self::hostMatch($host, $r)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return array('ok' => false, 'reason' => '來源網站不在允許清單：' . $host . '（允許：' . $allowOrigins . '）', 'source' => $source);
            }
        }

        return array('ok' => true, 'reason' => '', 'source' => $source);
    }

    /**
     * 第二階段：簽章比對（僅在第一階段通過後執行）
     * @return bool
     */
    static function accessStage2($source, $post, $sign)
    {
        if ($source['secret'] === '') {
            return false;
        }
        $fields = $post;
        unset($fields['sign']);
        return nw_verify_sign($source['secret'], $fields, $sign, 'sign');
    }

    /** 逗號／分號／空白分隔的字串轉陣列 */
    static function splitList($s)
    {
        $parts = preg_split('/[\s,;]+/', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? $parts : array();
    }

    /** 取得請求的來源網站主機名（Origin 優先，其次 Referer）；PHP 5.2 無 $_SERVER['HTTP_ORIGIN'] 保證 */
    static function requestSiteHost()
    {
        $candidates = array();
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            $candidates[] = $_SERVER['HTTP_ORIGIN'];
        }
        if (isset($_SERVER['HTTP_REFERER'])) {
            $candidates[] = $_SERVER['HTTP_REFERER'];
        }
        foreach ($candidates as $u) {
            $host = parse_url($u, PHP_URL_HOST);
            if ($host) {
                return strtolower($host);
            }
        }
        return '';
    }

    /** 主機名比對：完全相同，或以允許值結尾（shop.example.com 命中 example.com） */
    static function hostMatch($host, $rule)
    {
        $host = strtolower(trim($host));
        $rule = strtolower(trim($rule));
        if ($rule === '') {
            return false;
        }
        if ($host === $rule) {
            return true;
        }
        if (substr($rule, 0, 1) === '.' && substr($host, -strlen($rule)) === $rule) {
            return true;
        }
        if (substr($rule, 0, 1) !== '.' && substr($host, -strlen('.' . $rule)) === '.' . $rule) {
            return true;
        }
        return false;
    }

    /** IP 比對：完全相同或 CIDR（含 IPv4；IPv4-mapped IPv6 會先轉成 IPv4） */
    static function ipMatch($ip, $rule)
    {
        $ip = trim((string)$ip);
        $rule = trim((string)$rule);
        if ($ip === '' || $rule === '') {
            return false;
        }
        if (strpos($rule, '/') === false) {
            return $ip === $rule;
        }
        if (strpos($ip, ':') !== false && strpos($ip, '.') !== false) {
            $ip = substr($ip, strpos($ip, ':') + 1);      // ::ffff:127.0.0.1 → 127.0.0.1
        }
        if (strpos($ip, ':') !== false || strpos($rule, ':') !== false) {
            return false;                                  // 不支援 IPv6 CIDR，請填完整 IP
        }
        $bits = explode('/', $rule, 2);
        $net = $bits[0];
        $mask = isset($bits[1]) ? (int)$bits[1] : 32;
        $ipLong = ip2long($ip);
        $netLong = ip2long($net);
        if ($ipLong === false || $netLong === false || $mask < 0 || $mask > 32) {
            return false;
        }
        if ($mask === 0) {
            return true;
        }
        $maskLong = (-1 << (32 - $mask)) & 0xFFFFFFFF;
        return (($ipLong & $maskLong) === ($netLong & $maskLong));
    }

    /** 回呼網址是否在允許清單（避免被當成跳板外傳訂單資訊） */
    static function callbackAllowed($url, $source)
    {
        if ($url === '') {
            return false;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        $isHttps = (stripos($url, 'https://') === 0);
        if (!$isHttps && ALLOW_HTTP != 1) {
            return false;
        }
        $hosts = trim($source['allow_hosts']);
        if ($hosts === '') {
            return true;
        }
        $host = parse_url($url, PHP_URL_HOST);
        $allow = array_map('trim', explode(',', strtolower($hosts)));
        return in_array(strtolower($host), $allow);
    }

    /**
     * 檢查簽章金鑰是否為常見的示範／弱值，避免正式環境還在用公開的測試金鑰
     * @return string  '' 表示沒問題；否則回傳提醒文字
     */
    static function weakSecretReason($secret)
    {
        $weak = array(
            'DEMO-SECRET-123456' => '這是本專案公開示範用的測試金鑰',
            'CHANGE-ME' => '這是設定檔範本裡的預設值',
            'secret' => '金鑰過於簡單',
            'test' => '金鑰過於簡單',
        );
        $s = trim((string)$secret);
        if ($s === '') {
            return '尚未設定簽章金鑰（等於完全不做簽章驗證）';
        }
        if (isset($weak[$s])) {
            return $weak[$s];
        }
        if (strlen($s) < 16) {
            return '金鑰長度僅 ' . strlen($s) . ' 碼，建議至少 16 碼以上';
        }
        return '';
    }

    // ------------------------------------------------------------ 訂單

    /**
     * 建立訂單（同一來源＋同一來源訂單編號為冪等）
     * @return array array('ok'=>bool,'msg'=>string,'order_no'=>string,'order'=>array)
     */
    static function createOrder($source, $in, $ip)
    {
        $sourceOrderNo = trim((string)$in['source_order_no']);
        $amount = nw_money($in['amount']);
        $payType = in_array($in['pay_type'], array('credit', 'atm', 'both')) ? $in['pay_type'] : 'both';

        if ($sourceOrderNo === '' || strlen($sourceOrderNo) > 64) {
            return array('ok' => false, 'msg' => '來源訂單編號必填且不得超過 64 碼', 'order_no' => '', 'order' => null);
        }
        if ($amount <= 0) {
            return array('ok' => false, 'msg' => '金額格式錯誤（需為整數元，不可有小數或千分位）', 'order_no' => '', 'order' => null);
        }

        // 金額上限：防止把「分」誤當成「元」（最常見的上線收款錯誤）
        $maxAmount = isset($source['max_amount']) ? (int)$source['max_amount'] : 0;
        if ($maxAmount > 0 && $amount > $maxAmount) {
            self::log('', 'RECEIVE', 'AMOUNT_OVER_LIMIT', 'FAIL',
                '金額 ' . $amount . ' 元超過站台上限 ' . $maxAmount . ' 元（可能把「分」當成「元」）',
                array('amount' => $amount, 'max_amount' => $maxAmount), $ip);
            return array('ok' => false, 'msg' => '金額 ' . $amount . ' 元超過此站台設定的上限 ' . $maxAmount . ' 元；請確認金額單位是「元」而不是「分」', 'order_no' => '', 'order' => null);
        }

        $callbackUrl = trim((string)$in['callback_url']);
        if ($callbackUrl !== '') {
            if (!self::callbackAllowed($callbackUrl, $source)) {
                return array('ok' => false, 'msg' => 'callback_url 不在允許清單內或非 https', 'order_no' => '', 'order' => null);
            }
        } else {
            $callbackUrl = $source['callback_url'];
        }

        $resultUrl = trim((string)$in['result_url']);
        if ($resultUrl !== '') {
            if (!self::callbackAllowed($resultUrl, $source)) {
                return array('ok' => false, 'msg' => 'result_url 不在允許清單內或非 https', 'order_no' => '', 'order' => null);
            }
        } else {
            $resultUrl = $source['result_url'];
        }

        // 冪等：同一來源 + 同一來源訂單編號只允許一筆（避免來源站台重送造成重複出貨）
        $exist = DB::queryRow("SELECT * FROM `pay_orders` WHERE `source_code` = '" . DB::esc($source['source_code'])
            . "' AND `source_order_no` = '" . DB::esc($sourceOrderNo) . "' LIMIT 1");
        if ($exist) {
            self::log($exist['order_no'], 'RECEIVE', 'DUPLICATE_ORDER', 'WARN',
                '來源站台重複轉送同一筆付款需求（' . $sourceOrderNo . '），已忽略',
                array('source_order_no' => $sourceOrderNo), $ip);
            return array(
                'ok' => false,
                'msg' => '此來源訂單編號已存在（訂單編號 ' . $exist['order_no'] . '），未重複建立',
                'order_no' => $exist['order_no'],
                'order' => $exist,
            );
        }

        $itemDesc = nw_truncate(trim((string)$in['item_desc']), 50);
        if ($itemDesc === '') {
            $itemDesc = '付款單 ' . $sourceOrderNo;
        }
        $expireDate = date('Ymd', strtotime('+' . (int)ATM_EXPIRE_DAYS . ' day'));

        $data = array(
            'order_no' => nw_make_order_no(),
            'source_code' => $source['source_code'],
            'source_order_no' => $sourceOrderNo,
            'amount' => $amount,
            'item_desc' => $itemDesc,
            'buyer_name' => nw_truncate(trim((string)$in['buyer_name']), 64),
            'buyer_email' => nw_truncate(trim((string)$in['buyer_email']), 128),
            'buyer_phone' => nw_truncate(trim((string)$in['buyer_phone']), 32),
            'pay_type' => $payType,
            'status' => 'CREATED',
            'status_msg' => '已收到 ' . $source['source_name'] . ' 轉送的付款需求',
            'callback_url' => $callbackUrl,
            'result_url' => $resultUrl,
            'callback_state' => 'PENDING',
            'request_ip' => $ip,
            'created_at' => nw_now(),
            'updated_at' => nw_now(),
        );
        try {
            $id = DB::insert('pay_orders', $data);
        } catch (Exception $e) {
            self::log('', 'RECEIVE', 'INSERT_FAIL', 'FAIL', '寫入訂單失敗：' . $e->getMessage(), $data, $ip);
            return array('ok' => false, 'msg' => '寫入訂單失敗：' . $e->getMessage(), 'order_no' => '', 'order' => null);
        }

        self::log($data['order_no'], 'RECEIVE', 'CREATE_ORDER', 'OK',
            '收到 ' . $source['source_name'] . ' 付款需求：' . $amount . ' 元',
            $in, $ip);
        if ($amount >= 10000 && ($amount % 100) === 0) {
            self::log($data['order_no'], 'RECEIVE', 'AMOUNT_SUSPICIOUS', 'WARN',
                '金額 ' . $amount . ' 元為整百且金額偏大，請確認來源端不是以「分」送出（' . $amount . ' 分 = ' . ($amount / 100) . ' 元）',
                array('amount' => $amount, 'hint_cents' => $amount / 100), $ip);
        }

        return array('ok' => true, 'msg' => '訂單已建立', 'order_no' => $data['order_no'], 'order' => $data);
    }

    static function getOrder($orderNo)
    {
        $orderNo = trim((string)$orderNo);
        if ($orderNo === '') {
            return false;
        }
        return DB::queryRow("SELECT * FROM `pay_orders` WHERE `order_no` = '" . DB::esc($orderNo) . "' LIMIT 1");
    }

    static function updateOrder($orderNo, $fields)
    {
        $fields['updated_at'] = nw_now();
        DB::update('pay_orders', $fields, array('order_no' => $orderNo));
    }

    // ------------------------------------------------------------ 事件紀錄

    static function log($orderNo, $stage, $action, $result, $message, $detail, $ip)
    {
        $row = array(
            'order_no' => $orderNo,
            'stage' => $stage,
            'action' => $action,
            'result' => $result,
            'message' => nw_truncate($message, 255),
            'detail' => self::encodeDetail($detail),
            'remote_ip' => $ip,
            'created_at' => nw_now(),
        );
        DB::insert('pay_logs', $row);
    }

    static function encodeDetail($detail)
    {
        if ($detail === null || $detail === '') {
            return '';
        }
        if (is_string($detail)) {
            return $detail;
        }
        return nw_json_encode($detail);
    }

    static function logs($orderNo, $limit)
    {
        return DB::queryAll("SELECT * FROM `pay_logs` WHERE `order_no` = '" . DB::esc($orderNo)
            . "' ORDER BY `id` ASC LIMIT " . (int)$limit);
    }

    static function callbacks($orderNo)
    {
        return DB::queryAll("SELECT * FROM `pay_callbacks` WHERE `order_no` = '" . DB::esc($orderNo) . "' ORDER BY `id` ASC");
    }

    // ------------------------------------------------------------ 發動付款

    /**
     * 組出藍新 MPG 表單
     * @return array array('action'=>, 'fields'=>array, 'trade_info_params'=>array)
     */
    static function buildMpg($order, $payType)
    {
        $p = array(
            'MerchantID' => NEWEB_MERCHANT_ID,
            'RespondType' => 'JSON',
            'TimeStamp' => time(),
            'Version' => NEWEB_VERSION,
            'MerchantOrderNo' => $order['order_no'],
            'Amt' => (int)$order['amount'],
            'ItemDesc' => $order['item_desc'],
            'LangType' => NEWEB_LANG,
            'ReturnURL' => self::returnUrl(),
            'NotifyURL' => self::notifyUrl(),
            'ClientBackURL' => self::clientBackUrl(),
        );

        if ($order['buyer_email'] !== '') {
            $p['Email'] = $order['buyer_email'];
            $p['EmailModify'] = '1';
        }

        if ($payType === 'credit') {
            $p['CREDIT'] = '1';      // 信用卡一次付清
            $p['InstFlag'] = '1';    // 分期（全部期數）
        } elseif ($payType === 'atm') {
            $p['VACC'] = '1';        // ATM 虛擬帳號
            $p['ExpireDate'] = date('Ymd', strtotime('+' . (int)ATM_EXPIRE_DAYS . ' day'));
            $p['ExpireTime'] = '235959';
            $p['CustomerURL'] = self::customerUrl();
        } else {
            $p['CREDIT'] = '1';
            $p['InstFlag'] = '1';
            $p['VACC'] = '1';
            $p['ExpireDate'] = date('Ymd', strtotime('+' . (int)ATM_EXPIRE_DAYS . ' day'));
            $p['ExpireTime'] = '235959';
            $p['CustomerURL'] = self::customerUrl();
            if (defined('NEWEB_ENABLE_CVS') && NEWEB_ENABLE_CVS == 1) {
                $p['CVS'] = '1';      // 超商代碼
            }
        }

        $crypto = self::crypto();
        $tradeInfo = $crypto->encryptParams($p);
        $tradeSha = $crypto->makeTradeSha($tradeInfo);

        return array(
            'action' => self::mpgUrl(),
            'fields' => array(
                'MerchantID' => NEWEB_MERCHANT_ID,
                'TradeInfo' => $tradeInfo,
                'TradeSha' => $tradeSha,
                'Version' => NEWEB_VERSION,
            ),
            'trade_info_params' => $p,
        );
    }

    // ------------------------------------------------------------ 接收藍新結果

    /**
     * 處理藍新回傳（NotifyURL / ReturnURL / CustomerURL）
     * @param array  $post $_POST
     * @param string $kind notify | return | customer
     * @param string $ip
     * @return array array('ok'=>bool,'msg'=>string,'order_no'=>string,'status'=>string)
     */
    static function handleIncoming($post, $kind, $ip)
    {
        $res = array('ok' => false, 'msg' => '', 'order_no' => '', 'status' => '', 'callback' => false);

        // ---- 取號結果（ATM / 超商）為明文 POST
        if ($kind === 'customer') {
            $orderNo = isset($post['MerchantOrderNo']) ? $post['MerchantOrderNo'] : '';
            $order = self::getOrder($orderNo);
            if (!$order) {
                self::log('', 'CUSTOMER', 'UNKNOWN_ORDER', 'FAIL', '取號結果對應不到訂單', $post, $ip);
                $res['msg'] = 'order not found';
                return $res;
            }
            $fields = array(
                'status' => 'VACC_ISSUED',
                'status_msg' => 'ATM 虛擬帳號已取號',
                'neweb_trade_no' => self::pick($post, array('TradeNo')),
                'virtual_account' => self::pick($post, array('PayAccount', 'VirtualAccount', 'vAccount', 'Account')),
                'bank_code' => self::pick($post, array('PayBankCode', 'BankCode', 'Bank')),
                'bank_name' => self::pick($post, array('PayBankName', 'BankName')),
                'branch_name' => self::pick($post, array('PayBranchName', 'BranchName', 'Branch')),
                'expire_date' => self::pick($post, array('ExpireDate', 'PayExpireDate', 'ExpireTime')),
            );
            if ($fields['expire_date'] === '') {
                $fields['expire_date'] = date('Ymd', strtotime('+' . (int)ATM_EXPIRE_DAYS . ' day'));
            }
            if (in_array($order['status'], array('PAID', 'FAILED', 'CANCELLED', 'REFUNDED'), true)) {
                $fields['status'] = $order['status'];   // 已進入終態時不覆蓋狀態，只補齊帳號資訊
            }
            if (LOG_RAW_POST) {
                self::log($order['order_no'], 'CUSTOMER', 'VACC_ISSUED', 'OK',
                    '藍新回傳 ATM 虛擬帳號：' . $fields['virtual_account'] . '（' . $fields['bank_name'] . ' ' . $fields['branch_name'] . '）', $post, $ip);
            }
            self::updateOrder($order['order_no'], $fields);
            $res['ok'] = true;
            $res['msg'] = 'ok';
            $res['order_no'] = $order['order_no'];
            $res['status'] = 'VACC_ISSUED';
            $res['callback'] = self::sendCallback($order['order_no'], '藍新已產生 ATM 虛擬帳號');
            return $res;
        }

        // ---- 付款結果（加密 POST）
        $tradeInfo = isset($post['TradeInfo']) ? trim($post['TradeInfo']) : '';
        $tradeSha = isset($post['TradeSha']) ? trim($post['TradeSha']) : '';
        $outerStatus = isset($post['Status']) ? trim($post['Status']) : '';
        if ($tradeInfo === '') {
            self::log('', ($kind === 'return' ? 'RETURN' : 'NOTIFY'), 'EMPTY_TRADEINFO', 'FAIL', '未帶 TradeInfo', $post, $ip);
            $res['msg'] = 'no TradeInfo';
            return $res;
        }

        $crypto = self::crypto();
        if (!$crypto->verifyTradeSha($tradeInfo, $tradeSha)) {
            self::log('', ($kind === 'return' ? 'RETURN' : 'NOTIFY'), 'BAD_SHA', 'FAIL', 'TradeSha 驗證失敗', $post, $ip);
            $res['msg'] = 'TradeSha mismatch';
            return $res;
        }

        try {
            $data = $crypto->decryptParams($tradeInfo);
        } catch (Exception $e) {
            self::log('', ($kind === 'return' ? 'RETURN' : 'NOTIFY'), 'DECRYPT_FAIL', 'FAIL', $e->getMessage(), $post, $ip);
            $res['msg'] = $e->getMessage();
            return $res;
        }

        $orderNo = self::pick($data, array('MerchantOrderNo'));
        $order = self::getOrder($orderNo);
        if (!$order) {
            self::log('', ($kind === 'return' ? 'RETURN' : 'NOTIFY'), 'UNKNOWN_ORDER', 'FAIL', '結果對應不到訂單：' . $orderNo, $data, $ip);
            $res['msg'] = 'order not found';
            return $res;
        }

        $status = self::pick($data, array('Status'));
        $message = self::pick($data, array('Message'));
        $amt = (int)self::pick($data, array('Amt'));
        $paymentType = self::pick($data, array('PaymentType'));

        // 金額必須與訂單一致，否則視為異常
        if ($amt > 0 && $amt !== (int)$order['amount']) {
            self::log($order['order_no'], ($kind === 'return' ? 'RETURN' : 'NOTIFY'), 'AMT_MISMATCH', 'FAIL',
                '回傳金額 ' . $amt . ' 與訂單金額 ' . (int)$order['amount'] . ' 不符', $data, $ip);
            $res['order_no'] = $order['order_no'];
            $res['msg'] = 'amount mismatch';
            return $res;
        }

        $newStatus = 'PAID';
        if ($status !== 'SUCCESS') {
            if ($order['status'] === 'PAID') {
                // 已付款的訂單不因後續失敗通知被改回失敗（避免重複通知造成錯誤狀態）
                self::log($order['order_no'], ($kind === 'return' ? 'RETURN' : 'NOTIFY'), 'IGNORE_FAIL_AFTER_PAID', 'WARN',
                    '訂單已是付款成功，忽略後續的失敗通知：' . $status . ' ' . $message, $data, $ip);
                $res['ok'] = true;
                $res['msg'] = $message;
                $res['order_no'] = $order['order_no'];
                $res['status'] = 'PAID';
                return $res;
            }
            $newStatus = 'FAILED';
            $code = strtoupper($status);
            if ($code === 'MPG03009' || strpos($message, '取號') !== false) {
                // 取號失敗時不算付款失敗
                $newStatus = 'FAILED';
            }
        }

        $fields = array(
            'status' => $newStatus,
            'status_msg' => nw_truncate($status . ' ' . $message, 255),
            'neweb_trade_no' => self::pick($data, array('TradeNo')),
            'payment_type' => $paymentType,
            'pay_time' => self::pick($data, array('PayTime')),
        );
        if ($fields['pay_time'] === '') {
            $fields['pay_time'] = nw_now();
        }
        self::updateOrder($order['order_no'], $fields);

        if (LOG_RAW_POST) {
            self::log($order['order_no'], ($kind === 'return' ? 'RETURN' : 'NOTIFY'),
                $kind === 'return' ? 'RETURN_RESULT' : 'NOTIFY_RESULT',
                $newStatus === 'PAID' ? 'OK' : 'WARN',
                '藍新回傳 ' . $status . ' ' . $message . '（' . nw_payment_type_text($paymentType) . '）',
                array('outer_status' => $outerStatus, 'data' => $data, 'raw_post' => self::maskPost($post)), $ip);
        }

        $res['ok'] = true;
        $res['msg'] = $message;
        $res['order_no'] = $order['order_no'];
        $res['status'] = $newStatus;
        $res['callback'] = self::sendCallback($order['order_no'], '藍新回傳付款結果');
        return $res;
    }

    /** 移除可能含卡號的欄位後才落 log */
    static function maskPost($post)
    {
        $sensitive = array('cardno', 'cardnumber', 'cvv', 'cvc', 'exp', 'token', 'tokenterm');
        $out = array();
        foreach ($post as $k => $v) {
            if (is_array($v)) {
                $out[$k] = '[array]';
                continue;
            }
            $lk = strtolower($k);
            $masked = false;
            foreach ($sensitive as $s) {
                if (strpos($lk, $s) !== false) {
                    $masked = true;
                    break;
                }
            }
            $out[$k] = $masked ? '******' : $v;
        }
        return $out;
    }

    /** 多代名單取值 */
    static function pick($arr, $keys)
    {
        foreach ($keys as $k) {
            if (isset($arr[$k]) && $arr[$k] !== '') {
                return $arr[$k];
            }
        }
        return '';
    }

    /**
     * 組出付款完成後要導回來源站台（RESULT）的網址
     * 附加訂單資訊與簽章，方便來源站台直接確認這筆付款
     */
    static function buildResultUrl($order)
    {
        $params = array(
            'order_no' => $order['order_no'],
            'source_order_no' => $order['source_order_no'],
            'status' => $order['status'],
            'status_text' => nw_status_text($order['status']),
            'amount' => (int)$order['amount'],
            'neweb_trade_no' => $order['neweb_trade_no'],
            'payment_type' => $order['payment_type'],
            'pay_time' => $order['pay_time'],
            'virtual_account' => $order['virtual_account'],
            'bank_code' => $order['bank_code'],
            'bank_name' => $order['bank_name'],
            'branch_name' => $order['branch_name'],
            'expire_date' => $order['expire_date'],
            'timestamp' => time(),
        );
        $source = self::getSource($order['source_code']);
        if ($source && $source['secret'] !== '') {
            $params['sign'] = nw_sign($source['secret'], $params, 'sign');
        }
        $sep = (strpos($order['result_url'], '?') === false) ? '?' : '&';
        return $order['result_url'] . $sep . NewebPayCrypto::buildQuery($params);
    }

    // ------------------------------------------------------------ 回呼來源站台

    /**
     * 將交易結果回呼來源站台，並記錄傳送成功與否
     * @return bool 是否成功
     */
    static function sendCallback($orderNo, $reason, $force = false)
    {
        $order = self::getOrder($orderNo);
        if (!$order) {
            return false;
        }
        $url = trim($order['callback_url']);
        if ($url === '') {
            self::updateOrder($orderNo, array('callback_state' => 'SKIPPED'));
            self::log($orderNo, 'CALLBACK', 'SKIP', 'WARN', $reason . '：未設定回呼網址，略過回呼', null, '');
            return false;
        }

        $source = self::getSource($order['source_code']);
        if (!$source) {
            self::log($orderNo, 'CALLBACK', 'NO_SOURCE', 'FAIL', '找不到來源站台設定：' . $order['source_code'], null, '');
            return false;
        }

        // 同一狀態不重複回呼（除非強制重送），避免來源站台重複出貨
        $lastSent = isset($order['cb_sent_status']) ? $order['cb_sent_status'] : '';
        if ($order['callback_state'] === 'SENT' && $lastSent === $order['status'] && !$force) {
            self::log($orderNo, 'CALLBACK', 'SKIP_DUPLICATE', 'WARN',
                $reason . '：狀態未變更（' . $order['status'] . '）且已回呼成功，略過', null, '');
            return true;
        }

        $payload = array(
            'event' => 'PAYWAY_RESULT',
            'order_no' => $order['order_no'],
            'source_code' => $order['source_code'],
            'source_order_no' => $order['source_order_no'],
            'status' => $order['status'],
            'status_text' => nw_status_text($order['status']),
            'message' => $order['status_msg'],
            'amount' => (int)$order['amount'],
            'currency' => 'TWD',
            'neweb_trade_no' => $order['neweb_trade_no'],
            'payment_type' => $order['payment_type'],
            'payment_type_text' => nw_payment_type_text($order['payment_type']),
            'pay_time' => $order['pay_time'],
            'virtual_account' => $order['virtual_account'],
            'bank_code' => $order['bank_code'],
            'bank_name' => $order['bank_name'],
            'branch_name' => $order['branch_name'],
            'expire_date' => $order['expire_date'],
            'timestamp' => time(),
        );
        $payload['sign'] = nw_sign($source['secret'], $payload, 'sign');

        $maxTry = 1 + (int)CALLBACK_RETRY;
        $success = false;
        $lastResp = null;
        $i = 0;
        for ($i = 1; $i <= $maxTry; $i++) {
            $resp = Http::postForm($url, $payload, CALLBACK_TIMEOUT);
            $lastResp = $resp;
            $ok = ($resp['code'] >= 200 && $resp['code'] < 300 && $resp['error'] === '');
            if ($ok && CALLBACK_ACK !== '') {
                if (strpos($resp['body'], CALLBACK_ACK) === false) {
                    $ok = false;
                }
            }
            DB::insert('pay_callbacks', array(
                'order_no' => $orderNo,
                'source_code' => $order['source_code'],
                'url' => nw_truncate($url, 255),
                'request_body' => nw_json_encode($payload),
                'response_code' => (string)$resp['code'],
                'response_body' => nw_truncate($resp['body'], 500),
                'attempt' => $i,
                'cost_ms' => (int)$resp['cost_ms'],
                'is_success' => $ok ? 1 : 0,
                'error' => nw_truncate($resp['error'] !== '' ? $resp['error'] : ($ok ? '' : '回應內容未包含 ' . CALLBACK_ACK), 255),
                'created_at' => nw_now(),
            ));
            if ($ok) {
                $success = true;
                break;
            }
        }

        $upd = array(
            'callback_state' => $success ? 'SENT' : 'FAILED',
            'callback_tries' => (int)$order['callback_tries'] + $i,
            'cb_sent_status' => $success ? $order['status'] : $lastSent,
        );
        if ($success) {
            $upd['callback_at'] = nw_now();   // 失敗時不要動這欄（DATETIME 不接受空字串）
        }
        self::updateOrder($orderNo, $upd);

        self::log($orderNo, 'CALLBACK', $success ? 'SENT' : 'FAILED', $success ? 'OK' : 'FAIL',
            $reason . '：回呼 ' . $url . ($success ? ' 成功' : ' 失敗（已嘗試 ' . $i . ' 次）'),
            $lastResp, '');

        return $success;
    }

    // ------------------------------------------------------------ 主動查詢藍新

    /**
     * 向藍新查詢單筆交易狀態
     * @return array array('ok'=>bool,'msg'=>string,'result'=>array)
     */
    static function queryNeweb($orderNo, $ip)
    {
        $order = self::getOrder($orderNo);
        if (!$order) {
            return array('ok' => false, 'msg' => '找不到訂單', 'result' => null);
        }
        $checkValue = NewebPayCrypto::makeCheckValue(
            $order['amount'], NEWEB_MERCHANT_ID, $order['order_no'], NEWEB_HASH_KEY, NEWEB_HASH_IV);
        $fields = array(
            'MerchantID' => NEWEB_MERCHANT_ID,
            'Version' => '1.3',
            'RespondType' => 'JSON',
            'CheckValue' => $checkValue,
            'TimeStamp' => time(),
            'MerchantOrderNo' => $order['order_no'],
            'Amt' => (int)$order['amount'],
        );

        $resp = Http::postForm(self::queryUrl(), $fields, CALLBACK_TIMEOUT);
        $out = array('ok' => false, 'msg' => '', 'result' => null, 'raw' => $resp);

        if ($resp['code'] < 200 || $resp['code'] >= 300 || $resp['error'] !== '') {
            self::log($orderNo, 'QUERY', 'QUERY_FAIL', 'FAIL', '查詢藍新失敗：' . $resp['error'] . ' HTTP ' . $resp['code'], $resp, $ip);
            $out['msg'] = $resp['error'] !== '' ? $resp['error'] : ('HTTP ' . $resp['code']);
            return $out;
        }

        $json = json_decode($resp['body'], true);
        $result = null;
        if (is_array($json) && isset($json['Result'])) {
            $result = $json['Result'];
        } else {
            // RespondType 非 JSON 時 Result 為加密字串
            $result = self::crypto()->decryptParams($resp['body']);
        }

        $status = is_array($json) && isset($json['Status']) ? $json['Status'] : '';
        $message = is_array($json) && isset($json['Message']) ? $json['Message'] : '';
        $out['ok'] = ($status === 'SUCCESS');
        $out['msg'] = $message;
        $out['result'] = $result;

        // 檢核碼驗證（藍新回傳 CheckCode）
        $checkOk = true;
        if (is_array($result) && isset($result['CheckCode'])) {
            $expect = NewebPayCrypto::makeCheckCode($result, NEWEB_HASH_KEY, NEWEB_HASH_IV);
            $checkOk = NewebPayCrypto::safeEquals($expect, strtoupper($result['CheckCode']));
        }

        if (is_array($result)) {
            $tradeStatus = isset($result['TradeStatus']) ? (string)$result['TradeStatus'] : '';
            $map = array('1' => 'PAID', '2' => 'FAILED', '3' => 'CANCELLED', '6' => 'REFUNDED', '0' => 'DISPATCHED');
            if (isset($map[$tradeStatus]) && $map[$tradeStatus] !== $order['status']) {
                self::updateOrder($orderNo, array(
                    'status' => $map[$tradeStatus],
                    'status_msg' => '藍新查詢：' . $message,
                    'neweb_trade_no' => isset($result['TradeNo']) ? $result['TradeNo'] : $order['neweb_trade_no'],
                    'payment_type' => isset($result['PaymentType']) ? $result['PaymentType'] : $order['payment_type'],
                    'pay_time' => (isset($result['PayTime']) && $result['PayTime'] !== '') ? $result['PayTime'] : $order['pay_time'],
                ));
                $order = self::getOrder($orderNo);
            }
        }

        self::log($orderNo, 'QUERY', 'QUERY_TRADE', $checkOk ? 'OK' : 'FAIL',
            '向藍新查詢交易：' . $status . ' ' . $message . ($checkOk ? '' : '（CheckCode 驗證失敗）'),
            array('request' => $fields, 'response' => $resp), $ip);

        return $out;
    }
}