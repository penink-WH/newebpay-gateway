<?php
/**
 * layout.php - 版面（PHP 5.2 相容，無外部相依）
 */

function nw_page_head($title, $sub)
{
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo nw_e($title); ?></title>
<style>
:root{--bg:#f5f6f8;--card:#fff;--line:#e3e6ec;--txt:#22262e;--mut:#6b7280;--pri:#1a56db;--ok:#0a7c3f;--bad:#b42318;--warn:#a15c07}
*{box-sizing:border-box}
body{margin:0;padding:24px 12px;background:var(--bg);color:var(--txt);font:15px/1.65 -apple-system,"PingFang TC","Microsoft JhengHei",sans-serif;overflow-x:hidden}
.wrap{max-width:960px;margin:0 auto;overflow-x:hidden}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:20px 22px;margin-bottom:16px}
h1{font-size:20px;margin:0 0 4px}h2{font-size:16px;margin:0 0 12px}
.sub{color:var(--mut);font-size:13px;margin-bottom:18px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top;word-break:break-word;overflow-wrap:anywhere}
th{background:#fafbfc;color:var(--mut);font-weight:600;white-space:nowrap}
td.k{color:var(--mut);white-space:nowrap;width:150px}
.amount{font-size:30px;font-weight:700;letter-spacing:.5px}
.ok{color:var(--ok)}.bad{color:var(--bad)}.warn{color:var(--warn)}
.tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid var(--line);background:#fafbfc}
.btn{display:inline-block;background:var(--pri);color:#fff;border:0;border-radius:8px;padding:12px 22px;font-size:16px;font-weight:600;cursor:pointer;text-decoration:none}
.btn.ghost{background:#fff;color:var(--txt);border:1px solid var(--line)}
.btn.sm{padding:6px 12px;font-size:13px}
.notice{border-left:3px solid var(--pri);background:#f6f9ff;padding:10px 14px;margin:12px 0;font-size:14px}
.vacc{font-size:26px;font-weight:700;letter-spacing:3px;background:#f6f9ff;border:1px dashed var(--pri);padding:12px 16px;border-radius:8px;margin:10px 0}
.muted{color:var(--mut);font-size:13px}
pre{background:#0f172a;color:#e2e8f0;padding:10px 12px;border-radius:6px;font-size:12px;max-height:300px;
    white-space:pre-wrap;word-break:break-all;overflow-wrap:anywhere;max-width:100%;overflow-x:auto;line-height:1.55}
.timeline{border-left:2px solid var(--line);margin-left:8px;padding-left:14px}
.timeline div{margin-bottom:10px;font-size:13px;word-break:break-word;overflow-wrap:anywhere}
.timeline div>code,.timeline div>a{overflow-wrap:anywhere}
.badge{display:inline-block;padding:1px 8px;border-radius:999px;font-size:12px;border:1px solid var(--line);background:#fafbfc}
.logsum{display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin:10px 0;font-size:14px}
code{background:#f1f3f6;padding:1px 5px;border-radius:4px;font-size:13px;word-break:break-all;overflow-wrap:anywhere}
</style>
</head>
<body><div class="wrap">
<div class="card">
<h1><?php echo nw_e($title); ?></h1>
<div class="sub"><?php echo nw_e($sub); ?></div>
<?php
}

function nw_page_foot()
{
    ?>
</div>
<?php
$__mail = nw_cfg('support_email', '');
$__notice = nw_cfg('notice', '');
if ($__notice !== '') {
    echo '<div class="notice">' . nl2br(nw_e($__notice)) . '</div>';
}
?>
<p class="muted" style="text-align:center">藍新金流付款閘道 · 訂單 <?php echo nw_e(NEWEB_MERCHANT_ID); ?> · <?php echo nw_e(NEWEB_ENV === 'live' ? '正式環境' : '測試環境'); ?>
<?php if ($__mail !== '') { echo ' · 客服信箱 ' . nw_e($__mail); } ?>
</p>
</div></body></html>
<?php
}

/** 狀態標籤 */
function nw_status_tag($status)
{
    $cls = 'tag';
    if ($status === 'PAID') {
        $cls .= ' ok';
    } elseif ($status === 'FAILED' || $status === 'CANCELLED') {
        $cls .= ' bad';
    } elseif ($status === 'VACC_ISSUED' || $status === 'DISPATCHED' || $status === 'CONFIRMED') {
        $cls .= ' warn';
    }
    return '<span class="' . $cls . '">' . nw_e($status) . ' ' . nw_e(nw_status_text($status)) . '</span>';
}