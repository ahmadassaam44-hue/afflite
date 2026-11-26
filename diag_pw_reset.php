<?php
// إعادة تعيين باسورد حساب الأدمن (باسورد مؤقت يظهر مرة واحدة) — بيتشال بعد الاستخدام
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'rs3k') { http_response_code(404); echo '{"ok":false}'; exit; }
if (!empty($_GET['cleanup'])) { echo json_encode(['ok' => @unlink(__FILE__)]); exit; }
if (($_GET['go'] ?? '') !== '1') {
    $adm = [];
    foreach (users_list() as $u) { if ((string)($u['role'] ?? '') === 'admin') $adm[] = (string)$u['email']; }
    echo json_encode(['ok' => true, 'admins' => $adm], JSON_UNESCAPED_UNICODE);
    exit;
}
$email = 'admin@wafirinhalk.com';
$out = ['ok' => true, 'email' => $email];
$row = usr_find_row($email);
if (!$row) { echo json_encode(['ok' => false, 'err' => 'account not found']); exit; }
// بنينة إيه اللي متخزن فعلاً (بصمة مش باسورد)
$stored = (string)($row['encrypted_password'] ?? '');
$out['stored_value_is'] = $stored !== '' ? substr($stored, 0, 7) . '… (bcrypt hash — مش باسورد)' : '(مش ظاهر من الـ API)';
$new = 'Wf' . strtoupper(bin2hex(random_bytes(4))) . '!' . random_int(10, 99) . 'a';
$out['set_ok'] = usr_set_password($email, $new);
$lg = $out['set_ok'] ? auth_login_profile($email, $new) : null;
$out['login_with_new_ok'] = !empty($lg);
$out['new_password'] = $out['set_ok'] ? $new : '';
if ($out['set_ok'] && function_exists('log_audit')) log_audit('admin_password_reset', $email, ['by' => 'support']);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
