-- 藍新金流付款閘道 - 資料表
-- MySQL 5.x / utf8(InnoDB) 相容；不使用 5.6+ 以上語法

CREATE TABLE IF NOT EXISTS `pay_sources` (
  `source_code`  VARCHAR(32)  NOT NULL COMMENT '來源站台代號',
  `source_name`  VARCHAR(100) NOT NULL DEFAULT '' COMMENT '來源站台名稱',
  `callback_url` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '預設結果回呼網址 NOTIFY（機器對機器，帶簽章；空則由請求自帶）',
  `result_url`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '預設付款完成後導回來源站頁面 RESULT（瀏覽器導向，附加訂單資訊查詢參數）',
  `secret`       VARCHAR(128) NOT NULL DEFAULT '' COMMENT '簽章金鑰（雙方共用）',
  `allow_ips`    VARCHAR(255) NOT NULL DEFAULT '' COMMENT '允許連入的 IP，逗號分隔，支援 CIDR；空=不限制（第一階段篩選）',
  `allow_origins` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '接受的來源端網站（Origin/Referer 主機名），逗號分隔；空=不檢查',
  `allow_hosts`  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '回呼網域白名單，逗號分隔；空=不檢查',
  `is_active`    TINYINT(1)   NOT NULL DEFAULT '1',
  `remark`       VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`   DATETIME     NOT NULL,
  `updated_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`source_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='來源站台設定';

CREATE TABLE IF NOT EXISTS `pay_orders` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `order_no`       VARCHAR(30)   NOT NULL COMMENT '本站訂單編號 = 藍新 MerchantOrderNo',
  `source_code`    VARCHAR(32)   NOT NULL DEFAULT '',
  `source_order_no` VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '來源站台訂單編號',
  `amount`         INT(11)      NOT NULL DEFAULT '0' COMMENT '金額(元)',
  `item_desc`      VARCHAR(200)  NOT NULL DEFAULT '',
  `buyer_name`     VARCHAR(64)   NOT NULL DEFAULT '',
  `buyer_email`    VARCHAR(128)  NOT NULL DEFAULT '',
  `buyer_phone`    VARCHAR(32)   NOT NULL DEFAULT '',
  `pay_type`       VARCHAR(16)   NOT NULL DEFAULT 'both' COMMENT 'credit | atm | both',
  `status`         VARCHAR(16)   NOT NULL DEFAULT 'CREATED' COMMENT 'CREATED/CONFIRMED/DISPATCHED/VACC_ISSUED/PAID/FAILED/CANCELLED/REFUNDED',
  `status_msg`     VARCHAR(255)  NOT NULL DEFAULT '',
  `neweb_trade_no` VARCHAR(32)   NOT NULL DEFAULT '' COMMENT '藍新交易序號',
  `payment_type`   VARCHAR(32)   NOT NULL DEFAULT '' COMMENT '藍新回傳 PaymentType',
  `pay_time`       VARCHAR(32)   NOT NULL DEFAULT '',
  `expire_date`    VARCHAR(16)   NOT NULL DEFAULT '' COMMENT 'ATM 繳費期限',
  `bank_code`      VARCHAR(16)   NOT NULL DEFAULT '' COMMENT 'ATM 銀行代號',
  `bank_name`      VARCHAR(64)   NOT NULL DEFAULT '',
  `branch_name`    VARCHAR(64)   NOT NULL DEFAULT '',
  `virtual_account` VARCHAR(32)  NOT NULL DEFAULT '' COMMENT 'ATM 虛擬帳號',
  `callback_url`   VARCHAR(255)  NOT NULL DEFAULT '' COMMENT '結果要回呼的來源站台網址（NOTIFY）',
  `result_url`     VARCHAR(255)  NOT NULL DEFAULT '' COMMENT '付款完成後導回來源站頁面（RESULT）',
  `callback_state` VARCHAR(16)   NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING/SENT/FAILED/SKIPPED',
  `callback_at`    DATETIME     NULL DEFAULT NULL COMMENT '最後成功回呼時間',
  `callback_tries` INT(11)       NOT NULL DEFAULT '0',
  `cb_sent_status` VARCHAR(16)   NOT NULL DEFAULT '' COMMENT '最後成功回呼時的狀態，避免重複回呼',
  `request_ip`     VARCHAR(64)   NOT NULL DEFAULT '',
  `notify_raw`     TEXT          NULL,
  `created_at`     DATETIME     NOT NULL,
  `updated_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  UNIQUE KEY `uk_source_order` (`source_code`,`source_order_no`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_trade_no` (`neweb_trade_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='付款訂單主檔';

CREATE TABLE IF NOT EXISTS `pay_logs` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `order_no`   VARCHAR(30) NOT NULL DEFAULT '' COMMENT '可空白(例如來源站台被拒時)',
  `stage`      VARCHAR(24) NOT NULL COMMENT 'RECEIVE/CONFIRM/DISPATCH/NOTIFY/RETURN/CUSTOMER/QUERY/CALLBACK/SYSTEM',
  `action`     VARCHAR(48) NOT NULL DEFAULT '',
  `result`     VARCHAR(16) NOT NULL DEFAULT 'OK' COMMENT 'OK/FAIL/WARN',
  `message`    VARCHAR(255) NOT NULL DEFAULT '',
  `detail`     TEXT         NULL,
  `remote_ip`  VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_no`),
  KEY `idx_stage` (`stage`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='事件紀錄（驅動付款/轉送/接收結果 全部留痕）';

CREATE TABLE IF NOT EXISTS `pay_callbacks` (
  `id`            INT(11)     NOT NULL AUTO_INCREMENT,
  `order_no`      VARCHAR(30) NOT NULL,
  `source_code`   VARCHAR(32) NOT NULL DEFAULT '',
  `url`           VARCHAR(255) NOT NULL DEFAULT '',
  `request_body`  TEXT        NULL,
  `response_code` VARCHAR(16) NOT NULL DEFAULT '',
  `response_body` VARCHAR(500) NOT NULL DEFAULT '',
  `attempt`       INT(11)     NOT NULL DEFAULT '1',
  `cost_ms`       INT(11)     NOT NULL DEFAULT '0',
  `is_success`    TINYINT(1)  NOT NULL DEFAULT '0',
  `error`         VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`    DATETIME    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_no`),
  KEY `idx_success` (`is_success`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='回呼來源站台傳送紀錄（成功與否）';