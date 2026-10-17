<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ui-components.php';
if (!function_exists('is_admin_user') || !is_admin_user()) {
    http_response_code(403);
    require __DIR__ . '/error.php';
    exit;
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>دليل نظام التصميم (Style Guide) | <?php echo esc(BRAND); ?></title>



<link rel="preload" href="assets/cairo-arabic.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="assets/cairo-basic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/style.css?v=20261010-eo-fields">
</head>
<body>
<?php wf_ui_sprite(); ?>
<main class="section">
  <div class="card">
    <div data-ui="u-1ae8b27f ue1 ue2 ue7 uet ugg">
      <div>
        <h1>نظام التصميم الموحّد — <?php echo esc(BRAND); ?></h1>
        <p class="mut">مرجع المكونات والألوان والـ Tokens (محمي للمشرفين فقط)</p>
      </div>
      <a href="dashboard/admin.php" class="btn outline sm">العودة للوحة الإدارة</a>
    </div>
  </div>

  <!-- 1. Colors & Semantic Tokens -->
  <div class="card">
    <h3>1) الألوان والـ Tokens الدلالية</h3>
    <div class="kpis">
      <div class="kpi" data-ui="u-2e894c7a uhf uhe"><b>#fed701</b><span>Primary CTA</span></div>
      <div class="kpi" data-ui="u-c2525b2c uld udq"><b data-ui="u-3b9d57ad udq">#0f172a</b><span data-ui="u-055e9375 uhj">Dark Surface</span></div>
      <div class="kpi" data-ui="u-4492c262 uf6 uh9"><b data-ui="u-bb0d6c6f uh9">Success</b><span>#059669 / #065f46</span></div>
      <div class="kpi" data-ui="u-f0a2b257 ule ufz"><b data-ui="u-6b87f6a7 ufz">Warning</b><span>#d97706 / #92400e</span></div>
      <div class="kpi" data-ui="u-b80f8e1d uey ud7"><b data-ui="u-d9e0339a ud7">Danger</b><span>#dc2626 / #991b1b</span></div>
      <div class="kpi" data-ui="u-68cb0f00 uga ugc"><b data-ui="u-f5195fe0 ugc">Info</b><span>#2563eb / #1e40af</span></div>
    </div>
  </div>

  <!-- 2. Buttons, Chips & Badges -->
  <div class="card">
    <h3>2) الأزرار والشارات والفلاتر (Buttons, Chips & Badges)</h3>
    <div data-ui="u-d439b8af ue1 ufj uet ue7 ueh">
      <button type="button" class="btn">زر أساسي (Primary)</button>
      <button type="button" class="btn pri">زر معلوماتي (Info)</button>
      <button type="button" class="btn dark">زر داكن (Dark)</button>
      <button type="button" class="btn outline">زر بإطار (Outline)</button>
      <button type="button" class="btn ghost">زر شفاف (Ghost)</button>
      <button type="button" class="btn danger">زر خطر (Danger)</button>
      <button type="button" class="btn sm">حجم صغير (Small)</button>
      <button type="button" class="btn" disabled>معطّل (Disabled)</button>
    </div>
    <div data-ui="u-d1b3320c ue1 ues uet ue7 ueh">
      <span class="chip is-active">فلتر نشط</span>
      <span class="chip">فلتر عادي</span>
      <span class="badge yellow">شارة تنبيه</span>
      <span class="badge green">متاح / مكتمل</span>
      <span class="badge red">ملغي / خصم</span>
      <span class="badge blue">معلومة</span>
      <span class="badge dark">رسمي</span>
    </div>
    <div data-ui="u-4ce6b144 ue1 ufj uet">
      <button type="button" class="btn sm outline" onclick="window.wfToast && window.wfToast('تم نسخ الرابط بنجاح', 'success')">تجربة Toast نجاح</button>
      <button type="button" class="btn sm outline" onclick="window.wfToast && window.wfToast('تنبيه: تأكد من الحقول المطلوبة', 'error')">تجربة Toast خطأ</button>
    </div>
  </div>

  <!-- 3. Inputs & Alerts -->
  <div class="card">
    <h3>3) الحقول والتنبيهات (Inputs & Alerts)</h3>
    <div class="ok">رسالة نجاح واضحة بتباين عالي (Contrast &gt; 4.5:1)</div>
    <div class="err">رسالة خطأ واضحة مرتبطة بالحقل المطلوب</div>
    <div data-ui="u-578211d7 uho uk4 ugg">
      <div>
        <label data-ui="u-ab1cf8a2 udt ue6 ugv">الاسم بالكامل</label>
        <input type="text" placeholder="مثال: محمد أحمد">
      </div>
      <div>
        <label data-ui="u-ab1cf8a2 udt ue6 ugv">المحافظة</label>
        <select><option>القاهرة</option><option>الجيزة</option><option>الإسكندرية</option></select>
      </div>
    </div>
  </div>
</main>
<section class="dash" aria-label="مكونات اللوحات"><div class="main"><div class="card"><h2>مكونات اللوحة — أمثلة عرض، وليست بيانات حقيقية</h2><div class="kpis"><div class="kpi" title="تعريف المؤشر يظهر عند التركيز"><b>—</b><span>رصيد النظام</span></div><div class="kpi"><b>—</b><span>إجمالي الطلبات</span></div></div><?php echo wf_order_timeline('shipped'); ?><div class="alert info"><?php echo wf_icon('info'); ?>تنبيه توضيحي موحّد.</div><button type="button" class="btn outline" data-demo-confirm>تجربة نافذة التأكيد (بدون تنفيذ إجراء)</button><div class="empty-state"><?php echo wf_icon('box',40); ?><h3>حالة فارغة</h3><p>تظهر هنا إرشادات الخطوة التالية عندما لا توجد بيانات.</p></div><div class="skeleton" aria-label="مثال الشكل أثناء التحميل"></div></div></div></section>
<script src="assets/ui.js?v=20261009-city-mk1" defer></script>
</body>
</html>
