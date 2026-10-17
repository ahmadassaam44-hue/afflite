<?php require 'config.php';
/* ============================================================================
   مسارَان منفصلان تماماً للتسجيل:
   1) مسار العميل العادي: register.php (من هيدر الموقع / صفحة الدخول)
      — حساب «عميل» (buyer) بدون أي أدوات تسويق: العميل يشوف الموقع الأساسي
        زي الزائر بالظبط، ومميزاتُه: التقييم والتعليقات + تتبع الشحنة + وصول
        أحدث العروض والأخبار (إشعارات داخل الموقع).
   2) مسار المسوق: register.php?role=marketer (من صفحات التسويق/الافلييت)
      أو رابط دعوة من مسوق: register.php?ref=CODE
      — رقم الموبايل + المحافظة + المدينة إجباري، وكود الدعوة اختياري،
        وبعد التسجيل يفتح لوحة المسوق.
   ========================================================================== */
$ref_param = trim($_GET['ref'] ?? $_POST['referred_by'] ?? '');
$is_mk_flow = (($_GET['role'] ?? '') === 'marketer')
    || (($_GET['flow'] ?? '') === 'marketer')
    || (($_POST['flow'] ?? '') === 'marketer')
    || $ref_param !== '';
$ref_owner = $ref_param !== '' ? find_marketer_by_code($ref_param) : null;
$err = '';
$old = ['name' => '', 'email' => '', 'whatsapp' => '', 'phone' => '', 'gov' => '', 'city' => '', 'referred_by' => $ref_param];

