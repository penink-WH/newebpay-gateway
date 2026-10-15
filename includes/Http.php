<?php
/**
 * Http.php - 極簡 HTTP 客戶端（curl 優先，無 curl 用 stream context）
 * PHP 5.2 相容
 */

class Http
{
    /**
     * GET（讀取遠端設定用）
     * @return array array('code'=>, 'body'=>, 'error'=>, 'cost_ms'=>, 'url'=>)
     */
    static function get($url, $timeout, $headers)
    {
        $t0 = microtime(true);
        $headers = $headers === null ? array() : $headers;
        $headerLines = '';
        foreach ($headers as $k => $v) {
            $headerLines .= $k . ': ' . $v . "\r\n";
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            if ($headerLines !== '') {
                curl_setopt($ch, CURLOPT_HTTPHEADER, explode("\r\n", trim($headerLines)));
            }
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }
            return array(
                'code' => $code,
                'body' => $resp === false ? '' : $resp,
                'error' => $err,
                'cost_ms' => (int)round((microtime(true) - $t0) * 1000),
                'url' => $url,
            );
        }

        $ctx = stream_context_create(array(
            'http' => array('method' => 'GET', 'header' => $headerLines, 'timeout' => $timeout, 'ignore_errors' => true),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (function_exists('http_get_last_response_headers')) {
            $headers2 = http_get_last_response_headers();
        } else {
            $__hdr = 'http_response_header';
            $headers2 = isset($$__hdr) ? $$__hdr : array();
        }
        if (is_array($headers2)) {
            foreach ($headers2 as $h) {
                if (stripos($h, 'HTTP/') === 0) {
                    $parts = explode(' ', $h);
                    $code = isset($parts[1]) ? (int)$parts[1] : 0;
                }
            }
        }
        return array(
            'code' => $code,
            'body' => $resp === false ? '' : $resp,
            'error' => $resp === false ? '無法連線或逾時' : '',
            'cost_ms' => (int)round((microtime(true) - $t0) * 1000),
            'url' => $url,
        );
    }

    /**
     * POST application/x-www-form-urlencoded
     * @param string $url
     * @param array  $fields
     * @param int    $timeout 秒
     * @param string $cookieFile cookie jar 路徑（給需要 session 的後台頁面用，可省略）
     * @return array array('code'=>, 'body'=>, 'error'=>, 'cost_ms'=>, 'url'=>)
     */
    static function postForm($url, $fields, $timeout, $cookieFile = '')
    {
        $pairs = array();
        foreach ($fields as $k => $v) {
            if ($v === null) {
                continue;
            }
            $pairs[] = urlencode($k) . '=' . urlencode((string)$v);
        }
        $body = implode('&', $pairs);
        $t0 = microtime(true);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            if ($cookieFile !== '') {
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            }
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            if (PHP_VERSION_ID < 80000) {   // PHP 8 起 curl_close 無作用且 8.5 起棄用
                curl_close($ch);
            }
            return array(
                'code' => $code,
                'body' => $resp === false ? '' : $resp,
                'error' => $err,
                'cost_ms' => (int)round((microtime(true) - $t0) * 1000),
                'url' => $url,
            );
        }

        $ctx = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ),
            'ssl' => array(
                'verify_peer' => true,
                'verify_peer_name' => true,
            ),
        ));
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        $err = '';
        // PHP 8.5 起 $http_response_header 已棄用，改用 http_get_last_response_headers()
        if (function_exists('http_get_last_response_headers')) {
            $headers = http_get_last_response_headers();
        } else {
            $__hdr = 'http_response_header';   // 以動態變數取，避開新版的編譯期棄用警告
            $headers = isset($$__hdr) ? $$__hdr : array();
        }
        if (is_array($headers)) {
            foreach ($headers as $h) {
                if (stripos($h, 'HTTP/') === 0) {
                    $parts = explode(' ', $h);
                    $code = isset($parts[1]) ? (int)$parts[1] : 0;
                }
            }
        } else {
            $err = '無法連線或逾時';
        }
        return array(
            'code' => $code,
            'body' => $resp === false ? '' : $resp,
            'error' => $err,
            'cost_ms' => (int)round((microtime(true) - $t0) * 1000),
            'url' => $url,
        );
    }
}