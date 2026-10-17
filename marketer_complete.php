<?php require 'config.php';
// إكمال بيانات المسوق: بيتفتح للعميل اللي دخل (بجوجل أو بالإيميل) ومش بيطلب كلمة سر، لأن الجلسة نفسها بتثبت الهوية
$cu = current_user();
if (!$cu) { header('Location: login.php'); exit; }
$cur = find_user_row($cu['email']);
if (!$cur) { header('Location: login.php'); exit; }
if (($cur['role'] ?? '') === 'marketer') { header('Location: dashboard/marketer.php'); exit; }
if (($cur['role'] ?? '') !== 'buyer') { header('Location: index.php'); exit; }

$govs = function_exists('wf_ship_governorates') ? wf_ship_governorates() : [];
$cities_by_gov = function_exists('wf_ship_cities_map') ? wf_ship_cities_map() : [];
$err = '';
$old = [
    'phone' => (string)($cur['phone'] ?? ''), 'whatsapp' => (string)($cur['whatsapp'] ?? ''),
    'gov' => (string)($cur['governorate'] ?? ''), 'city' => (string)($cur['city'] ?? ''), 'referred_by' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone_digits = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
    $wa_digits = preg_replace('/\D+/', '', (string)($_POST['whatsapp'] ?? ''));
    $gov = mb_substr(trim((string)($_POST['governorate'] ?? '')), 0, 60);
    $city = mb_substr(trim((string)($_POST['city'] ?? '')), 0, 80);
    $refby = trim((string)($_POST['referred_by'] ?? ''));
    $old = ['phone' => $phone_digits, 'whatsapp' => $wa_digits, 'gov' => $gov, 'city' => $city, 'referred_by' => $refby];
    if (!csrf_ok()) $err = 'انتهت صلاحية الصفحة، حدّثها وجرّب تاني';
    elseif (!preg_match('/^01[0125][0-9]{8}$/', $phone_digits)) $err = 'رقم الموبايل غير صحيح — اكتبه بصيغة 01012345678';
    elseif ($wa_digits !== '' && (strlen($wa_digits) < 8 || strlen($wa_digits) > 15)) $err = 'رقم واتساب غير صحيح';
    elseif ($gov === '' || !in_array($gov, $govs, true)) $err = 'اختار المحافظة من القائمة';
    elseif ($city === '' || !in_array($city, wf_ship_cities($gov), true)) $err = 'اختار المدينة من القائمة';
    elseif ($refby !== '' && !find_marketer_by_code($refby)) $err = 'كود الدعوة غير صحيح';
    else {
        $rcode = mk_code($cur['name'] ?? 'MK');
        $ok = update_user($cur['email'], function (&$x) use ($rcode, $phone_digits, $wa_digits, $gov, $city, $refby) {
            $x['role'] = 'marketer';
            $x['ref_code'] = $x['ref_code'] ?? $rcode;
            $x['margin_pct'] = 0;
            $x['custom_comm_pct'] = 0;
            $x['phone'] = $phone_digits;
            $x['whatsapp'] = $wa_digits !== '' ? $wa_digits : $phone_digits;
            $x['governorate'] = $gov;
            $x['city'] = $city;
            if ($refby !== '' && empty($x['referred_by'])) $x['referred_by'] = $refby;
        });
        if (!$ok) {
            $err = 'تعذر حفظ البيانات دلوقتي، جرّب تاني بعد شوية';
        } else {
            notify('admin', '🧑‍💼 تفعيل حساب مسوق (من حساب عميل): ' . ($cur['name'] ?? '') . ' — ' . $phone_digits . ' — ' . $gov . '/' . $city, 'dashboard/admin.php?tab=marketers');
            if ($refby !== '' && ($inv = find_marketer_by_code($refby))) notify($inv['email'], '🎉 عضو جديد: ' . ($cur['name'] ?? ''), 'dashboard/marketer.php');
            session_harden();
            $_SESSION['user'] = ['name' => $cur['name'], 'email' => $cur['email'], 'role' => 'marketer', 'ref_code' => $rcode];
            session_bind();
            header('Location: dashboard/marketer.php'); exit;
        }
    }
}
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>إكمال بيانات المسوق | <?php echo BRAND; ?></title>
<link rel="stylesheet" href="assets/style.css?v=20261010-eo-fields"></head>
<body>
<?php require_once __DIR__ . '/ui-components.php'; wf_ui_sprite(); ?>
<main class="auth" id="authContent">
  <div>
    <h1>كمّل بياناتك كمسوق</h1>
    <p class="mut">حسابك كعميل جاهز. ابعت بيانات التواصل عشان تبدأ التسويق بالعمولة من لوحة المسوق.</p>
  </div>
  <?php if ($err): ?><div class="err"><?php echo esc($err); ?></div><?php endif; ?>
  <form method="post">
    <?php echo csrf_field(); ?>
    <label for="mkPhone">رقم الموبايل *</label>
    <input id="mkPhone" type="tel" name="phone" inputmode="numeric" maxlength="15" placeholder="01012345678" value="<?php echo esc($old['phone']); ?>" pattern="01[0125][0-9]{8}" required>
    <label for="mkWa">رقم الواتساب (لو مختلف عن الموبايل)</label>
    <input id="mkWa" type="tel" name="whatsapp" inputmode="numeric" maxlength="15" placeholder="اختياري" value="<?php echo esc($old['whatsapp']); ?>">
    <div class="form-grid">
      <div>
        <label for="mkGov">المحافظة *</label>
        <select id="mkGov" name="governorate" required onchange="mkFillCities(this.value, '')">
          <option value="">-- اختر المحافظة --</option>
          <?php foreach ($govs as $g): ?>
          <option value="<?php echo esc($g); ?>"<?php echo $old['gov'] === $g ? ' selected' : ''; ?>><?php echo esc($g); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="mkCity">المدينة *</label>
        <select id="mkCity" name="city" required><option value="">-- اختر المدينة --</option></select>
      </div>
    </div>
    <script id="mkCityData" type="application/json"><?php echo json_encode($cities_by_gov, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?></script>
    <script>
    function mkFillCities(gov, keep) {
      var map = {};
      try { map = JSON.parse(document.getElementById('mkCityData').textContent || '{}'); } catch (e) {}
      var sel = document.getElementById('mkCity');
      sel.innerHTML = '<option value="">-- اختر المدينة --</option>';
      (map[gov] || []).forEach(function (c) {
        var o = document.createElement('option');
        o.value = c; o.textContent = c;
        if (c === keep) o.selected = true;
        sel.appendChild(o);
      });
    }
    mkFillCities(document.getElementById('mkGov').value, <?php echo json_encode($old['city'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>);
    </script>
    <label for="mkRef">كود الدعوة (اختياري)</label>
    <input id="mkRef" name="referred_by" placeholder="MK-XXXX-0000" value="<?php echo esc($old['referred_by']); ?>">
    <button type="submit" class="btn pri">حفظ والدخول للوحة المسوق</button>
  </form>
</main>
</body></html>
