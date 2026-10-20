<?php require 'config.php';
// إنهاء تسجيل جوجل: بنتحقق من التوكن مع Supabase، ولو الحساب جديد بننشئ بروفايل عميل،
// وبعدين بنفتح الجلسة زي تسجيل الدخول العادي. لو النية مسوق بنوجّه للخطوة اللي بتكمّل بيانات المسوق.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !defined('GOOGLE_LOGIN_ON') || !GOOGLE_LOGIN_ON) { header('Location: login.php'); exit; }
$intent = (string)($_SESSION['google_intent'] ?? 'login');
unset($_SESSION['google_intent']);
$u = usr_user_from_token($_POST['access_token'] ?? '');
if (!$u) { header('Location: login.php?gerr=1'); exit; }
$found = find_user_row($u['email']);
if (!$found) {
    $name = mb_substr($u['name'] !== '' ? $u['name'] : explode('@', $u['email'])[0], 0, 60);
    $prof = [
        'name' => $name, 'role' => 'marketer', 'at' => date('c'), 'balance' => 0, 'pending_balance' => 0,
        'status' => 'approved', 'ref_code' => mk_code($name), 'referred_by' => '', 'offers' => 1,
    ];
    if (!usr_profile_create_for_id($u['id'], $u['email'], $prof)) { header('Location: login.php?gerr=1'); exit; }
    notify('admin', '🧑‍💼 تسجيل مسوق جديد (جوجل): ' . $name, 'dashboard/admin.php?tab=marketers');
    $found = find_user_row($u['email']);
    if (!$found) { header('Location: login.php?gerr=1'); exit; }
}
rate_limit_reset('login');
session_harden();
unset($_SESSION['is_admin'], $_SESSION['admin_role'], $_SESSION['admin_gate'], $_SESSION['admin_seen_at']);
$role = wf_user_role($found);   // حساب العميل اتلغى: أي حساب مش أدمن = مسوق
$_SESSION['user'] = ['name' => $found['name'], 'email' => $found['email'], 'role' => $role];
session_bind();
$dest = $role === 'admin' ? 'dashboard/admin.php' : 'dashboard/marketer.php';
header('Location: ' . $dest);
exit;
