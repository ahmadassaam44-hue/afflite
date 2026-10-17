<?php require 'config.php';
// بداية تسجيل الدخول بجوجل: بنحفظ النية (دخول/تسجيل عميل/تسجيل مسوق) في الجلسة، وبعدين Supabase بيوجّه المستخدم لجوجل
if (!defined('GOOGLE_LOGIN_ON') || !GOOGLE_LOGIN_ON || !usr_sb_ready()) { header('Location: login.php'); exit; }
$intent = (string)($_GET['intent'] ?? 'login');
if (!in_array($intent, ['login', 'buyer', 'marketer'], true)) $intent = 'login';
$_SESSION['google_intent'] = $intent;
$scheme = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
$back = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/google_callback.php';
header('Location: ' . SB_URL . '/auth/v1/authorize?provider=google&redirect_to=' . rawurlencode($back));
exit;
