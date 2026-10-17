<?php require 'config.php'; $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { $email = strtolower(trim($_POST['email'] ?? ''));
$wait = rate_limit_check('forgot', 5, 900);
if ($wait > 0) { $msg = '⏳ محاولات كتيرة — استنى '.fmt_wait($wait); }
elseif ($email === '' || !(function_exists('valid_email') && valid_email($email))) { $msg = 'صيغة البريد الإلكتروني غير صحيحة'; }
elseif (!usr_recover($email)) { $msg = 'تعذر إرسال الكود دلوقتي، جرّب تاني بعد دقيقة'; }
else { $_SESSION['pw_reset'] = ['email' => $email, 'at' => time()]; header('Location: reset.php'); exit; }
} ?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#fed701">
<title>استعادة كلمة السر | <?php echo BRAND; ?></title>


<link rel="preload" href="assets/cairo-arabic.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="assets/cairo-basic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/style.css?v=20261010-eo-fields"></head>
<body data-ui="u-803bfb02 uj7 ue1 ue7 uf9 ufv">
<?php require_once __DIR__ . '/ui-components.php'; wf_ui_sprite(); ?>
<a class="skip-link" href="#authContent">تخطي إلى نموذج الحساب</a>
<main class="auth" id="authContent" data-ui="u-5d7158f0 uem">
  <div data-ui="u-02099a9f udn uk9">
    <a href="index.php" data-ui="u-3d4dfc36 uka ue6 uej ufn"><?php echo BRAND; ?><span data-ui="u-29d95478 ukb">.</span></a>
    <h1 data-ui="u-41d9cdf8 ukc ue6 ueu">استعادة كلمة المرور</h1>
    <p class="mut">أدخل بريدك الإلكتروني المسجّل وهنبعتلك كود من 6 أرقام عليه</p>
  </div>
  <?php if ($msg): ?><div class="<?php echo $ok?'ok':'err'; ?>"><?php echo esc($msg); ?></div><?php endif; ?>
  <form method="post">
    <?php echo csrf_field(); ?>
    <label for="fgEmail" data-ui="u-8328352a ugv ui2 ue6 uew">البريد الإلكتروني المسجّل</label>
    <input id="fgEmail" type="email" name="email" autocomplete="email" inputmode="email" data-ui="u-4f836fe8 ukd ufh" placeholder="name@example.com" required autofocus>
    <button class="btn pri" data-ui="u-e67ab03c uf9 uem ukk ujt">إرسال الكود</button>
  </form>
  <div data-ui="u-4291c26c ukl ukm ukn udn">
    <p class="mut">تذكرت كلمة المرور؟ <a href="login.php" data-ui="u-0c7828ab ue6 ued">العودة لتسجيل الدخول</a></p>
  </div>
</main>
<script src="assets/ui.js?v=20261009-city-mk1" defer></script></body></html>
