<?php
// login.php — 极简登录
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username !== '' && $password !== '') {
        $stmt = $pdo->prepare("SELECT id, username, password_hash, display_name, is_active FROM users WHERE username=? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
            // 可选：升级哈希
            if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
                $newHash = password_hash($password, PASSWORD_ARGON2ID);
                $upd = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
                $upd->execute([$newHash, $user['id']]);
            }

            // 登录成功
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'           => (int)$user['id'],
                'username'     => $user['username'],
                'display_name' => $user['display_name'] ?: $user['username'],
            ];

            $redirect = safe_redirect($_GET['redirect'] ?? '/index.php');
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = '用户名或密码错误';
        }
    } else {
        $error = '请输入用户名和密码';
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>登录 | 短信中心</title>
<link rel="stylesheet" href="/style/tabler.min.css">
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body class="d-flex flex-column" style="min-height:100vh;">
  <div class="page page-center">
    <div class="container-tight">
      <div class="card card-md">
        <div class="card-body">
          <h2 class="card-title text-center mb-4">登录短信中心</h2>
          <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
          <?php endif; ?>
          <form method="post" autocomplete="off">
            <div class="mb-3">
              <label class="form-label">用户名</label>
              <input type="text" name="username" class="form-control" required autofocus>
            </div>
            <div class="mb-2">
              <label class="form-label">密码</label>
              <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-footer">
              <button class="btn btn-primary w-100" type="submit">登录</button>
            </div>
          </form>
        </div>
      </div>
      <div class="text-center text-secondary mt-3">
        © <?= date('Y') ?> 短信
      </div>
    </div>
  </div>
<script src="/style/tabler.min.js"></script>
</body>
</html>
