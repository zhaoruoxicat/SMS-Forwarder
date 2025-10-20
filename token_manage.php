<?php
// token_manage.php — 令牌管理（创建 / 启用-停用 / 重置 / 删除 + 随机生成功能）
// 依赖：db.php, auth.php, /style/tabler.min.css, /style/tabler.min.js
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

/* ---------------- Helpers ---------------- */

// 生成 16~96 长度的随机 token（十六进制截断）
function gen_token(int $len = 48): string {
  $len = max(16, min(96, $len));
  $raw = bin2hex(random_bytes(max(16, min(64, $len))));
  return substr($raw, 0, $len);
}

// 简易 PRG：把消息放到查询参数里，避免重复提交
function redirect_with_msg(?string $ok = null, ?string $err = null): void {
  $params = [];
  if ($ok !== null)  $params['ok']  = $ok;
  if ($err !== null) $params['err'] = $err;
  $qs = $params ? ('?' . http_build_query($params)) : '';
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . $qs);
  exit;
}

/* ---------------- Handle POST ---------------- */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'add') {
      $name  = trim((string)($_POST['name'] ?? ''));
      $tlen  = max(16, min(96, (int)($_POST['tlen'] ?? 48)));
      $customToken = trim((string)($_POST['custom_token'] ?? ''));
      if ($name === '') throw new RuntimeException('请填写用途备注');

      $token = $customToken !== '' ? $customToken : gen_token($tlen);
      $st = $pdo->prepare("INSERT INTO access_tokens(name, token, is_enabled) VALUES(?,?,1)");
      $st->execute([$name, $token]);

      redirect_with_msg('已创建 Token：' . $token);

    } elseif ($action === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('参数错误');
      $st = $pdo->prepare("UPDATE access_tokens SET is_enabled = 1 - is_enabled WHERE id=?");
      $st->execute([$id]);
      redirect_with_msg('状态已切换');

    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('参数错误');
      $st = $pdo->prepare("DELETE FROM access_tokens WHERE id=?");
      $st->execute([$id]);
      redirect_with_msg('已删除');

    } elseif ($action === 'reset') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('参数错误');
      $tlen = max(16, min(96, (int)($_POST['tlen'] ?? 48)));
      $customToken = trim((string)($_POST['custom_token'] ?? ''));
      $token = $customToken !== '' ? $customToken : gen_token($tlen);

      $st = $pdo->prepare("UPDATE access_tokens SET token=?, created_at=NOW() WHERE id=?");
      $st->execute([$token, $id]);
      redirect_with_msg('新 Token：' . $token);

    } else {
      throw new RuntimeException('未知操作');
    }

  } catch (Throwable $e) {
    redirect_with_msg(null, '操作失败：' . $e->getMessage());
  }
}

/* ---------------- Fetch Data ---------------- */

$rows = $pdo->query("
  SELECT id, name, token, is_enabled, created_at, last_used_at
  FROM access_tokens
  ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$ok  = isset($_GET['ok'])  ? (string)$_GET['ok']  : '';
$err = isset($_GET['err']) ? (string)$_GET['err'] : '';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Token 管理</title>
<link rel="stylesheet" href="/style/tabler.min.css">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;}
  .token-cell{max-width: 520px; overflow: hidden; text-overflow: ellipsis;}
  .nowrap{white-space:nowrap;}
  @media (max-width: 576px){
    .table-responsive{ overflow-x: auto; }
    .token-cell{ max-width: 240px; }
  }
