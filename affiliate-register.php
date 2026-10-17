<?php
require_once __DIR__ . '/config.php';
$ref_param = trim((string)($_GET['ref'] ?? ''));
$methods = function_exists('payout_methods') ? payout_methods() : [];
$max_markup = defined('MAX_MARKUP_AMOUNT') ? (int)MAX_MARKUP_AMOUNT : 5000;
$reg_url = 'register.php?role=marketer' . ($ref_param !== '' ? '&ref=' . urlencode($ref_param) : '');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0f172a">
<title>برنامج المسوقين | <?php echo esc(BRAND); ?></title>



<link rel="preload" href="assets/cairo-arabic.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="assets/cairo-basic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/style.css?v=20261010-eo-fields">
</head>
<body>
<?php require_once __DIR__ . '/ui-components.php'; wf_ui_sprite(); ?>
<a href="#main-content" class="skip-link">تخطي إلى المحتوى</a>

<header class="header">
  <div class="header-inner">
    <a href="index.php" class="logo"><span><?php echo esc(BRAND); ?></span> | برنامج المسوقين</a>
    <div data-ui="u-78dd74fc ue1 ufj ue7">
      <a href="login.php" class="btn sm outline">تسجيل الدخول</a>
      <a href="<?php echo esc($reg_url); ?>" class="btn sm">ابدأ كمسوق مجاناً</a>
    </div>
  </div>
</header>

