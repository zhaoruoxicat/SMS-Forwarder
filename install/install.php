<?php
// install/install.php
// 安装脚本：创建数据库、导入 sql.sql、创建管理员用户、并在站点根目录生成 db.php
// 用法：把本文件放到项目 install/ 目录，确保 sql.sql 位于 install/ 或根目录，然后浏览器访问本页执行安装。

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---------------- 工具函数 ----------------

function detect_sql_path() {
    $candidates = [
        __DIR__ . '/sql.sql',
        __DIR__ . '/../sql.sql',
        __DIR__ . '/../../sql.sql',
        __DIR__ . '/../data/sql.sql',
        __DIR__ . '/data/sql.sql',
        getcwd() . '/sql.sql',
    ];
    foreach ($candidates as $p) {
        if (is_file($p)) return $p;
    }
    return false;
}

function random_password($len = 12) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    $s = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $len; $i++) $s .= $chars[random_int(0, $max)];
    return $s;
}

/**
 * 预处理 mysqldump：去掉恢复到 @OLD_* 的字符集/排序设置，避免 NULL 变量报错；
 * 并强制会话使用 utf8mb4。
 */
function normalize_mysql_dump($sql) {
    $prefix = "SET NAMES utf8mb4;\n";

    // 去掉 /*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */ 之类
    $sql = preg_replace('/\/\*![0-9]+\s+SET\s+CHARACTER_SET_CLIENT\s*=\s*@[^*]+?\*\//i', '/* stripped: CHARACTER_SET_CLIENT restore */', $sql);
    $sql = preg_replace('/\/\*![0-9]+\s+SET\s+CHARACTER_SET_RESULTS\s*=\s*@[^*]+?\*\//i', '/* stripped: CHARACTER_SET_RESULTS restore */', $sql);
    $sql = preg_replace('/\/\*![0-9]+\s+SET\s+COLLATION_CONNECTION\s*=\s*@[^*]+?\*\//i', '/* stripped: COLLATION_CONNECTION restore */', $sql);

    // 兼容非注释形式
    $sql = preg_replace('/\bSET\s+character_set_client\s*=\s*@\w+\s*;/i', '/* stripped: SET character_set_client */', $sql);
    $sql = preg_replace('/\bSET\s+character_set_results\s*=\s*@\w+\s*;/i', '/* stripped: SET character_set_results */', $sql);
    $sql = preg_replace('/\bSET\s+collation_connection\s*=\s*@\w+\s*;/i', '/* stripped: SET collation_connection */', $sql);

    // 可选：去掉 DEFINER 以避免权限问题
    $sql = preg_replace('/\sDEFINER=`[^`]+`@`[^`]+`\s/i', ' ', $sql);

    return $prefix . $sql;
}

// ---------------- 主流程 ----------------

