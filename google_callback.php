<?php require 'config.php';
// صفحة الاستقبال بعد جوجل: التوكن بييجي في الـ fragment، فالـ JS بياخده ويبعته لصفحة الإنهاء بـ POST
?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>تسجيل الدخول | <?php echo BRAND; ?></title>
<link rel="stylesheet" href="assets/style.css?v=20261010-eo-fields"></head>
<body>
<main class="auth" id="authContent">
  <p class="mut" id="gMsg">جاري تسجيل دخولك بجوجل…</p>
  <form id="gForm" method="post" action="google_finish.php" hidden>
    <?php echo csrf_field(); ?>
    <input type="hidden" id="gTok" name="access_token" value="">
  </form>
</main>
<script>
(function () {
  var h = new URLSearchParams((location.hash || '').replace(/^#/, ''));
  var q = new URLSearchParams(location.search || '');
  var t = h.get('access_token');
  // سبب الفشل (لو جه من Supabase أو جوجل): بيتعرض للمستخدم عشان نعرف المشكلة، من غير أي توكن
  var errText = h.get('error_description') || h.get('error') || q.get('error_description') || q.get('error') || '';
  history.replaceState(null, '', location.pathname);
  if (t) {
    document.getElementById('gTok').value = t;
    document.getElementById('gForm').submit();
  } else {
    var msg = document.getElementById('gMsg');
    msg.textContent = 'تعذر تسجيل الدخول بجوجل. جرّب تاني.';
    if (errText) {
      var d = document.createElement('p');
      d.className = 'mut';
      d.style.direction = 'ltr';
      d.textContent = 'سبب الفشل: ' + errText.replace(/\+/g, ' ');
      msg.parentNode.insertBefore(d, msg.nextSibling);
      return;
    }
    setTimeout(function () { location.href = 'login.php'; }, 2500);
  }
})();
</script>
</body></html>
