<?php require 'config.php';
$err = '';
$wait = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // تحديد معدل المحاولات: 5 محاولات كل 15 دقيقة لكل IP
    $wait = rate_limit_check('login', 5, 900);
    if ($wait > 0) {
        $err = '⏳ محاولات كتيرة — استنى ' . fmt_wait($wait) . ' وجرّب تاني';
    } else {
        $email = trim($_POST['email'] ?? ''); $pass = (string)($_POST['password'] ?? ''); $ok = false;
        // التحقق من الإيميل وكلمة السر عند Supabase Auth، وبيانات الحساب من جدول profiles
        $found = auth_login_profile($email, $pass);
        if ($found) { $ok = true; }
        if ($ok) {
            rate_limit_reset('login');
            session_harden();                       // تجديد معرّف الجلسة (منع Session Fixation)
            // لا تسمح بانتقال صلاحيات إدارية قديمة إلى حساب مسوق/مشتري آخر.
            unset($_SESSION['is_admin'], $_SESSION['admin_role'], $_SESSION['admin_gate'], $_SESSION['admin_seen_at']);
            $_SESSION['user'] = [
                'name'  => $found['name'],
                'email' => $found['email'],
                'role'  => $found['role'] ?? 'buyer',
            ];
            session_bind();                          // تثبيت الجلسة على IP/المتصفح
            $login_role = (string)(($found['role'] ?? '') ?: 'customer');
            // حساب العميل العادي يرجع للموقع الأساسي بنفس شكل الزائر — مميزات الحساب في «حسابي»
            $login_dest = $login_role === 'admin' ? 'dashboard/admin.php'
                : ($login_role === 'marketer' ? 'dashboard/marketer.php' : 'index.php');
            header('Location: ' . $login_dest);
            exit;
        }
        $err = 'بيانات الدخول غير صحيحة';
    }
}
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#fed701">
<title>تسجيل الدخول | <?php echo BRAND; ?></title>


<link rel="preload" href="assets/cairo-arabic.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="assets/cairo-basic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/style.css?v=20261010-eo-fields"></head>
<body data-ui="u-803bfb02 uj7 ue1 ue7 uf9 ufv">
<?php require_once __DIR__ . '/ui-components.php'; wf_ui_sprite(); ?>
<a class="skip-link" href="#authContent">تخطي إلى نموذج الحساب</a>
<main class="auth" id="authContent" data-ui="u-5d7158f0 uem">
  <div data-ui="u-02099a9f udn uk9">
    <a href="index.php" data-ui="u-3d4dfc36 uka ue6 uej ufn"><?php echo BRAND; ?><span data-ui="u-29d95478 ukb">.</span></a>
    <h1 data-ui="u-41d9cdf8 ukc ue6 ueu">مرحباً بعودتك</h1>
    <p class="mut">أدخل بيانات حسابك وتابع طلباتك أو مبيعاتك</p>
  </div>
  <?php if ($err): ?><div class="err"><?php echo esc($err); ?></div><?php endif; ?>
  <?php if (isset($_GET['reset'])): ?><div class="ok">تم تغيير كلمة المرور بنجاح — سجّل دخولك الآن</div><?php endif; ?>
  <?php if (defined('GOOGLE_LOGIN_ON') && GOOGLE_LOGIN_ON): ?>
    <a class="btn outline" href="google_start.php?intent=login" style="width:100%;justify-content:center;margin-bottom:10px">تسجيل الدخول بجوجل</a>
    <p class="mut" style="text-align:center;margin:0 0 12px">أو</p>
  <?php endif; ?>
  <form method="post">
    <?php echo csrf_field(); ?>
    <label for="loginEmail" data-ui="u-8328352a ugv ui2 ue6 uew">البريد الإلكتروني</label>
    <input id="loginEmail" type="email" name="email" autocomplete="email" inputmode="email" data-ui="u-4f836fe8 ukd ufh" placeholder="name@example.com" required autofocus>
    <label for="loginPass" data-ui="u-8328352a ugv ui2 ue6 uew">كلمة المرور</label>
    <div data-ui="u-d27dc493 uhk">
      <input id="loginPass" type="password" name="password" autocomplete="current-password" placeholder="••••••••" required data-ui="u-ed3f6d2a uke">
      <button type="button" data-password-toggle="loginPass" aria-pressed="false" data-ui="u-05f0db69 ujp ukf ukg ukh uki ude uh2 udd udt ue6 uhm">إظهار</button>
    </div>
    <div data-ui="u-c13c8048 ug4 ukj"><a href="forgot.php" data-ui="u-5e65e7af ued ui2 ue6">نسيت كلمة السر؟</a></div>
    <button type="submit" class="btn pri" data-ui="u-5cf62db6 uf9 uem ukk">تسجيل الدخول</button>
  </form>
  <div data-ui="u-4291c26c ukl ukm ukn udn">
    <p data-ui="u-02ba62cc uko ufc">ليس لديك حساب؟ <a href="register.php" data-ui="u-0c7828ab ue6 ued">إنشاء حساب جديد</a></p>
    <p data-ui="u-bbdbc123 ui2"><a href="affiliate-register.php" data-ui="u-82e75fd0 uhn ue6">برنامج المسوقين بالعمولة</a> • <a href="index.php" class="mut">العودة للمتجر</a></p>
  </div>
</main>
<script src="assets/ui.js?v=20261018-visfeat" defer></script>
<script src="assets/tracker.js?v=20261018-visfeat" defer></script>
</body></html>