<main id="main-content" class="section" data-ui="u-8b676229 ul1">
  <!-- Hero -->
  <section class="card aff-hero-lite" data-ui="u-45c9559e ul3">
    <div data-ui="u-4eba7f93 ul4">
      <span class="badge yellow" data-ui="u-aea4a95a ul5">نظام مسوقين متكامل بدون رأس مال</span>
      <h1 data-ui="u-13c75798 uhx ul5">اربح عمولة على كل طلب مع متجر إلكتروني وصفحات هبوط باسمك</h1>
      <p data-ui="u-971fadb0 uhy ul6">
        اختر من آلاف المنتجات الجاهزة للشحن الفوري، حدد عمولتك المخصصة لكل منتج بنفسك، وشارك رابط متجرك أو صفحة الهبوط، ونحن نتولى التأكيد والتغليف والشحن والتحصيل.
      </p>
      <div data-ui="u-4d7b1145 ue1 ugg uet">
        <a href="<?php echo esc($reg_url); ?>" class="btn">سجّل كمسوق الآن مجاناً</a>
      </div>
    </div>
  </section>

  <!-- 3 Steps -->
  <section class="card">
    <h2 data-ui="u-fb891ff3 ui7 ufe">كيف يعمل برنامج المسوقين في 3 خطوات؟</h2>
    <div class="kpis">
      <div class="kpi">
        <span class="badge blue" data-ui="u-bd32f672 ueb">الخطوة 1</span>
        <b data-ui="u-7ce28675 uhy">سجّل حسابك وأنشئ متجرك</b>
        <span>بياناتك الإجبارية: الاسم، البريد، رقم الموبايل، المحافظة، المدينة — وبعدها تحصل فوراً على كود إحالة خاص ومتجر إلكتروني مستقل بهويتك وألوانك.</span>
      </div>
      <div class="kpi">
        <span class="badge green" data-ui="u-bd32f672 ueb">الخطوة 2</span>
        <b data-ui="u-7ce28675 uhy">حدد عمولتك وشارك الرابط</b>
        <span>اختر المنتجات بالسعر الأساسي الصافي، أضف عمولتك المخصصة، وشارك صفحة الهبوط الجاهزة.</span>
      </div>
      <div class="kpi">
        <span class="badge yellow" data-ui="u-bd32f672 ueb">الخطوة 3</span>
        <b data-ui="u-7ce28675 uhy">تحصيل الأرباح والسحب</b>
        <span>عند تسليم الطلب للعميل، تتحرر عمولتك بالكامل في محفظتك وتستطيع سحبها بسهولة.</span>
      </div>
    </div>
  </section>

  <!-- Illustrative Example (Clearly labeled as example) -->
  <section class="card">
    <h2 data-ui="u-751c4669 ui7 ueb">مثال توضيحي لكيفية حساب ربحك (أمثلة توضيحية فقط)</h2>
    <p class="mut" data-ui="u-fe8011b4 ueh">الأرقام التالية للتوضيح فقط وليست ضماناً لدخل محدد — يعتمد الربح الفعلي على عدد الطلبات المسلمة والعمولة التي تحددها.</p>
    <div data-ui="u-103cc5b0 uel">
      <table>
        <thead>
          <tr>
            <th>السعر الأساسي للمنتج (بدون عمولة)</th>
            <th>عمولتك المخصصة التي تضيفها</th>
            <th>سعر البيع الظاهر للعميل</th>
            <th>صافي ربحك عند تسليم الطلب</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><b>200 ج.م</b></td>
            <td>+50 ج.م</td>
            <td>250 ج.م (+ الشحن)</td>
            <td class="comm"><b>50 ج.م</b></td>
          </tr>
          <tr>
            <td><b>350 ج.م</b></td>
            <td>+75 ج.م</td>
            <td>425 ج.م (+ الشحن)</td>
            <td class="comm"><b>75 ج.م</b></td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Commission Rules & Payout Methods -->
  <section class="card">
    <h2 data-ui="u-77b18698 ui7 ul5">شفافية العمولة ووسائل السحب المتاحة</h2>
    <ul data-ui="u-b1c23ee0 ul9 udh ufe">
      <li><b>السعر الأساسي:</b> جميع المنتجات معروضة للمسوق بالسعر الأساسي الصافي بدون عمولة مسبقة.</li>
      <li><b>تحديد العمولة:</b> تحدد عمولتك بنفسك لكل منتج (حتى <?php echo number_format($max_markup); ?> ج.م للقطعة).</li>
      <li><b>تحرير العمولة:</b> تسجّل العمولة «معلّقة» فور تسجيل الطلب، وتتحول إلى «متاحة للسحب» فور تغيير حالة الطلب إلى «تم التوصيل».</li>
      <li><b>الشفافية:</b> قواعد الاستخدام في <a href="pages.php?p=terms" data-ui="u-e927b86e ued ula">الشروط والأحكام</a>.</li>
    </ul>
    <?php if ($methods): ?>
    <div class="kpis">
      <?php foreach ($methods as $mk => $mv): ?>
      <div class="kpi">
        <b data-ui="u-40fcd368 uko"><?php echo esc($mv['label'] ?? $mk); ?></b>
        <span>الحد الأدنى للسحب: <?php echo number_format((float)($mv['min'] ?? 100)); ?> ج.م</span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <!-- FAQ -->
  <section class="card">
    <h2 data-ui="u-77b18698 ui7 ul5">الأسئلة الشائعة</h2>
    <details data-ui="u-2fd789c6 ulb ulc">
      <summary data-ui="u-0cff833d ue6 uhm">هل أحتاج لدفع أي رسوم للاشتراك أو فتح متجري؟</summary>
      <p class="mut" data-ui="u-49e0de07 ujt">لا، التسجيل وفتح متجرك المستقل واستخدام صفحات الهبوط مجاني بالكامل.</p>
    </details>
    <details data-ui="u-2fd789c6 ulb ulc">
      <summary data-ui="u-0cff833d ue6 uhm">متى يمكنني سحب أرباحي؟</summary>
      <p class="mut" data-ui="u-49e0de07 ujt">بمجرد تسليم الطلب للعميل وتحصيل المبلغ تنتقل العمولة إلى رصيدك المتاح للسحب فوراً.</p>
    </details>
    <details data-ui="u-fea91d93 ulb">
      <summary data-ui="u-0cff833d ue6 uhm">ماذا يحدث لو ألغى العميل الطلب أو رفض الاستلام؟</summary>
      <p class="mut" data-ui="u-49e0de07 ujt">لا يتحمل المسوق أي رسوم شحن على الطلبات الملغاة، وتُلغى فقط العمولة المعلقة الخاصة بالطلب غير المستلم.</p>
    </details>
    <div data-ui="u-b8bb1aed ukw udn">
      <a href="<?php echo esc($reg_url); ?>" class="btn">انضم الآن وابدأ التسويق</a>
    </div>
  </section>
</main>
<script src="assets/ui.js?v=20261009-city-mk1" defer></script>
</body>
</html>
