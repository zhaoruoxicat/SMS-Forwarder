<?php
// logout.php — 退出登录
declare(strict_types=1);
require __DIR__ . '/auth.php';
$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: /login.php');
exit;
