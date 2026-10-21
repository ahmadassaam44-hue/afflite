<?php
$code = (int)($_GET['c'] ?? 404); if (!in_array($code, [403, 404, 500], true)) $code = 404;
http_response_code($code);
$m = [
  403 => ['403', 'غير مسموح بالوصول', 'ليس لديك صلاحية للوصول إلى هذه الصفحة.'],
  404 => ['404', 'الصفحة غير موجودة', 'يبدو أن الرابط غير صحيح أو أن المنتج لم يعد متاحاً.'],
  500 => ['500', 'خطأ مؤقت في الخادم', 'حدثت مشكلة غير متوقعة — يرجى المحاولة مرة أخرى بعد لحظات.']
][$code];
$ui_base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/error.php')), '/.');
$ui_esc = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $code ?> — <?= $ui_esc($m[1]) ?></title>


<link rel="stylesheet" href="<?= $ui_esc($ui_base) ?>/assets/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/style.css') ?: 20261021 ?>"></head>
<body data-ui="u-803bfb02 uj7 ue1 ue7 uf9 ufv">
<main class="auth" id="errorContent" data-ui="u-4a7737d1 udn ukx uem">
  <div data-ui="u-d0cfe77b ujc ue6 uky"><?= $ui_esc($m[0]) ?></div>
  <h1 data-ui="u-af3e543f ui7 ue6 ud8"><?= $ui_esc($m[1]) ?></h1>
  <p class="mut" data-ui="u-3ca58e94 ufe"><?= $ui_esc($m[2]) ?></p>
  <form method="get" action="<?= $ui_esc($ui_base) ?>/index.php" data-ui="u-1ef74f0f ue1 ues ueh">
    <input type="search" name="q" aria-label="ابحث عن منتج" placeholder="ابحث عن منتج..." data-ui="u-21c90cab ufu uic">
    <button type="submit" class="btn pri">بحث</button>
  </form>
  <div data-ui="u-02b118b5 ue1 ues uf9 uet">
    <a class="btn" href="<?= $ui_esc($ui_base) ?>/index.php">الصفحة الرئيسية</a>
    <a class="btn outline" href="<?= $ui_esc($ui_base) ?>/register.php">حساب جديد</a>
    <a class="btn outline" href="<?= $ui_esc($ui_base) ?>/index.php?col=discounts#products">عروض اليوم</a>
    <a class="btn outline" href="<?= $ui_esc($ui_base) ?>/dashboard/support.php">الدعم الفني</a>
  </div>
</main></body></html>
