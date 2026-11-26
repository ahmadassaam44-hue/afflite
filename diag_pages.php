<?php
// فحص صفحات لوحة التحكم بعد التوحيد — بيتشال بعد الاستخدام
define('WF_NO_CLOSURE', true);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_GET['t'] ?? '') !== 'pg3x') { http_response_code(404); echo '{"ok":false}'; exit; }
if (!empty($_GET['cleanup'])) { echo json_encode(['ok' => @unlink(__FILE__)]); exit; }
error_reporting(E_ALL); ini_set('display_errors', '0');

$admin = null;
foreach (users_list() as $uu) { if ((string)($uu['role'] ?? '') === 'admin') { $admin = $uu; break; } }
$_SESSION['user'] = ['name' => (string)($admin['name'] ?? 'admin'), 'email' => (string)($admin['email'] ?? ''), 'role' => 'admin'];

$page = (string)($_GET['page'] ?? 'admin');
$map = [
    'admin'      => ['admin.php', ['tab' => 'overview']],
    'stores'     => ['admin.php', ['tab' => 'stores']],
    'marketers'  => ['admin.php', ['tab' => 'marketers']],
    'orders'     => ['admin.php', ['tab' => 'orders']],
    'settings'   => ['admin.php', ['tab' => 'settings']],
    'marketer'   => ['marketer.php', []],
    'vendor'     => ['vendor.php', []],
    'vendor_ok'  => ['vendor.php', ['store' => 'x']],
    'orders_page'=> ['orders.php', []],
    'layout_home'=> ['products.php', []],
];
if (!isset($map[$page])) { echo json_encode(['ok' => false, 'err' => 'unknown page']); exit; }
[$file, $get] = $map[$page];
$_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/dashboard/' . $file;
$_SERVER['PHP_SELF'] = '/dashboard/' . $file;

$out = ['ok' => true, 'page' => $page, 'file' => $file];
chdir(__DIR__ . '/dashboard');
ob_start();
try { include $file; } catch (\Throwable $e) { $out['throw'] = substr($e->getMessage(), 0, 200); }
$html = (string)ob_get_clean();
$out['html_len'] = strlen($html);
foreach (['Fatal error', 'Parse error', 'Uncaught', 'Warning:', 'Notice:', 'Deprecated:', 'undefined function'] as $n) {
    if (stripos($html, $n) !== false) $out['errors'][] = substr($html, stripos($html, $n), 160);
}
$log = __DIR__ . '/data/wf_errors.json';
$rows = is_file($log) ? json_decode((string)file_get_contents($log), true) : [];
$out['last_fatal_at'] = is_array($rows) && $rows ? (string)($rows[count($rows)-1]['at'] ?? '') : '';
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