// محافظات/مدن مصر (من بيانات الشحن الرسمية) — للعميل والمسوق (قائمة مسندلة: المدينة بتتحدد حسب المحافظة)
$govs = function_exists('wf_ship_governorates') ? wf_ship_governorates() : [];
$cities_by_gov = function_exists('wf_ship_cities_map') ? wf_ship_cities_map() : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $pass = $_POST['password'] ?? '';
    $whatsapp = trim(mb_substr((string)($_POST['whatsapp'] ?? ''), 0, 30));
    $wa_digits = preg_replace('/\\D+/', '', $whatsapp);
    $phone_raw = trim((string)($_POST['phone'] ?? ''));
    $phone_digits = preg_replace('/\D+/', '', $phone_raw);
    $gov = mb_substr(trim((string)($_POST['governorate'] ?? '')), 0, 60);
    $city = mb_substr(trim((string)($_POST['city'] ?? '')), 0, 80);
    // كود الدعوة بيتقرا في مسار المسوقين فقط (مسار العميل مافيهوش كود دعوة أصلاً)
    $refby = $is_mk_flow ? trim($_POST['referred_by'] ?? '') : '';
    $old = ['name' => $name, 'email' => $email, 'whatsapp' => $whatsapp, 'phone' => $phone_raw, 'gov' => $gov, 'city' => $city, 'referred_by' => $refby];
    $pw_err = weak_password_reason($pass);
    if (!$name || !$email || $pw_err) $err = $pw_err ?: 'أكمل البيانات المطلوبة';
    elseif (mb_strlen($name) > 60) $err = 'الاسم طويل جداً';
    elseif ($is_mk_flow && $wa_digits === '') $err = 'رقم الموبايل / الواتساب مطلوب في برنامج المسوقين';
    elseif ($whatsapp !== '' && (strlen($wa_digits) < 8 || strlen($wa_digits) > 15)) $err = 'رقم واتساب غير صحيح؛ اكتبه بصيغة دولية أو محلية';
    elseif (!function_exists('valid_email') || !valid_email($email)) $err = 'صيغة البريد الإلكتروني غير صحيحة';
    elseif ($gov === '' || !in_array($gov, $govs, true)) $err = 'اختار المحافظة من القائمة';
    elseif ($city === '' || !in_array($city, wf_ship_cities($gov), true)) $err = 'اختار المدينة من القائمة';
    elseif ($is_mk_flow) {
        // برنامج المسوقين: الموبايل إجباري بالإضافة للمحافظة والمدينة
        if ($phone_digits === '') $err = 'رقم الموبايل مطلوب في برنامج المسوقين';
        elseif (!preg_match('/^01[0125][0-9]{8}$/', $phone_digits)) $err = 'رقم الموبايل غير صحيح — اكتبه بصيغة 01012345678';
    }
    if ($err === '') {
        $existing = find_user_row($email) ?: null;   // بحث واحد بالإيميل بدل تحميل كل الحسابات
        if ($existing && $is_mk_flow && (string)($existing['role'] ?? '') === 'buyer') {
            // حساب عميل موجود: تفعيل حساب المسوق على نفس البريد بدل إنشاء حساب مكرر
            if ($refby !== '' && !find_marketer_by_code($refby)) $err = 'كود الدعوة غير صحيح';
        }
        // تحويل حساب عميل لمسوق: لازم كلمة السر تكون صح (وإلا أي حد يعرف الإيميل يدخل الحساب)
        if (!$err && $existing && $is_mk_flow && (string)($existing['role'] ?? '') === 'buyer' && auth_login_profile($email, $pass)) {
            $rcode = $existing['ref_code'] ?? mk_code($existing['name'] ?? $name);
            update_user($existing['email'], function (&$u) use ($rcode, $phone_digits, $gov, $city, $whatsapp, $refby) {
                $u['role'] = 'marketer';
                $u['ref_code'] = $rcode;
                $u['phone'] = $phone_digits;
                $u['governorate'] = $gov;
                $u['city'] = $city;
                $u['whatsapp'] = $whatsapp !== '' ? $whatsapp : $phone_digits;
                if ($refby !== '' && empty($u['referred_by'])) $u['referred_by'] = $refby;
            });
            notify('admin', '🧑‍💼 تفعيل حساب مسوق لعميل موجود: ' . $existing['name'], 'dashboard/admin.php?tab=marketers');
            if ($refby && ($inv = find_marketer_by_code($refby))) notify($inv['email'], '🎉 عضو جديد: ' . $existing['name'], 'dashboard/marketer.php');
            session_harden();
            $_SESSION['user'] = ['name' => $existing['name'], 'email' => $existing['email'], 'role' => 'marketer', 'ref_code' => $rcode];
            session_bind();
            header('Location: dashboard/marketer.php'); exit;
        } elseif ($existing && $is_mk_flow && (string)($existing['role'] ?? '') === 'buyer' && $err === '') {
            $err = 'الإيميل ده مسجل كعميل قبل كده. اكتب كلمة السر بتاعة حسابك عشان نفعّل حساب المسوق عليه';
        } elseif ($existing) { $err = 'الإيميل مسجل بالفعل'; }
        elseif ($refby && !find_marketer_by_code($refby)) $err = 'كود الدعوة غير صحيح';
        if (!$err) {
            $role = $is_mk_flow ? 'marketer' : 'buyer';
            $rcode = $is_mk_flow ? mk_code($name) : '';
            $nu = [
                'name' => mb_substr($name, 0, 60), 'email' => $email,
                'role' => $role, 'at' => date('c'), 'balance' => 0, 'pending_balance' => 0, 'status' => 'approved',
                'ref_code' => $rcode, 'referred_by' => $refby,
                'margin_pct' => $is_mk_flow ? 0 : null, 'custom_comm_pct' => $is_mk_flow ? 0 : null,
                // بيانات التواصل: مسجّلة دايمًا لو اتكتبت، وإجبارية في مسار المسوق
                'phone' => $phone_digits !== '' ? $phone_digits : $whatsapp,
                'whatsapp' => $whatsapp !== '' ? $whatsapp : $phone_digits,
                'governorate' => $gov, 'city' => $city,
                'offers' => 1, // موافق على وصول أحدث العروض والأخبار
            ];
            $nu = array_filter($nu, function ($v) { return $v !== null; });
            if (!usr_create_account($email, $pass, $nu)) {
                $err = 'تعذر إنشاء الحساب دلوقتي، جرّب تاني بعد شوية';
            } else {
            if ($is_mk_flow) {
                notify('admin', '🧑‍💼 تسجيل مسوق جديد: ' . $name . ' — ' . $phone_digits . ' — ' . $gov . '/' . $city, 'dashboard/admin.php?tab=marketers');
                if ($refby && ($inv = find_marketer_by_code($refby))) notify($inv['email'], '🎉 عضو جديد: ' . $name, 'dashboard/marketer.php');
            } else {
                notify('admin', '👤 عميل جديد: ' . $name, 'dashboard/admin.php?tab=users');
                // مزايا حساب العميل: الاشتراك في العروض + رسالة ترحيب داخل الموقع
                if (function_exists('wf_offers_subscribe')) wf_offers_subscribe($email, $name, 'register');
                if (function_exists('notify_user')) notify_user($email, '🎉 تم إنشاء حسابك — تقدر دلوقتي تقيّم وتعلّق على المنتجات، وتتبع شحنتك من «طلباتي»، وهيصلك إشعار بأحدث العروض والأخبار.', 'dashboard/index.php');
            }
            session_harden();
            $_SESSION['user'] = ['name' => mb_substr($name, 0, 60), 'email' => $email, 'role' => $role] + ($is_mk_flow ? ['ref_code' => $rcode] : []);
            session_bind();
            header('Location: ' . ($is_mk_flow ? 'dashboard/marketer.php' : 'index.php?reg=1'));
            exit;
            }
        }
    }
}
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#fed701">
<title><?php echo $is_mk_flow ? 'تسجيل مسوق جديد' : 'إنشاء حساب جديد'; ?> | <?php echo BRAND; ?></title>


