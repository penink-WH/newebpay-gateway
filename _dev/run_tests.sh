#!/bin/bash
# run_tests.sh - 一鍵啟動本機測試環境並跑完所有測試
#
# 用法：
#   bash _dev/run_tests.sh          # 啟動環境 → 跑測試（環境會留著方便你開瀏覽器看）
#   bash _dev/run_tests.sh stop     # 關掉測試用服務
#
# 會做的事：
#   1. 啟動測試用 MariaDB（資料放在 /tmp/nwmysql，重開機會消失，不碰你正式資料庫）
#   2. 補齊資料庫與表格（install.php 會自動遷移舊欄位）
#   3. 啟動 PHP 內建伺服器（8811 埠）
#   4. 執行加解密標準向量測試 + 端對端流程測試
set -u

DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT=8811
DATA=/tmp/nwmysql/data
SOCK=/tmp/nwmysql/run/my.sock

# config.php 裡的資料庫埠（沒設定就用 13306）
DB_PORT=$(grep -E "^define\('DB_PORT'" "$DIR/config.php" 2>/dev/null | grep -oE "[0-9]+" | head -1)
DB_PORT="${DB_PORT:-13306}"
DB_NAME=$(grep -E "^define\('DB_NAME'" "$DIR/config.php" 2>/dev/null | grep -oE "'[^']+'" | tr -d "'")
DB_NAME="${DB_NAME:-newebpay_gateway}"

MYSQL_BIN="$(command -v mariadb || command -v mysql)"
MYSQLD_BIN="$(command -v mariadbd || command -v mysqld)"

stop_env() {
  pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null && echo "已停止 PHP 測試伺服器（${PORT}）" || true
  if [ -f /tmp/nwmysql/run/my.pid ]; then
    kill "$(cat /tmp/nwmysql/run/my.pid)" 2>/dev/null && echo "已停止測試用 MariaDB（${DB_PORT}）" || true
  fi
}

if [ "${1:-}" = "stop" ]; then
  stop_env
  exit 0
fi

command -v php >/dev/null || { echo "❌ 找不到 php，請先 brew install php"; exit 1; }
[ -n "$MYSQLD_BIN" ] || { echo "❌ 找不到 mariadbd/mysqld，請先 brew install mariadb"; exit 1; }

# 1. MariaDB
if ! "$MYSQL_BIN" -h 127.0.0.1 -P "$DB_PORT" -u root -e "SELECT 1" >/dev/null 2>&1; then
  echo "▶ 啟動測試用 MariaDB（${DB_PORT}，資料目錄 ${DATA}）"
  mkdir -p "$DATA" /tmp/nwmysql/run
  if [ ! -d "$DATA/mysql" ]; then
    "$(command -v mariadb-install-db || command -v mysql_install_db)" \
      --datadir="$DATA" --auth-root-authentication-method=normal > /tmp/nwmysql/install.log 2>&1 \
      || { echo "❌ 初始化資料目錄失敗，見 /tmp/nwmysql/install.log"; exit 1; }
  fi
  "$MYSQLD_BIN" --datadir="$DATA" --socket="$SOCK" --port="$DB_PORT" \
    --bind-address=127.0.0.1 --pid-file=/tmp/nwmysql/run/my.pid \
    --skip-name-resolve --innodb-buffer-pool-size=64M > /tmp/nwmysql/server.log 2>&1 &
  for i in $(seq 1 20); do
    "$MYSQL_BIN" --socket="$SOCK" -u root -e "SELECT 1" >/dev/null 2>&1 && break
    sleep 1
  done
fi
"$MYSQL_BIN" --socket="$SOCK" -u root -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8;
  CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '';
  GRANT ALL ON \`$DB_NAME\`.* TO 'root'@'127.0.0.1'; FLUSH PRIVILEGES;" >/dev/null 2>&1
echo "✔ MariaDB 就緒（127.0.0.1:${DB_PORT}／${DB_NAME}）"

# 2. 表格與測試站台
echo "▶ 檢查資料表"
(cd "$DIR" && php install.php | sed 's/^/  /')

# 3. PHP 伺服器
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null
sleep 1
PHP_CLI_SERVER_WORKERS=8 php -S "127.0.0.1:$PORT" -t "$DIR" > /tmp/nw_php_server.log 2>&1 &
sleep 2
if ! curl -s -o /dev/null "http://127.0.0.1:$PORT/pay_request.php"; then
  echo "❌ PHP 伺服器沒起來，見 /tmp/nw_php_server.log"; exit 1
fi
echo "✔ PHP 測試伺服器就緒（http://127.0.0.1:${PORT}）"

# 4. 測試
echo
echo "══════ 1/2 加解密標準向量 ══════"
(cd "$DIR" && php _dev/test_crypto.php); CRYPTO=$?

echo
echo "══════ 2/2 端對端流程測試 ══════"
(cd "$DIR" && php _dev/test_e2e.php); E2E=$?

echo
echo "════════════════════════════════"
if [ $CRYPTO -eq 0 ] && [ $E2E -eq 0 ]; then
  echo "✅ 全部測試通過"
  echo
  echo "你可以用瀏覽器實際操作："
  echo "  付款入口    http://127.0.0.1:$PORT/demo/source_site.php"
  echo "  交易查詢    http://127.0.0.1:$PORT/pay_query.php   （後台密碼見 config.php 的 ADMIN_PASSWORD）"
  echo "  站台設定    http://127.0.0.1:$PORT/pay_sources_admin.php"
  echo "  模擬藍新    http://127.0.0.1:$PORT/_dev/mock_newebpay_gateway.php"
  echo
  echo "停止服務：bash _dev/run_tests.sh stop"
  exit 0
else
  echo "❌ 有測試未通過（crypto=$CRYPTO e2e=${E2E}）"
  exit 1
fi