$errors = [];
$messages = [];
$auto_password = null;
$existing_db_php = __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) 收集表单
    $db_host = trim($_POST['db_host'] ?? '127.0.0.1');
    $db_port = intval($_POST['db_port'] ?? 3306);
    $db_name = trim($_POST['db_name'] ?? 'sms_db');
    $db_user = trim($_POST['db_user'] ?? 'root');
    $db_pass = $_POST['db_pass'] ?? '';
    $admin_user = trim($_POST['admin_user'] ?? 'admin');
    $admin_pass = trim($_POST['admin_pass'] ?? '');

    if ($admin_user === '') $admin_user = 'admin';
    if ($admin_pass === '') {
        $admin_pass = random_password(12);
        $auto_password = $admin_pass;
    }

    // 2) 避免覆盖已有 db.php
    if (file_exists($existing_db_php)) {
        $errors[] = "检测到根目录已存在 db.php（{$existing_db_php}）。请先备份并删除/重命名后再重试安装。";
    } else {
        // 3) 连接 MySQL（不选库），以便创建数据库
        $mysqli = @new mysqli($db_host, $db_user, $db_pass, '', $db_port);
        if ($mysqli->connect_errno) {
            $errors[] = "无法连接到 MySQL：({$mysqli->connect_errno}) {$mysqli->connect_error}";
        } else {
            // 4) 创建数据库（若不存在）
            if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
                $errors[] = "创建/选择数据库失败：{$mysqli->error}";
            } else {
                $messages[] = "数据库 `{$db_name}` 已存在或创建成功。";

                // 5) 切换到该数据库
                if (!$mysqli->select_db($db_name)) {
                    $errors[] = "切换数据库失败：{$mysqli->error}";
                } else {
                    // 6) 导入 SQL
                    $sql_path = detect_sql_path();
                    if (!$sql_path) {
                        $errors[] = "未找到 sql.sql。请将导出的 sql.sql 放到 install/ 或网站根目录。";
                    } else {
                        $messages[] = "找到 SQL 文件：{$sql_path}";
                        $sql_contents = file_get_contents($sql_path);
                        if ($sql_contents === false || trim($sql_contents) === '') {
                            $errors[] = "读取 SQL 文件失败或内容为空。";
                        } else {
                            // 预处理 + 设定连接字符集
                            $sql_contents = normalize_mysql_dump($sql_contents);
                            $mysqli->set_charset('utf8mb4');

                            $ok_import = false;
                            // 优先 multi_query（效率高）
                            if (@$mysqli->multi_query($sql_contents)) {
                                do {
                                    if ($res = @$mysqli->store_result()) { $res->free(); }
                                    if (!$mysqli->more_results()) break;
                                } while (@$mysqli->next_result());

                                if ($mysqli->errno) {
                                    $messages[] = "multi_query 过程中出现错误：{$mysqli->error}，改用逐条执行模式。";
                                } else {
                                    $ok_import = true;
                                    $messages[] = "SQL 导入成功。";
                                }
                            } else {
                                $messages[] = "multi_query 不可用或失败，改用逐条执行模式。";
                            }

                            // 逐条执行兜底
                            if (!$ok_import) {
                                $stmts = preg_split('/;\s*(\r?\n)+/u', $sql_contents);
                                $failed = false;
                                foreach ($stmts as $stmt) {
                                    $stmt = trim($stmt);
                                    if ($stmt === '' || stripos($stmt, '/* stripped:') === 0) continue;
                                    if (!$mysqli->query($stmt)) {
                                        $errors[] = "执行 SQL 失败：{$mysqli->error}。语句（前120字符）：".substr($stmt, 0, 120);
                                        $failed = true;
                                        break;
                                    }
                                }
                                if (!$failed) $messages[] = "SQL 导入成功（逐条执行）。";
                            }
                        }
                    }

                    // 7) 创建/更新管理员用户
                    if (empty($errors)) {
                        $check = $mysqli->query("SHOW TABLES LIKE 'users'");
                        if (!$check) {
                            $errors[] = "无法检查 users 表：{$mysqli->error}";
                        } else {
                            if ($check->num_rows === 0) {
                                $errors[] = "导入后未找到 `users` 表，请确认 sql.sql 是否正确。";
                            } else {
                                // 存在则更新密码；不存在则插入
                                $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ?");
                                $stmt->bind_param('s', $admin_user);
                                $stmt->execute();
                                $stmt->store_result();
                                if ($stmt->num_rows > 0) {
                                    $stmt->free_result();
                                    $stmt->close();
                                    $pass_hash = password_hash($admin_pass, PASSWORD_DEFAULT);
                                    $u = $mysqli->prepare("UPDATE users SET password_hash = ?, is_active = 1 WHERE username = ?");
                                    $u->bind_param('ss', $pass_hash, $admin_user);
                                    if (!$u->execute()) {
                                        $errors[] = "更新已存在用户密码失败：{$u->error}";
                                    } else {
                                        $messages[] = "已存在用户 '{$admin_user}' 的密码已更新。";
                                    }
                                    $u && $u->close();
                                } else {
                                    $stmt->free_result();
                                    $stmt->close();
                                    $pass_hash = password_hash($admin_pass, PASSWORD_DEFAULT);
                                    $i = $mysqli->prepare("INSERT INTO users (username, password_hash, display_name, is_active) VALUES (?, ?, ?, 1)");
                                    $display = 'Administrator';
                                    $i->bind_param('sss', $admin_user, $pass_hash, $display);
                                    if (!$i->execute()) {
                                        $errors[] = "插入管理员用户失败：{$i->error}";
                                    } else {
                                        $messages[] = "管理员用户 '{$admin_user}' 创建成功。";
                                    }
                                    $i && $i->close();
                                }
                            }
                        }
                    }

// 8) 生成根目录 db.php
if (empty($errors)) {
    $v_host = $db_host;
    $v_port = (int)$db_port;
    $v_name = $db_name;
    $v_user = $db_user;
    $v_pass = $db_pass;

    $db_php_content = <<<PHP
<?php
// db.php
declare(strict_types=1);

\$host = '{$v_host}';   // 数据库地址
\$port = {$v_port};     // 端口
\$db   = '{$v_name}';   // 数据库名
\$user = '{$v_user}';   // 数据库用户名
\$pass = '{$v_pass}';   // 数据库密码

\$dsn = "mysql:host=\$host;port=\$port;dbname=\$db;charset=utf8mb4";
\$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

\$pdo = new PDO(\$dsn, \$user, \$pass, \$options);
PHP;

    $root_db_path = __DIR__ . '/../db.php';
    $written = @file_put_contents($root_db_path, $db_php_content);
    if ($written === false) {
        $errors[] = "写入 db.php 失败（{$root_db_path}）。请检查写权限，或手动创建并粘贴内容。";
    } else {
        @chmod($root_db_path, 0640);
        $messages[] = "db.php 已写入站点根目录：{$root_db_path}";
    }
}

                } // select_db
            } // create db
        } // connect
        if (isset($mysqli) && $mysqli instanceof mysqli) $mysqli->close();
    } // no existing db.php
} // POST
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装 - 短信转发程序</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial;background:#f7fafc;color:#222;padding:20px;}
.container{max-width:900px;margin:16px auto;background:#fff;padding:20px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,0.06);}
h1{margin-top:0;}
label{display:block;margin:8px 0 4px;font-weight:600;}
input[type=text],input[type=password],input[type=number]{width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;}
small{color:#666;}
.btn{display:inline-block;padding:8px 14px;border-radius:6px;background:#2b6cb0;color:#fff;text-decoration:none;border:none;cursor:pointer;}
.alert{padding:10px;border-radius:6px;margin:10px 0;}
.alert.error{background:#fff5f5;color:#920000;border:1px solid #f2c2c2;}
.alert.ok{background:#f0fff4;color:#0b7c3b;border:1px solid #c7f0d3;}
.code{background:#f6f8fa;border:1px solid #e1e4e8;padding:10px;border-radius:6px;font-family:monospace;white-space:pre-wrap;}
.footer{margin-top:12px;color:#666;font-size:13px;}
hr{border:none;border-top:1px solid #eee;margin:16px 0;}
</style>
</head>
<body>
<div class="container">
  <h1>短信转发 程序 安装向导</h1>
  <p>本向导将：<strong>创建数据库</strong>、<strong>导入 SQL</strong>、<strong>创建管理员</strong>，并写入根目录 <code>db.php</code>。完成后请删除 <code>install/</code> 目录。</p>

  <?php if (!empty($errors)): ?>
    <div class="alert error">
      <strong>安装失败：</strong>
      <ul>
      <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($messages)): ?>
    <div class="alert ok">
      <strong>安装信息：</strong>
      <ul>
      <?php foreach ($messages as $m): ?><li><?php echo htmlspecialchars($m); ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)): ?>
    <h2>安装已完成 ✅</h2>
    <p>管理员账号： <strong><?php echo htmlspecialchars($admin_user); ?></strong></p>
    <?php if ($auto_password !== null): ?>
      <p>自动生成的管理员密码（请立即保存）：</p>
      <div class="code"><?php echo htmlspecialchars($auto_password); ?></div>
    <?php else: ?>
      <p>你使用了自定义密码，请妥善保管。</p>
    <?php endif; ?>
    <p><strong>安全提示：</strong> 请立即删除 <code>install/</code> 目录，保留 <code>db.php</code> 于站点根目录且不要公开。</p>
    <p class="footer"><a class="btn" href="../">返回站点首页</a></p>
  <?php else: ?>
    <form method="post" autocomplete="off">
      <label>MySQL 主机 (host)</label>
      <input type="text" name="db_host" value="127.0.0.1" required>
      <label>MySQL 端口 (port)</label>
      <input type="number" name="db_port" value="3306" required>
      <label>数据库名 (database name)</label>
      <input type="text" name="db_name" value="sms_db" required>
      <label>MySQL 用户名</label>
      <input type="text" name="db_user" value="root" required>
      <label>MySQL 密码</label>
      <input type="password" name="db_pass" value="">
      <hr>
      <label>管理员用户名 (默认 admin)</label>
      <input type="text" name="admin_user" value="admin">
      <label>管理员密码（留空则自动生成）</label>
      <input type="password" name="admin_pass" value="">
      <p><small>提示：请确保 <code>sql.sql</code> 已上传到 <code>install/</code> 或站点根目录。</small></p>
      <p><button type="submit" class="btn">开始安装</button></p>
    </form>

    <h3>安装前检查</h3>
    <ul>
      <li>确认 <code>sql.sql</code> 已上传。</li>
      <li>如用普通数据库用户安装，请确保具备 <code>CREATE DATABASE</code> / <code>CREATE TABLE</code> / <code>INSERT</code> 权限；或预先创建好数据库。</li>
      <li>安装完成后请删除 <code>install/</code> 目录。</li>
    </ul>
  <?php endif; ?>
</div>
</body>
</html>