</style>
</head>
<body>
<div class="page">
  <div class="container-xl">

    <div class="d-flex justify-content-between align-items-center my-3">
      <h2 class="page-title m-0">API Token 管理</h2>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="/index.php">返回短信列表</a>
        <a class="btn btn-outline-secondary" href="/logout.php">退出</a>
      </div>
    </div>

    <?php if ($ok): ?>
      <div class="alert alert-success" role="alert">
        <span class="mono"><?= h($ok) ?></span>
        <button class="btn btn-sm btn-outline-secondary ms-2" type="button" onclick="copyText('<?= h($ok) ?>')">复制消息</button>
      </div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert alert-danger" role="alert"><?= h($err) ?></div>
    <?php endif; ?>

    <!-- 创建新 Token -->
    <div class="card mb-3">
      <div class="card-header"><strong>创建新 Token</strong></div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <input type="hidden" name="action" value="add">

          <div class="col-sm-4">
            <label class="form-label">用途备注</label>
            <input type="text" name="name" class="form-control" placeholder="如：备用机#1 Webhook" required>
          </div>

          <div class="col-sm-2">
            <label class="form-label">Token 长度</label>
            <input type="number" class="form-control" name="tlen" value="48" min="16" max="96">
          </div>

          <div class="col-sm-4">
            <label class="form-label">自定义 Token</label>
            <div class="input-group">
              <input type="text" name="custom_token" id="custom_token_create" class="form-control" placeholder="留空自动生成">
              <button class="btn btn-outline-secondary" type="button" onclick="genRandomTokenTo('custom_token_create')">生成随机</button>
            </div>
          </div>

          <div class="col-sm-2 d-flex align-items-end">
            <button class="btn btn-primary w-100" type="submit">创建</button>
          </div>
        </form>
      </div>
    </div>

    <!-- 列表 -->
    <div class="card">
      <div class="table-responsive">
        <table class="table table-striped table-vcenter">
          <thead>
            <tr>
              <th class="nowrap" style="width:80px;">ID</th>
              <th class="nowrap" style="width:220px;">用途备注</th>
              <th>Token</th>
              <th class="nowrap" style="width:120px;">状态</th>
              <th class="nowrap" style="width:170px;">创建时间</th>
              <th class="nowrap" style="width:170px;">最后使用</th>
              <th class="nowrap" style="width:360px;">操作</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="text-center text-secondary py-5">暂无 Token</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= h($r['name']) ?></td>
              <td class="mono token-cell" title="<?= h($r['token']) ?>"><?= h($r['token']) ?></td>
              <td>
                <?php if ((int)$r['is_enabled'] === 1): ?>
                  <span class="status status-green">启用</span>
                <?php else: ?>
                  <span class="status status-red">停用</span>
                <?php endif; ?>
              </td>
              <td><?= h($r['created_at']) ?></td>
              <td><?= h($r['last_used_at'] ?? '') ?></td>
              <td>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyText('<?= h($r['token']) ?>')">复制</button>

                  <form method="post" onsubmit="return confirm('确定切换启用/停用？');">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-outline-primary" type="submit">
                      <?= (int)$r['is_enabled'] === 1 ? '停用' : '启用' ?>
                    </button>
                  </form>

                  <form method="post" onsubmit="return confirm('重置将生成新 Token，旧的将立即失效。继续？');" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

                    <div class="input-group input-group-sm" style="width: 260px;">
                      <span class="input-group-text">长度</span>
                      <input type="number" name="tlen" value="48" min="16" max="96" class="form-control" style="max-width:90px;">
                      <input type="text"   name="custom_token" id="custom_token_reset_<?= (int)$r['id'] ?>" class="form-control" placeholder="留空自动生成">
                      <button class="btn btn-outline-secondary" type="button"
                              onclick="genRandomTokenTo('custom_token_reset_<?= (int)$r['id'] ?>')">生成随机</button>
                    </div>

                    <button class="btn btn-sm btn-outline-warning" type="submit">重置</button>
                  </form>

                  <form method="post" onsubmit="return confirm('确定删除？该 Token 将不可再用');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">删除</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<script>
// 复制到剪贴板
function copyText(txt){
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(txt).then(()=>alert('已复制到剪贴板')).catch(()=>fallbackCopy(txt));
  } else {
    fallbackCopy(txt);
  }
}
function fallbackCopy(txt){
  const ta=document.createElement('textarea');
  ta.value=txt; document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); alert('已复制到剪贴板'); } catch(e){ alert('复制失败，请手动复制'); }
  ta.remove();
}

// 生成随机字符串并填充到指定输入框
function genRandomTokenTo(inputId, len){
  const el = document.getElementById(inputId);
  if (!el) return;
  const L = Number.isInteger(len) ? Math.max(16, Math.min(96, len)) : guessLengthFromNeighbors(el) || 48;
  el.value = genRandomAlnum(L);
}
function genRandomAlnum(len=48){
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  let out = '';
  const array = new Uint32Array(len);
  if (window.crypto && window.crypto.getRandomValues) {
    window.crypto.getRandomValues(array);
    for (let i=0;i<len;i++) out += chars[array[i] % chars.length];
  } else {
    for (let i=0;i<len;i++) out += chars.charAt(Math.floor(Math.random()*chars.length));
  }
  return out;
}
// 从同一 input-group 的 number 推测长度
function guessLengthFromNeighbors(el){
  const group = el.closest('.input-group');
  if (!group) return null;
  const nums = group.querySelectorAll('input[type="number"]');
  for (const n of nums) {
    const v = parseInt(n.value, 10);
    if (!isNaN(v)) return Math.max(16, Math.min(96, v));
  }
  return null;
}
</script>
<script src="/style/tabler.min.js"></script>
</body>
</html>
