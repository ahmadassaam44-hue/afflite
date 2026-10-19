<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/affiliate_programs.php';
$progs = aff_programs();
$key = (string)($_GET['p'] ?? '');
if (!isset($progs[$key])) { header('Location: affiliate-register.php'); exit; }
$pr = $progs[$key];
$ref_param = trim((string)($_GET['ref'] ?? ''));
$cta = $pr['cta_href'] . ($ref_param !== '' ? (strpos($pr['cta_href'], '?') !== false ? '&' : '?') . 'ref=' . urlencode($ref_param) : '');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0f172a">
<title><?php echo esc($pr['title']); ?> | <?php echo esc(BRAND); ?> أفلييت</title>
<meta name="description" content="<?php echo esc($pr['intro']); ?>">
<meta name="robots" content="index,follow">
<link rel="canonical" href="https://wafirinhalk.com/affiliate-program.php?p=<?php echo rawurlencode($key); ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="ar_EG">
<meta property="og:site_name" content="<?php echo esc(BRAND); ?> أفلييت">
<meta property="og:title" content="<?php echo esc($pr['title']); ?> | <?php echo esc(BRAND); ?> أفلييت">
<meta property="og:description" content="<?php echo esc($pr['intro']); ?>">
<meta property="og:url" content="https://wafirinhalk.com/affiliate-program.php?p=<?php echo rawurlencode($key); ?>">
<meta property="og:image" content="https://wafirinhalk.com/assets/aff-<?php echo esc($key); ?>.png">
<link rel="stylesheet" href="assets/style.css?v=20261012-aff-under-strip">
</head>
<body>
<?php require_once __DIR__ . '/ui-components.php'; wf_ui_sprite(); ?>
<a href="#main-content" class="skip-link">تخطي إلى المحتوى</a>

<header class="header">
  <div class="header-inner">
    <a href="index.php" class="logo"><span><?php echo esc(BRAND); ?></span> | برامج التسويق</a>
    <div>
      <a href="affiliate-register.php" class="btn sm outline">كل البرامج</a>
      <a href="<?php echo esc($cta); ?>" class="btn sm">ابدأ كمسوق مجاناً</a>
    </div>
  </div>
</header>

<main id="main-content" class="section">
  <section class="card aff-hero-lite">
    <div>
      <span class="badge yellow"><?php echo esc($pr['teaser']); ?></span>
      <h1><?php echo esc($pr['title']); ?></h1>
      <p class="mut"><?php echo esc($pr['intro']); ?></p>
    </div>
    <img src="assets/aff-<?php echo esc($key); ?>.png" alt="<?php echo esc($pr['title']); ?>" width="960" height="540" style="width:100%;height:auto;border-radius:14px;margin-top:12px">
  </section>

  <section class="card">
    <h2>كيف يشتغل معانا؟</h2>
    <ol style="line-height:1.9">
      <?php foreach ($pr['steps'] as $s): ?>
      <li><?php echo esc($s); ?></li>
      <?php endforeach; ?>
    </ol>
  </section>

  <section class="card">
    <h2>قواعد مهمة</h2>
    <ul style="line-height:1.9">
      <?php foreach ($pr['facts'] as $f): ?>
      <li><?php echo esc($f); ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="card" style="text-align:center">
    <p class="mut">تقدر ترجع تشوف باقي البرامج في أي وقت.</p>
    <a href="affiliate-register.php" class="btn outline">كل برامج التسويق</a>
    <a href="<?php echo esc($cta); ?>" class="btn"><?php echo esc($pr['cta']); ?></a>
  </section>
</main>
<script src="assets/ui.js?v=20261018-visfeat" defer></script>
</body>
</html>
