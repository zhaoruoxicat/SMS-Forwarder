<?php
// sms_index.php — 短信列表：筛选 + 排序 + 分页（移除ID列 & 移动端自适应 & 一键清空）
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}

/** ----------- 本页轻量 CSRF（仅用于一键清空） ----------- */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function page_purge_token(): string {
  if (empty($_SESSION['_purge_csrf'])) {
    $_SESSION['_purge_csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['_purge_csrf'];
}
function page_purge_token_validate(?string $t): bool {
  $cur = $_SESSION['_purge_csrf'] ?? '';
  $ok  = is_string($t) && $t !== '' && is_string($cur) && $cur !== '' && hash_equals($cur, $t);
  // 命中后旋转令牌，避免重放
  if ($ok) {
    $_SESSION['_purge_csrf'] = bin2hex(random_bytes(16));
  }
  return $ok;
}

$notice = '';

/** ----------- 处理一键清空（POST + 本页CSRF + PRG） ----------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'purge_all') {
  if (!page_purge_token_validate($_POST['csrf'] ?? '')) {
    $notice = 'CSRF 校验失败';
  } else {
    try {
      // 清空表；若不想重置自增可改为：DELETE FROM sms_records
      $pdo->exec('TRUNCATE TABLE sms_records');
      // PRG：防止刷新重复提交
      header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query(array_merge($_GET, ['purged' => 1])));
      exit;
    } catch (Throwable $e) {
      $notice = '清空失败：' . $e->getMessage();
    }
  }
}
if (isset($_GET['purged']) && $_GET['purged'] == '1') {
  $notice = '已删除所有短信记录';
}

/** ----------- 参数解析 ----------- */
$device   = trim((string)($_GET['device']   ?? ''));
$phone    = trim((string)($_GET['phone']    ?? ''));
$q        = trim((string)($_GET['q']        ?? ''));   // 内容关键字
$from     = trim((string)($_GET['from']     ?? ''));   // 开始日期：YYYY-MM-DD
$to       = trim((string)($_GET['to']       ?? ''));   // 结束日期：YYYY-MM-DD
$sort     = ($_GET['sort'] ?? 'time');                 // 目前只支持 time
$dir      = strtolower($_GET['dir'] ?? 'desc');        // asc/desc
$dir      = ($dir === 'asc') ? 'asc' : 'desc';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = min(200, max(10, (int)($_GET['pp'] ?? 50))); // 每页 10~200

/** ----------- 设备下拉数据 ----------- */
$deviceRows = $pdo->query("SELECT DISTINCT device FROM sms_records WHERE device IS NOT NULL AND device<>'' ORDER BY device ASC")->fetchAll(PDO::FETCH_COLUMN);

/** ----------- 动态 WHERE 构建 ----------- */
$where = [];
$args  = [];

if ($device !== '') { $where[] = "device = ?";       $args[] = $device; }
if ($phone  !== '') { $where[] = "phone LIKE ?";     $args[] = "%{$phone}%"; }
if ($q      !== '') { $where[] = "content LIKE ?";   $args[] = "%{$q}%"; }
if ($from   !== '') { $where[] = "received_at >= ?"; $args[] = $from . ' 00:00:00'; }
if ($to     !== '') { $where[] = "received_at <= ?"; $args[] = $to   . ' 23:59:59'; }

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/** ----------- 排序 ----------- */
$orderSQL = "ORDER BY received_at " . strtoupper($dir);

/** ----------- 统计总数 & 分页 ----------- */
$countSql = "SELECT COUNT(*) FROM sms_records {$whereSQL}";
$stCount  = $pdo->prepare($countSql);
$stCount->execute($args);
$total    = (int)$stCount->fetchColumn();

$pages    = max(1, (int)ceil($total / $perPage));
$offset   = ($page - 1) * $perPage;

/** ----------- 查询数据（不再需要 id 列） ----------- */
$sql = "SELECT phone, content, received_at, device
        FROM sms_records
        {$whereSQL}
        {$orderSQL}
        LIMIT {$perPage} OFFSET {$offset}";
$st  = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

/** ----------- 构建当前查询的 base query（用于翻页/排序链接保留筛选） ----------- */
function build_query(array $merge): string {
  $params = $_GET;
  foreach ($merge as $k=>$v) { $params[$k] = $v; }
  return '?' . http_build_query($params);
}

/** ----------- 反转排序方向（仅 time） ----------- */
$toggleDir = ($dir === 'asc') ? 'desc' : 'asc';

// 本页 CSRF 值（仅用于清空）
$purgeToken = page_purge_token();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>短信记录</title>
<link rel="stylesheet" href="/style/tabler.min.css">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#1e88e5">
<link rel="apple-touch-icon" href="/icons/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

<style>
  /* 桌面端：正常表格；移动端：纵向列表，不横向滚动 */
  .content-cell { white-space: normal; }
  table.table td, table.table th { white-space: normal; }

  @media (max-width: 576px) {
    .table-responsive { overflow-x: visible; }
    table.resptable thead { display: none; }
    table.resptable tbody tr {
      display: block;
      border-top: 1px solid rgba(0,0,0,.06);
      padding: .5rem 0;
      margin: 0;
    }
    table.resptable tbody tr:first-child { border-top: none; }
    table.resptable tbody td {
      display: flex;
      padding: .25rem 0;
      border: 0 !important;
      align-items: baseline;
    }
    table.resptable tbody td::before {
      content: attr(data-label);
      flex: 0 0 auto;
      min-width: 4.5em;
      margin-right: .5rem;
      color: #6c757d;
      font-weight: 600;
      white-space: nowrap;
    }
  }
</style>
</head>
<body>
<div class="page">
  <div class="container-xl">

<div class="d-flex flex-wrap justify-content-between align-items-center my-3">
  <div class="d-flex flex-wrap gap-2 justify-content-end w-100 w-sm-auto">
    <!-- 一键清空按钮：二次确认 + POST + 本页CSRF -->
    <form id="purgeForm" method="post" class="m-0">
      <input type="hidden" name="csrf" value="<?= h($purgeToken) ?>">
      <input type="hidden" name="action" value="purge_all">
      <button type="button" class="btn btn-outline-danger w-100 w-sm-auto" onclick="confirmPurge()">删除所有</button>
    </form>

    <!-- 移动视图按钮 -->
    <a class="btn btn-outline-primary  w-sm-auto" href="/smslist.php">移动视图</a>

    <!-- 其他按钮 -->
    <a class="btn btn-outline-secondary  w-sm-auto" href="/token_manage.php">Token 管理</a>
    <a class="btn btn-outline-secondary w-sm-auto" href="/logout.php">退出</a>
  </div>
</div>



    <?php if ($notice): ?>
      <div class="alert <?= (strpos($notice,'失败')!==false?'alert-danger':'alert-success') ?>" role="alert">
        <?= h($notice) ?>
      </div>
    <?php endif; ?>

    <!-- 筛选表单 -->
    <form class="card mb-3" method="get" action="">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-sm-3 col-6">
            <label class="form-label">设备</label>
            <select name="device" class="form-select">
              <option value="">—— 全部 ——</option>
              <?php foreach($deviceRows as $d): ?>
                <option value="<?= h($d) ?>" <?= ($device===$d ? 'selected':'') ?>><?= h($d) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3 col-6">
            <label class="form-label">号码</label>
            <input type="text" name="phone" class="form-control" value="<?= h($phone) ?>" placeholder="支持模糊匹配">
          </div>
          <div class="col-sm-6">
            <label class="form-label">内容包含</label>
            <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="关键字模糊匹配">
          </div>
          <div class="col-sm-3 col-6">
            <label class="form-label">开始日期</label>
            <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
          </div>
          <div class="col-sm-3 col-6">
            <label class="form-label">结束日期</label>
            <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
          </div>
          <div class="col-sm-3 col-6">
            <label class="form-label">每页</label>
            <input type="number" min="10" max="200" name="pp" class="form-control" value="<?= h((string)$perPage) ?>">
          </div>
          <div class="col-sm-3 col-6 d-flex align-items-end">
            <div class="w-100">
              <button class="btn btn-primary w-100 mb-2" type="submit">筛选</button>
              <a class="btn btn-light w-100" href="index.php">重置</a>
            </div>
          </div>
        </div>
      </div>
    </form>

    <!-- 排序与统计 -->
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="text-secondary">
        共 <strong><?= $total ?></strong> 条，页 <strong><?= $page ?></strong> / <?= $pages ?>
      </div>
      <div>
        <a class="btn btn-outline-primary btn-sm"
           href="<?= h(build_query(['sort'=>'time','dir'=>$toggleDir,'page'=>1])) ?>">
          时间排序：<?= ($dir==='asc'?'升序 ↑':'降序 ↓') ?>
        </a>
      </div>
    </div>

    <!-- 列表：大屏为表格，小屏为“卡片式多行” -->
    <div class="card">
      <div class="table-responsive">
        <table class="table table-striped table-vcenter resptable">
          <thead>
            <tr>
              <th>时间</th>
              <th>设备</th>
              <th>号码</th>
              <th class="content-cell">内容</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="4" class="text-secondary text-center py-5">没有记录</td></tr>
          <?php else: ?>
            <?php foreach($rows as $r): ?>
              <tr>
                <td data-label="时间"><?= h($r['received_at']) ?></td>
                <td data-label="设备"><?= h($r['device']) ?></td>
                <td data-label="号码"><?= h($r['phone']) ?></td>
                <td data-label="内容" class="content-cell"><?= nl2br(h($r['content'])) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 分页 -->
    <?php if ($pages > 1): ?>
    <div class="mt-3">
      <ul class="pagination">
        <?php
          $win = 3; // 当前页左右各展示几页
          $start = max(1, $page - $win);
          $end   = min($pages, $page + $win);
          $disabled = ($page<=1)?' disabled':'';
          echo '<li class="page-item'.$disabled.'"><a class="page-link" href="'.h(build_query(['page'=>1])).'">«</a></li>';
          echo '<li class="page-item'.$disabled.'"><a class="page-link" href="'.h(build_query(['page'=>$page-1])).'">‹</a></li>';
          for ($i=$start; $i<=$end; $i++) {
            $active = ($i===$page)?' active':'';
            echo '<li class="page-item'.$active.'"><a class="page-link" href="'.h(build_query(['page'=>$i])).'">'.$i.'</a></li>';
          }
          $disabled = ($page>=$pages)?' disabled':'';
          echo '<li class="page-item'.$disabled.'"><a class="page-link" href="'.h(build_query(['page'=>$page+1])).'">›</a></li>';
          echo '<li class="page-item'.$disabled.'"><a class="page-link" href="'.h(build_query(['page'=>$pages])).'">»</a></li>';
        ?>
      </ul>
    </div>
    <?php endif; ?>

  </div>
</div>
<script src="/style/tabler.min.js"></script>
<script>
// 注册 SW（PWA）
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js', { scope: '/' })
      .catch(err => console.error('SW reg failed:', err));
  });
}

// 二次确认后提交清空表单
function confirmPurge(){
  if (!confirm('确定要删除所有短信记录吗？此操作不可恢复。')) return;
  if (!confirm('再次确认：你真的要删除所有短信记录吗？')) return;
  document.getElementById('purgeForm').submit();
}
</script>
</body>
</html>
