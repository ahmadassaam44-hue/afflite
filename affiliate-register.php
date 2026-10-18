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
<title>التسويق بالعمولة والربح من الإنترنت | <?php echo esc(BRAND); ?> أفلييت</title>
<meta name="description" content="ابدأ التسويق بالعمولة مع <?php echo esc(BRAND); ?> أفلييت مجاناً. تعرّف على الربح من الإنترنت، شارك منتجاتك واكسب عمولة على الطلبات المستلمة؛ لا يوجد دخل مضمون.">
<meta name="robots" content="index,follow">
<link rel="canonical" href="https://wafirinhalk.com/affiliate-register.php">
<meta property="og:type" content="website">
<meta property="og:locale" content="ar_EG">
<meta property="og:site_name" content="<?php echo esc(BRAND); ?> أفلييت">
<meta property="og:title" content="التسويق بالعمولة والربح من الإنترنت | <?php echo esc(BRAND); ?> أفلييت">
<meta property="og:description" content="سجّل مجاناً وشارك المنتجات لتكسب عمولة على الطلبات المستلمة. الدخل غير مضمون ويعتمد على المبيعات.">
<meta property="og:url" content="https://wafirinhalk.com/affiliate-register.php">
<meta property="og:image" content="https://wafirinhalk.com/assets/icon-512.png">
<meta name="twitter:card" content="summary_large_image">
<script type="application/ld+json"><?php echo json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => [['@type' => 'Question', 'name' => 'هل أحتاج لدفع أي رسوم للاشتراك أو فتح متجري؟', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'لا، التسجيل وفتح متجرك المستقل واستخدام صفحات الهبوط مجاني بالكامل.']], ['@type' => 'Question', 'name' => 'متى يمكنني سحب أرباحي؟', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'بمجرد تسليم الطلب للعميل وتحصيل المبلغ تنتقل العمولة إلى رصيدك المتاح للسحب فوراً.']], ['@type' => 'Question', 'name' => 'ماذا يحدث لو ألغى العميل الطلب أو رفض الاستلام؟', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'لا يتحمل المسوق أي رسوم شحن على الطلبات الملغاة، وتُلغى فقط العمولة المعلقة الخاصة بالطلب غير المستلم.']]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
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
        اختر من آلاف المنتجات الجاهزة للشحن الفوري، حدد عمولتك المخصصة لكل منتج بنفسك، وشارك رابط متجرك أو صفحة الهبوط، ونحن نتولى التأكيد والتغليف والشحن والتحصيل. لو بتسأل عن الربح من الإنترنت أو إزاي تكسب فلوس من النت، التسويق بالعمولة يتيح لك كسب عمولة عند تسليم الطلب؛ الأرباح غير مضمونة وتعتمد على المبيعات.
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

  <section class="card" aria-labelledby="affiliate-platforms-title">
    <h2 id="affiliate-platforms-title">منصات وبرامج التسويق بالعمولة المتاحة</h2>
    <p>لو بتدور على شغل أونلاين أو طريقة للربح من النت، تقدر تبدأ من كتالوج وفرنهالك أفلييت وتروّج لمنتجات من شركاء مثل <strong>Time Hand (تيم هاند)</strong> و<strong>Vendoor (فيندور)</strong> و<strong>Safqa (صفقة)</strong>. اختر المنتج، جهّز رابط متجرك أو صفحة الهبوط، وشاركها مع جمهورك.</p>
    <p>وتتوفر أيضاً برامج تسويق بالعمولة مرتبطة بمتاجر معروفة مثل <strong>أمازون</strong> و<strong>نون</strong> و<strong>جوميا</strong>، حسب تفعيل البرنامج ومراجعة الرابط أو الكوبون والموافقة عليه داخل لوحة المسوق. راجع شروط كل برنامج قبل الترويج.</p>
    <p>لو هدفك تكسب فلوس من النت، فالتسويق بالعمولة يتيح لك الحصول على عمولة مقابل المبيعات المؤهلة؛ لكنه ليس ربحاً مضموناً أو سريعاً، والنتائج تعتمد على جودة المحتوى والجمهور والطلبات التي تُستكمل وتُعتمد.</p>
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
    <details data-ui="u-2fd789c6 ulb ulc">
      <summary data-ui="u-0cff833d ue6 uhm">هل أقدر أسوّق لمنتجات أمازون ونون وجوميا؟</summary>
      <p class="mut" data-ui="u-49e0de07 ujt">نعم، توجد صفحات لبرامج أمازون ونون وجوميا، ويخضع كل رابط أو كوبون للمراجعة والموافقة حسب قواعد البرنامج المتاحة في لوحة المسوق.</p>
    </details>
    <details data-ui="u-2fd789c6 ulb ulc">
      <summary data-ui="u-0cff833d ue6 uhm">هل الربح من الإنترنت أو التسويق بالعمولة مضمون؟</summary>
      <p class="mut" data-ui="u-49e0de07 ujt">لا، لا يوجد دخل مضمون. العمولة تُحتسب حسب البرنامج والطلبات المستلمة أو المبيعات المؤكدة، وتتأثر بالزيارات ومعدل التحويل.</p>
    </details>
    <div data-ui="u-b8bb1aed ukw udn">
      <a href="<?php echo esc($reg_url); ?>" class="btn">انضم الآن وابدأ التسويق</a>
    </div>
  </section>
</main>
<script src="assets/ui.js?v=20261018-visfeat" defer></script>
</body>
</html>
