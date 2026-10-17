<?php
// ============================================================================
//  🩺 فحص سريع بعد رفع تحديث «أفلييت أمازون» — للأدمن فقط
//  افتح: yoursite.com/amazon_check.php
//  بعد ما تتأكد إن كل حاجة تمام → احذف الملف من الاستضافة.
// ============================================================================
require_once __DIR__ . '/config.php';

if (!function_exists('is_admin_user') || !is_admin_user()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<div style="font-family:Cairo,sans-serif;max-width:520px;margin:80px auto;padding:28px;border:1px solid #fecaca;background:#fef2f2;border-radius:16px;text-align:center">'
       . '<div style="font-size:44px">🔒</div><h2 style="color:#b91c1c;margin:8px 0">للأدمن فقط</h2>'
       . '<p style="color:#7f1d1d;font-size:14px">سجّل دخول بحساب الأدمن الأول ثم أعد فتح الصفحة.</p></div>';
    exit;
}
header('Content-Type: text/html; charset=utf-8');

$checks = [];
$add = function ($label, $ok, $hint = '', $warn = false) use (&$checks) {
    $checks[] = ['label' => $label, 'ok' => (bool)$ok, 'hint' => $hint, 'warn' => $warn];
};

// 1) النواة محمّلة
$add('ملف النواة <code>amazon_lib.php</code> موجود', file_exists(__DIR__ . '/amazon_lib.php'),
     __DIR__ . '/amazon_lib.php');
$add('نواة النظام محمّلة داخل config.php', function_exists('amz_stats') && function_exists('amz_create_link'));
$add('رابط التتبع <code>amz.php</code> موجود', file_exists(__DIR__ . '/amz.php'));
$add('لوحة المسوق <code>dashboard/amazon.php</code> موجودة', file_exists(__DIR__ . '/dashboard/amazon.php'));
$add('تبويب الإدارة مضاف', file_exists(__DIR__ . '/dashboard/admin.php')
     && strpos((string)@file_get_contents(__DIR__ . '/dashboard/admin.php'), "'amazon'") !== false);

// 2) صلاحيات الكتابة
$add('مجلد <code>data/</code> قابل للكتابة', is_dir(DATA_DIR) && is_writable(DATA_DIR), DATA_DIR);

// 3) النظام مفعّل
$add('النظام مفعّل للمسوقين', amz_enabled(), '', true);

// 4) التاجات
$tags_ok = false; $tags_list = [];
foreach (amz_marketplaces() as $k => $m) {
    $t = amz_tag($k);
    if ($t !== '') { $tags_ok = true; $tags_list[] = $m['flag'] . ' ' . $m['label'] . ': <b dir="ltr">' . esc($t) . '</b>'; }
}
$add('تاج أمازون (Associate Tag) مضبوط على الأقل لمتجر واحد', $tags_ok,
     $tags_list ? implode(' • ', $tags_list) : 'لسه مش مضبوط — لازم تضبطه من: لوحة الإدارة ← 🛍️ أمازون أفلييت ← ⚙️ الإعدادات');

// 5) إحصائيات
$st = amz_stats();
$add('قاعدة بيانات أمازون جاهزة', true,
     'الروابط: ' . number_format($st['links']) . ' • النقرات: ' . number_format($st['clicks'])
     . ' • البيعات: ' . number_format($st['orders'])
     . ' • عمولة الموقع: ' . number_format($st['site_egp'], 2) . ' ج.م'
     . ' • عمولة المسوقين: ' . number_format($st['mk_egp'], 2) . ' ج.م');

// 6) رابط تجريبي
$sample = '';
foreach (amz_links_all() as $id => $l) { $sample = amz_track_url((string)$id); break; }
$add('رابط تتبع تجريبي', $sample !== '', $sample !== '' ? '<a dir="ltr" href="' . esc($sample) . '" target="_blank">' . esc($sample) . '</a>' : 'لا توجد روابط بعد');

$all_ok = true; foreach ($checks as $c) { if (!$c['ok']) $all_ok = false; }
?>
<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>فحص تحديث أفلييت أمازون</title>
<link rel="stylesheet" href="assets/style.css"></head>
<body style="background:#f8fafc">
<div style="max-width:860px;margin:30px auto;padding:20px">
  <div style="background:linear-gradient(135deg,#0f172a,#232f3e);color:#fff;border-radius:18px;padding:22px;margin-bottom:18px">
    <h1 style="margin:0;font-size:20px">🩺 فحص تحديث أفلييت أمازون</h1>
    <p style="margin:6px 0 0;font-size:13px;color:#cbd5e1">تأكد إن كل البنود خضراء. الملف ده للأدمن فقط — احذفه بعد الفحص.</p>
  </div>

  <?php if ($all_ok): ?>
    <div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:14px 18px;border-radius:12px;font-weight:900;margin-bottom:16px">
      ✅ كل حاجة تمام — النظام جاهز للاستخدام
    </div>
  <?php else: ?>
    <div style="background:#fef9c3;border:1px solid #fde047;color:#854d0e;padding:14px 18px;border-radius:12px;font-weight:900;margin-bottom:16px">
      ⚠️ فيه بند محتاج تعديل — شوف البنود الحمراء تحت
    </div>
  <?php endif; ?>

  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden">
    <table style="width:100%;font-size:13px">
      <tr><th style="width:44px"></th><th>البند</th><th>تفاصيل</th></tr>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td style="text-align:center;font-size:18px"><?php echo $c['ok'] ? '✅' : '❌'; ?></td>
          <td style="font-weight:800"><?php echo $c['label']; ?></td>
          <td style="color:#475569"><?php echo $c['hint']; ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">
    <a class="btn" href="dashboard/admin.php?tab=amazon" >⚙️ إعدادات أمازون</a>
    <a class="btn outline" href="dashboard/amazon.php">🛍️ لوحة المسوق</a>
    <a class="btn outline" href="dashboard/admin.php">👑 لوحة الإدارة</a>
  </div>
</div>
</body></html>
