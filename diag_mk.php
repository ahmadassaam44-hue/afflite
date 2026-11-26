<?php
// اختبار صفحة المسوق على السيرفر (بعد شيم الدوال) — بيتشال بعد الاستخدام
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'mk9p') { http_response_code(404); echo '{"ok":false}'; exit; }
if (!empty($_GET['cleanup'])) { echo json_encode(['ok' => @unlink(__FILE__)]); exit; }
error_reporting(E_ALL); ini_set('display_errors', '0');
$out = ['ok' => true];
$out['has_wf_order_earned'] = function_exists('wf_order_earned');
$out['has_wf_order_commission'] = function_exists('wf_order_commission');
$out['has_wf_marketer_comm_totals'] = function_exists('wf_marketer_comm_totals');
// اختبار الدالة على طلب مسلّم وغير مسلّم
$out['earned_delivered'] = wf_order_earned(['status' => 'delivered', 'm_earn' => 25]);
$out['earned_pending'] = wf_order_earned(['status' => 'pending', 'm_earn' => 25]);

$email = 'mk-test-' . substr(md5((string)microtime(true)), 0, 6) . '@wafirinhalk.ct.ws';
$pass = 'Mk-Test-9911';
$made = usr_create_account($email, $pass, ['name' => 'مسوق اختبار', 'role' => 'marketer', 'created' => date('c')]);
$out['account_created'] = (bool)$made;
if ($made) {
    $me = ensure_user_affiliate($email);
    $out['ref_code'] = (string)($me['ref_code'] ?? '');
    $_SESSION['user'] = ['name' => 'مسوق اختبار', 'email' => $email, 'role' => 'marketer'];
    $_GET = []; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/dashboard/marketer.php';
    chdir(__DIR__ . '/dashboard');
    ob_start();
    try { include 'marketer.php'; } catch (\Throwable $e) { $out['throw'] = $e->getMessage(); }
    $html = (string)ob_get_clean();
    $out['html_len'] = strlen($html);
    $out['has_dashboard_marker'] = (strpos($html, 'wf_act') !== false) || (strpos($html, 'لوحة') !== false);
    foreach (['Fatal error', 'Uncaught', 'undefined function', 'Warning:', 'Notice:'] as $n) {
        if (stripos($html, $n) !== false) $out['errors'][] = substr($html, stripos($html, $n), 180);
    }
    if (function_exists('usr_delete_account')) $out['account_deleted'] = usr_delete_account($email);
}
$log = __DIR__ . '/data/wf_errors.json';
$rows = is_file($log) ? json_decode((string)file_get_contents($log), true) : [];
$out['last_error_at'] = is_array($rows) && $rows ? (string)($rows[count($rows) - 1]['at'] ?? '') : '';
$out['last_error_uri'] = is_array($rows) && $rows ? (string)($rows[count($rows) - 1]['uri'] ?? '') : '';
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