<link rel="preload" href="assets/cairo-arabic.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="assets/cairo-basic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/style.css?v=20261010-eo-fields"></head>
<body data-ui="u-803bfb02 uj7 ue1 ue7 uf9 ufv">
<?php require_once __DIR__ . '/ui-components.php'; wf_ui_sprite(); ?>
<a class="skip-link" href="#authContent">تخطي إلى نموذج الحساب</a>
<main class="auth" id="authContent" data-ui="u-5d7158f0 uem">
  <div data-ui="u-17f0c29e udn ufi">
    <a href="index.php" data-ui="u-3d4dfc36 uka ue6 uej ufn"><?php echo BRAND; ?><span data-ui="u-29d95478 ukb">.</span></a>
    <h1 data-ui="u-41d9cdf8 ukc ue6 ueu"><?php echo $is_mk_flow ? 'تسجيل مسوق جديد' : 'إنشاء حساب جديد'; ?></h1>
    <p class="mut"><?php echo $is_mk_flow
        ? 'سجّل كمسوق، حدد عمولتك على كل منتج بنفسك، وابدأ التسويق بمتجرك وصفحات هبوط جاهزة.'
        : 'سجّل بياناتك لمتابعة طلباتك وتتبع شحناتك — الدفع عند الاستلام في كل المحافظات.'; ?></p>
  </div>
  <?php if ($is_mk_flow && $ref_owner): ?><div class="ok">تمت دعوتك بواسطة المسوق <b><?php echo esc($ref_owner['name']); ?></b></div><?php endif; ?>
  <?php if (!$is_mk_flow): ?><div class="ok">مميزات حساب العميل: تقييم وتعليقات على المنتجات • تتبع حالة الشحنة • وصول أحدث العروض والأخبار.</div><?php endif; ?>
  <?php if ($err): ?><div class="err"><?php echo esc($err); ?></div><?php endif; ?>
  <?php if (defined('GOOGLE_LOGIN_ON') && GOOGLE_LOGIN_ON): ?>
    <a class="btn outline" href="google_start.php?intent=<?php echo $is_mk_flow ? 'marketer' : 'buyer'; ?>" style="width:100%;justify-content:center;margin-bottom:10px"><?php echo $is_mk_flow ? 'التسجيل كمسوق بجوجل' : 'التسجيل كعميل بجوجل'; ?></a>
    <p class="mut" style="text-align:center;margin:0 0 12px">أو</p>
  <?php endif; ?>
  <form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="flow" value="<?php echo $is_mk_flow ? 'marketer' : 'customer'; ?>">
    <label for="regName" data-ui="u-8328352a ugv ui2 ue6 uew">الاسم بالكامل *</label>
    <input id="regName" name="name" autocomplete="name" placeholder="مثال: أحمد محمد" value="<?php echo esc($old['name']); ?>" required autofocus>
    <label for="regEmail" data-ui="u-8328352a ugv ui2 ue6 uew">البريد الإلكتروني *</label>
    <input id="regEmail" type="email" name="email" autocomplete="email" inputmode="email" data-ui="u-4f836fe8 ukd ufh" placeholder="name@example.com" value="<?php echo esc($old['email']); ?>" required>
    <?php if ($is_mk_flow): ?>
    <label for="regPhone" data-ui="u-8328352a ugv ui2 ue6 uew">رقم الموبايل *</label>
    <input id="regPhone" type="tel" name="phone" autocomplete="tel" inputmode="numeric" maxlength="15" placeholder="01012345678" value="<?php echo esc($old['phone']); ?>" pattern="01[0125][0-9]{8}" required aria-required="true">
    <?php endif; ?>
    <div class="form-grid">
      <div>
        <label for="regGov" data-ui="u-8328352a ugv ui2 ue6 uew">المحافظة *</label>
        <select id="regGov" name="governorate" required aria-required="true" onchange="wfFillCities(this.value, '')">
          <option value="">-- اختر المحافظة --</option>
          <?php foreach ($govs as $g): ?>
          <option value="<?php echo esc($g); ?>"<?php echo $old['gov'] === $g ? ' selected' : ''; ?>><?php echo esc($g); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="regCity" data-ui="u-8328352a ugv ui2 ue6 uew">المدينة *</label>
        <select id="regCity" name="city" required aria-required="true">
          <option value="">-- اختر المدينة --</option>
        </select>
      </div>
    </div>
    <script id="wfCityData" type="application/json"><?php echo json_encode($cities_by_gov, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?></script>
    <script>
    // قائمة المدن مسندلة على المحافظة: كل محافظة ليها مدنها بس
    function wfFillCities(gov, keep){
      var map = {};
      try { map = JSON.parse(document.getElementById('wfCityData').textContent || '{}'); } catch (e) {}
      var sel = document.getElementById('regCity');
      if (!sel) return;
      sel.innerHTML = '';
      var ph = document.createElement('option'); ph.value = ''; ph.textContent = '-- اختر المدينة --'; sel.appendChild(ph);
      (map[gov] || []).forEach(function (c) {
        var o = document.createElement('option'); o.value = c; o.textContent = c;
        if (c === keep) o.selected = true;
        sel.appendChild(o);
      });
    }
    wfFillCities(document.getElementById('regGov').value, <?php echo json_encode((string)$old['city'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>);
    </script>
    <?php if ($is_mk_flow): ?>
    <label for="regWa" data-ui="u-8328352a ugv ui2 ue6 uew">رقم واتساب للتواصل *</label>
    <input id="regWa" type="tel" name="whatsapp" placeholder="رقم واتساب للتواصل" autocomplete="tel" inputmode="tel" maxlength="30" value="<?php echo esc($old['whatsapp']); ?>" required aria-required="true" data-ui="u-4f836fe8 ukd ufh">
    <?php else: ?>
    <label for="regWa" data-ui="u-8328352a ugv ui2 ue6 uew">رقم الموبايل / واتساب (اختياري)</label>
    <input id="regWa" type="tel" name="whatsapp" placeholder="010xxxxxxxx" autocomplete="tel" inputmode="tel" maxlength="30" value="<?php echo esc($old['whatsapp']); ?>" data-ui="u-4f836fe8 ukd ufh">
    <?php endif; ?>
    <label for="regPass" data-ui="u-8328352a ugv ui2 ue6 uew">كلمة المرور (8 أحرف + رقم) *</label>
    <div data-ui="u-d27dc493 uhk">
      <input id="regPass" type="password" name="password" autocomplete="new-password" placeholder="••••••••" required data-ui="u-ed3f6d2a uke">
      <button type="button" data-password-toggle="regPass" aria-pressed="false" data-ui="u-05f0db69 ujp ukf ukg ukh uki ude uh2 udd udt ue6 uhm">إظهار</button>
    </div>
    <div data-ui="u-2f7da2f9 ukp ukq uds uiz ukj"><div id="pwStr" data-ui="u-000f3f03 uhv ukr uks"></div></div>
    <?php if ($is_mk_flow): ?>
    <label for="regRef" data-ui="u-8328352a ugv ui2 ue6 uew">كود الدعوة (اختياري)</label>
    <input id="regRef" name="referred_by" value="<?php echo esc($old['referred_by']); ?>" placeholder="مثال: MKT-XXXXX" data-ui="u-4f836fe8 ukd ufh">
    <button type="submit" class="btn pri" data-ui="u-75b48f49 uf9 uem ukk ueu">إنشاء حساب المسوق الآن</button>
    <?php else: ?>
    <button type="submit" class="btn pri" data-ui="u-75b48f49 uf9 uem ukk ueu">إنشاء الحساب الآن</button>
    <?php endif; ?>
  </form>
  <div data-ui="u-01001f4f ukl udb ukn udn">
    <p data-ui="u-40fcd368 uko">لديك حساب بالفعل؟ <a href="login.php" data-ui="u-0c7828ab ue6 ued">تسجيل الدخول</a> • <a href="index.php" class="mut">العودة للمتجر</a></p>
  </div>
</main>
<script src="assets/ui.js?v=20261009-city-mk1" defer></script>
<script src="assets/tracker.js" defer></script>
</body></html>
