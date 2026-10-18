<?php require 'config.php';
// نسخة الكود (إعادة نشر بعد فشل رفع FTP في run 38141897142)
// الخطوة التانية من استعادة كلمة السر: الكود اللي وصل على الإيميل + كلمة السر الجديدة
// الإيميل بيتحفظ في الجلسة من forgot.php (ساعة صلاحية)، والكود بيتشيك مع Supabase قبل تغيير كلمة السر
function rst_mask_email($e) {
    $parts = explode('@', (string)$e, 2);
    if (count($parts) !== 2) return '';
    $u = $parts[0];
    return mb_substr($u, 0, 2) . str_repeat('*', max(2, mb_strlen($u) - 2)) . '@' . $parts[1];
}
$pr = $_SESSION['pw_reset'] ?? null;
$email = (is_array($pr) && (time() - (int)($pr['at'] ?? 0)) < 3600) ? (string)($pr['email'] ?? '') : '';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($email === '') {
        $msg = 'انتهت صلاحية الطلب، ابدأ من جديد.';
    } else {
        $wait = rate_limit_check('reset_code', 5, 900);
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        $p1 = (string)($_POST['p1'] ?? ''); $p2 = (string)($_POST['p2'] ?? '');
        if ($wait > 0) { $msg = '⏳ محاولات كتيرة — استنى ' . fmt_wait($wait); }
        elseif (strlen($code) !== 6) { $msg = 'اكتب الكود المكون من 6 أرقام'; }
        elseif ($p1 !== $p2) { $msg = 'الكلمتين مش متطابقتين'; }
        elseif ($pw_err = weak_password_reason($p1)) { $msg = $pw_err; }
        elseif (($ver = usr_verify_recovery_code($email, $code)) !== true) {
            $msg = $ver === 'otp_expired'
                ? 'الكود ده انتهت صلاحيته أو اتبعتله كود أحدث. اطلب كود جديد واكتب آخر كود وصلك.'
                : 'الكود غير صحيح. اكتب آخر كود وصلك على الإيميل (لو طلبت كذا كود، بس آخر واحد بيشتغل).';
        }
        elseif (!usr_set_password($email, $p1)) { $msg = 'تعذر حفظ كلمة المرور دلوقتي، جرّب تاني بعد شوية'; }
        else {
            unset($_SESSION['pw_reset']);
            rate_limit_reset('login');
            rate_limit_reset('reset_code');
            header('Location: login.php?reset=1'); exit;
        }
    }
}
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#fed701">
<title>تعيين كلمة مرور جديدة | <?php echo BRAND; ?></title>


<link rel="preload" href="assets/cairo-arabic.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="assets/cairo-basic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/style.css?v=20261010-eo-fields"></head>
<body data-ui="u-803bfb02 uj7 ue1 ue7 uf9 ufv">
<?php require_once __DIR__ . '/ui-components.php'; wf_ui_sprite(); ?>
<a class="skip-link" href="#authContent">تخطي إلى نموذج الحساب</a>
<main class="auth" id="authContent" data-ui="u-5d7158f0 uem">
  <div data-ui="u-02099a9f udn uk9">
    <a href="index.php" data-ui="u-3d4dfc36 uka ue6 uej ufn"><?php echo BRAND; ?><span data-ui="u-29d95478 ukb">.</span></a>
    <h1 data-ui="u-41d9cdf8 ukc ue6 ueu">تعيين كلمة مرور جديدة</h1>
  </div>
  <?php if ($msg): ?><div class="err"><?php echo esc($msg); ?></div><?php endif; ?>
  <?php if ($email === ''): ?>
    <div class="err" data-ui="u-5e0d7e41 udn">مفيش طلب استعادة نشط.<br><a href="forgot.php" data-ui="u-f13ddafd ue6">اطلب كود جديد</a></div>
  <?php else: ?>
    <p class="mut">بعتنالك الكود على <b dir="ltr"><?php echo esc(rst_mask_email($email)); ?></b>. اكتب الكود واختار كلمة السر الجديدة. الكود صالح ساعة.</p>
    <form method="post" action="reset.php">
      <?php echo csrf_field(); ?>
      <label for="rstCode" data-ui="u-8328352a ugv ui2 ue6 uew">الكود (6 أرقام)</label>
      <input id="rstCode" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="123456" required autofocus dir="ltr">
      <label for="rstP1" data-ui="u-8328352a ugv ui2 ue6 uew">كلمة المرور الجديدة (8 أحرف + رقم)</label>
      <input id="rstP1" data-password-strength type="password" autocomplete="new-password" name="p1" placeholder="••••••••" required><button type="button" class="btn sm outline" data-password-toggle="rstP1" aria-pressed="false">إظهار كلمة المرور</button>
      <label for="rstP2" data-ui="u-8328352a ugv ui2 ue6 uew">تأكيد كلمة المرور</label>
      <input id="rstP2" type="password" autocomplete="new-password" name="p2" placeholder="••••••••" required>
      <button class="btn pri" data-ui="u-e67ab03c uf9 uem ukk ujt">حفظ كلمة المرور</button>
    </form>
    <p class="mut">ما وصلكش الكود؟ <a href="forgot.php">ابعت كود تاني</a></p>
  <?php endif; ?>
  <div data-ui="u-4291c26c ukl ukm ukn udn">
    <a href="login.php" data-ui="u-52f2e903 ued uko ue6">العودة لتسجيل الدخول</a>
  </div>
</main>
<script src="assets/ui.js?v=20261018-visfeat" defer></script></body></html>
