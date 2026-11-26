<?php
// اختبار لوحة الأدمن: تاب المتاجر + كارت كلمة السر + باسورد مؤقت لحساب
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'ad7m') { http_response_code(404); echo '{"ok":false}'; exit; }
if (!empty($_GET['cleanup'])) { echo json_encode(['ok' => @unlink(__FILE__)]); exit; }
error_reporting(E_ALL); ini_set('display_errors', '0');

$admin = null;
foreach (users_list() as $uu) { if ((string)($uu['role'] ?? '') === 'admin') { $admin = $uu; break; } }
if (!$admin) { echo json_encode(['ok' => false, 'err' => 'no admin']); exit; }
$_SESSION['user'] = ['name' => (string)($admin['name'] ?? 'admin'), 'email' => (string)$admin['email'], 'role' => 'admin'];

$mode = (string)($_GET['mode'] ?? 'marketers');
$out = ['ok' => true, 'mode' => $mode];
$tmp_user = '';

if ($mode === 'temp_pass') {
    $tmp_user = 'pwtest-' . substr(md5((string)microtime(true)), 0, 6) . '@wafirinhalk.ct.ws';
    if (!usr_create_account($tmp_user, 'Start-Pass-991', ['name' => 'اختبار باسورد', 'role' => 'marketer', 'created' => date('c')])) {
        echo json_encode(['ok' => false, 'err' => 'create failed']); exit;
    }
    $_POST = ['csrf_token' => csrf_token(), 'wf_pw_act' => 'temp_pass', 'wf_pw_email' => $tmp_user];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['tab' => 'marketers'];
}

$tab_get = $mode === 'stores' ? 'stores' : 'marketers';
if ($mode !== 'temp_pass') { $_GET = ['tab' => $tab_get]; $_SERVER['REQUEST_METHOD'] = 'GET'; }
$_SERVER['SCRIPT_NAME'] = '/dashboard/admin.php';
$_SERVER['PHP_SELF'] = '/dashboard/admin.php';

chdir(__DIR__ . '/dashboard');
ob_start();
try { include 'admin.php'; } catch (\Throwable $e) { $out['throw'] = $e->getMessage(); }
$html = (string)ob_get_clean();
$out['html_len'] = strlen($html);
$out['has_pw_card'] = (strpos($html, 'كلمة سر أي حساب') !== false);
$out['has_stores_tab'] = (strpos($html, 'إدارة المتاجر / إنشاء متجر جديد') !== false);
$out['has_temp_msg'] = (strpos($html, 'باسورد مؤقت لحساب') !== false);
if (preg_match('/>(Wf[0-9A-F]{6}!\d{2})</', $html, $m)) {
    $out['temp_password_shown'] = true;
    $lg = function_exists('auth_login_profile') ? auth_login_profile($tmp_user, $m[1]) : null;
    $out['login_with_temp_ok'] = !empty($lg);
} else {
    $out['temp_password_shown'] = false;
}
foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Uncaught'] as $needle) {
    if (stripos($html, $needle) !== false) { $out['errors'][] = substr($html, stripos($html, $needle), 200); }
}
if ($tmp_user !== '' && function_exists('usr_delete_account')) $out['test_user_deleted'] = usr_delete_account($tmp_user);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
