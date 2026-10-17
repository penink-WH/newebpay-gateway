<?php
/**
 * DB.php - 極簡資料庫存取層
 *
 * 目標環境 PHP 5.2 + MySQL 5.x（僅 mysql_* 函式）。
 * 為了能在新版 PHP（本機測試）運作，依序自動選擇 mysql_* / mysqli / PDO_mysql，
 * 對外 API 完全一致，程式碼不需要任何改動。
 */

class DB
{
    /** @var resource|object */
    static $conn = null;
    /** @var string */
    static $driver = '';

    static function init()
    {
        if (self::$conn !== null) {
            return;
        }

        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8';

        if (function_exists('mysql_connect')) {
            // PHP 5.2 ~ 6.x
            $c = @mysql_connect(DB_HOST . ':' . DB_PORT, DB_USER, DB_PASS);
            if (!$c) {
                die('DB connect failed');
            }
            mysql_query('SET NAMES ' . $charset, $c);   // 注意：mysql_query 的參數順序是 (查詢字串, 連線)
            mysql_select_db(DB_NAME, $c);
            self::$conn = $c;
            self::$driver = 'mysql';
            return;
        }

        if (function_exists('mysqli_connect')) {
            $c = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            if (!$c) {
                die('DB connect failed');
            }
            @mysqli_set_charset($c, $charset);
            self::$conn = $c;
            self::$driver = 'mysqli';
            return;
        }

        if (class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers())) {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . $charset;
            try {
                self::$conn = new PDO($dsn, DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
                self::$conn->exec("SET NAMES " . $charset);
            } catch (Exception $e) {
                die('DB connect failed');
            }
            self::$driver = 'pdo';
            return;
        }

        die('No MySQL driver available (需要 mysql_* / mysqli / PDO_mysql 其中之一)');
    }

    /** 跳過 SQL 識別字/值中的引號 */
    static function esc($value)
    {
        self::init();
        if ($value === null) {
            return '';
        }
        if (self::$driver === 'mysql') {
            // 注意：mysql_real_escape_string 的參數順序是 (字串, 連線)
            return mysql_real_escape_string($value, self::$conn);
        }
        if (self::$driver === 'mysqli') {
            return mysqli_real_escape_string(self::$conn, $value);
        }
        $quoted = self::$conn->quote((string)$value);
        return substr($quoted, 1, strlen($quoted) - 2);
    }

    /** 執行 SQL（select 請用 queryAll / queryRow） */
    static function exec($sql)
    {
        self::init();
        if (self::$driver === 'mysql') {
            $r = mysql_query($sql, self::$conn);
            if ($r === false) {
                throw new Exception('SQL error: ' . mysql_error(self::$conn) . ' | ' . $sql);
            }
            return $r;
        }
        if (self::$driver === 'mysqli') {
            $r = mysqli_query(self::$conn, $sql);
            if ($r === false) {
                throw new Exception('SQL error: ' . mysqli_error(self::$conn) . ' | ' . $sql);
            }
            return $r;
        }
            $r = self::$conn->query($sql);
            if ($r === false) {
                $e = self::$conn->errorInfo();
                throw new Exception('SQL error: ' . $e[2] . ' | ' . $sql);
            }
            return $r;
    }

    /** 查詢多筆，回傳陣列清單 */
    static function queryAll($sql)
    {
        self::init();
        $res = self::exec($sql);
        $rows = array();
        if (self::$driver === 'mysql') {
            while ($row = mysql_fetch_assoc($res)) {
                $rows[] = $row;
            }
            return $rows;
        }
        if (self::$driver === 'mysqli') {
            while ($row = mysqli_fetch_assoc($res)) {
                $rows[] = $row;
            }
            return $rows;
        }
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** 查詢單筆，沒有資料回傳 false */
    static function queryRow($sql)
    {
        $rows = self::queryAll($sql);
        if (count($rows) === 0) {
            return false;
        }
        return $rows[0];
    }

    /** 查詢單一欄位第一列 */
    static function queryValue($sql, $default = '')
    {
        $row = self::queryRow($sql);
        if (!$row) {
            return $default;
        }
        $vals = array_values($row);
        return count($vals) > 0 ? $vals[0] : $default;
    }

    static function lastId()
    {
        self::init();
        if (self::$driver === 'mysql') {
            return mysql_insert_id(self::$conn);
        }
        if (self::$driver === 'mysqli') {
            return mysqli_insert_id(self::$conn);
        }
        return self::$conn->lastInsertId();
    }

    static function affected()
    {
        self::init();
        if (self::$driver === 'mysql') {
            return mysql_affected_rows(self::$conn);
        }
        if (self::$driver === 'mysqli') {
            return mysqli_affected_rows(self::$conn);
        }
        return self::$conn->query('SELECT ROW_COUNT()')->fetchColumn();
    }

    /**
     * INSERT 輔助
     * @param string $table 資料表名
     * @param array  $data  欄位 => 值
     */
    static function insert($table, $data)
    {
        $cols = array();
        $vals = array();
        foreach ($data as $k => $v) {
            $cols[] = '`' . $k . '`';
            $vals[] = "'" . self::esc($v) . "'";
        }
        $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
        self::exec($sql);
        return self::lastId();
    }

    /**
     * UPDATE 輔助
     * @param array $data 欄位 => 值
     * @param array $where 欄位 => 值（已 escape 之字串）
     */
    static function update($table, $data, $where)
    {
        $set = array();
        foreach ($data as $k => $v) {
            $set[] = '`' . $k . "` = '" . self::esc($v) . "'";
        }
        $cond = array();
        foreach ($where as $k => $v) {
            $cond[] = '`' . $k . "` = '" . self::esc($v) . "'";
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE ' . implode(' AND ', $cond);
        return self::exec($sql);
    }

    /**
     * 產生 INSERT ... ON DUPLICATE KEY UPDATE（相容 MySQL 4.1+，MySQL 5.2 安全）
     */
    static function upsert($table, $data, $updateCols)
    {
        $cols = array();
        $vals = array();
        foreach ($data as $k => $v) {
            $cols[] = '`' . $k . '`';
            $vals[] = "'" . self::esc($v) . "'";
        }
        $upd = array();
        foreach ($updateCols as $k) {
            $upd[] = '`' . $k . "` = VALUES(`" . $k . "`)";
        }
        $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(',', $upd);
        return self::exec($sql);
    }
}