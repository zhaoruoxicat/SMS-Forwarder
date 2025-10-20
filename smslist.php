<?php
// smslist.php — 移动端优先的聊天气泡式短信列表（验证码复制 / 链接跳转 / 点击拨号）
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

/**
 * 自动把文本里的 URL 与中国大陆手机号变成可点击链接（先转义再替换）
 * - URL: http(s):// 或 www. 开头
 * - Phone: 11位大陆手机号 1[3-9]\d{9}
 * 依赖全局工具函数 h()（由 auth.php 提供），此处不再重复声明
 */
function auto_link_text(string $text): string {
  $escaped = h($text);

  // URL
  $patternUrl = '~(?<!["\'>])((?:https?://|www\.)[^\s<]+)~iu';
  $escaped = preg_replace_callback($patternUrl, function($m){
    $url = $m[1];
    $href = (stripos($url, 'http') === 0) ? $url : ('http://' . $url);
    return '<a href="'.h($href).'" target="_blank" rel="noopener noreferrer">'.h($url).'</a>';
  }, $escaped);

  // 中国大陆手机号（避免与数字串冲突：使用左右非数字边界）
  $patternPhone = '/(?<!\d)(1[3-9]\d{9})(?!\d)/u';
  $escaped = preg_replace_callback($patternPhone, function($m){
    $num = $m[1];
    return '<a href="tel:'.h($num).'">'.h($num).'</a>';
  }, $escaped);

  // 保留换行
  return nl2br($escaped);
}

/**
 * 提取验证码：
 * - 优先在“验证码”附近找 4–8 位数字
 * - 其次在全文找 4–8 位数字
 */
function extract_verification_code(string $text): ?string {
  $hasKw = mb_stripos($text, '验证码') !== false;

  if ($hasKw) {
    $pos = mb_stripos($text, '验证码');
    $start = max(0, $pos - 50);
    $len   = 100;
    $slice = mb_substr($text, $start, $len);
    if (preg_match('/(?<!\d)(\d{4,8})(?!\d)/u', $slice, $m)) {
      return $m[1];
    }
  }
  if (preg_match('/(?<!\d)(\d{4,8})(?!\d)/u', $text, $m)) {
    return $m[1];
  }
  return null;
}

/** ----------- 查询最近短信（分页） ----------- */
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(20, (int)($_GET['pp'] ?? 50)));
$offset  = ($page - 1) * $perPage;

$count = (int)$pdo->query("SELECT COUNT(*) FROM sms_records")->fetchColumn();

$st = $pdo->prepare("SELECT phone, content, received_at, device
                     FROM sms_records
                     ORDER BY received_at DESC
                     LIMIT :lim OFFSET :off");
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$pages = max(1, (int)ceil($count / $perPage));

/** 分页链接 */
function build_query(array $overrides = []): string {
  $q = array_merge($_GET, $overrides);
  return '?' . http_build_query($q);
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>短信会话</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/style/tabler.min.css">

<style>
/* 移动端优先：聊天气泡布局 */
:root { --topbar-h: 56px; }

body { background: #f5f7fb; }

/* 容器留出顶部固定条的空间（含 iOS 安全区） */
.chat-wrap {
  max-width: 720px;
  margin: 0 auto;
  padding: calc(var(--topbar-h) + env(safe-area-inset-top, 0px)) 12px 80px;
}

/* 顶部信息条改为 fixed，始终可见 */
.meta-bar {
  position: fixed;
  left: 0;
  right: 0;
  top: 0;
  z-index: 100;
  background: rgba(245,247,251,.92);
  backdrop-filter: saturate(180%) blur(8px);
  padding: calc(10px + env(safe-area-inset-top, 0px)) 12px 10px;
  border-bottom: 1px solid rgba(0,0,0,.06);
  box-shadow: 0 2px 10px rgba(0,0,0,.04);
}

.time-divider {
  text-align: center;
  color: #6c757d;
  font-size: 12px;
  margin: 12px 0;
}

.bubble-row {
  display: flex;
  align-items: flex-end;
  margin: 10px 0;
}

.bubble {
  display: inline-block;
  max-width: 85%;
  padding: 10px 12px;
  border-radius: 14px;
  line-height: 1.45;
  box-shadow: 0 4px 16px rgba(30,60,90,.06);
  word-wrap: break-word;
  white-space: pre-wrap;
  font-size: 15px;
}

.bubble .tools {
  margin-top: 6px;
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.left  { justify-content: flex-start; }
.right { justify-content: flex-end; }

.bubble.incoming {
  background: #ffffff;
  border-bottom-left-radius: 4px;
}
.bubble.outgoing {
  background: #dff1ff;
  border-bottom-right-radius: 4px;
}

.peer {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #6c757d;
  font-size: 12px;
  margin: 0 12px;
  flex-wrap: wrap;
}

.badge-device {
  background: #eef3ff;
  color: #3b5bdb;
  border-radius: 10px;
  padding: 2px 8px;
  font-weight: 600;
}

.copy-btn {
  border: 0;
  background: #1e88e5;
  color: #fff;
  padding: 6px 10px;
  border-radius: 10px;
  font-size: 12px;
  cursor: pointer;
}

.copy-btn.secondary {
  background: #adb5bd;
  color: #fff;
}

.ts {
  color: #6c757d;
  font-variant-numeric: tabular-nums;
}

/* 桌面更窄的气泡 */
@media (min-width: 768px) {
  .bubble { max-width: 70%; }
}
</style>
</head>
<body>

<div class="meta-bar">
  <div class="d-flex justify-content-between align-items-center container-xl" style="max-width:720px;">
    <div class="d-flex align-items-center gap-2">
      <a href="/index.php" class="btn btn-outline-primary btn-sm" style="display:flex;align-items:center;gap:4px;">
        <span style="font-size:16px;">🏠</span>
        <span class="d-none d-sm-inline">返回首页</span>
      </a>
      <div class="fw-bold">短信会话</div>
    </div>
    <div class="text-secondary small">共 <?= (int)$count ?> 条</div>
  </div>
</div>


<div class="chat-wrap" id="chatWrap">
  <?php
  // 按日期分段展示时间分隔（保留），但每条消息也会显示完整时间
  $lastDate = null;
  foreach ($rows as $r):
    $ts   = strtotime($r['received_at']);
    $date = date('Y-m-d', $ts);
    if ($lastDate !== $date) {
      echo '<div class="time-divider">'.h($date).'</div>';
      $lastDate = $date;
    }

    $contentHtml = auto_link_text($r['content']);
    $code = (mb_stripos($r['content'], '验证码') !== false) ? extract_verification_code($r['content']) : null;

    // 简单策略：全部按“incoming”显示；如你有 from/to 字段可区分左右
    $side  = 'left';
    $style = 'incoming';
  ?>
    <div class="bubble-row <?= $side ?>">
      <div>
        <div class="peer">
          <span class="badge-device"><?= h($r['device'] ?: '设备') ?></span>
          <span class="text-secondary">来自 <?= h($r['phone'] ?: '未知号码') ?></span>
          <!-- 显示完整时间：YYYY-MM-DD HH:MM:SS -->
          <span class="ts">· <?= h(date('Y-m-d H:i:s', $ts)) ?></span>
        </div>
        <div class="bubble <?= $style ?>">
          <div class="msg" data-raw="<?= h($r['content']) ?>"><?= $contentHtml ?></div>
          <div class="tools">
            <?php if ($code): ?>
              <button class="copy-btn" data-copy="<?= h($code) ?>">复制验证码</button>
            <?php endif; ?>
            <button class="copy-btn secondary" data-copy-full="<?= h($r['content']) ?>">复制全文</button>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (!$rows): ?>
    <div class="time-divider">暂无短信记录</div>
  <?php endif; ?>
</div>

<!-- 底部分页（移动端简单版） -->
<?php if ($pages > 1): ?>
<div class="container-xl my-3" style="max-width:720px;">
  <ul class="pagination justify-content-center">
    <?php
      $prevDis = ($page<=1)?' disabled':'';
      $nextDis = ($page>=$pages)?' disabled':'';
      echo '<li class="page-item'.$prevDis.'"><a class="page-link" href="'.h(build_query(['page'=>1])).'">首页</a></li>';
      echo '<li class="page-item'.$prevDis.'"><a class="page-link" href="'.h(build_query(['page'=>$page-1])).'">上一页</a></li>';
      echo '<li class="page-item active"><span class="page-link">'.(int)$page.' / '.(int)$pages.'</span></li>';
      echo '<li class="page-item'.$nextDis.'"><a class="page-link" href="'.h(build_query(['page'=>$page+1])).'">下一页</a></li>';
      echo '<li class="page-item'.$nextDis.'"><a class="page-link" href="'.h(build_query(['page'=>$pages])).'">末页</a></li>';
    ?>
  </ul>
</div>
<?php endif; ?>

<script>
// 复制功能：优先使用 Clipboard API，回退到 textarea 方案
function copyText(text) {
  if (!text) return;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(()=> {
      toast('已复制');
    }).catch(()=> fallbackCopy(text));
  } else {
    fallbackCopy(text);
  }
}
function fallbackCopy(text){
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.left = '-1000px';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); toast('已复制'); } catch(e){}
  document.body.removeChild(ta);
}

// 提示小气泡
function toast(msg){
  const t = document.createElement('div');
  t.textContent = msg;
  t.style.position='fixed';
  t.style.left='50%';
  t.style.bottom='80px';
  t.style.transform='translateX(-50%)';
  t.style.background='rgba(0,0,0,.75)';
  t.style.color='#fff';
  t.style.padding='8px 12px';
  t.style.borderRadius='12px';
  t.style.fontSize='12px';
  t.style.zIndex='9999';
  document.body.appendChild(t);
  setTimeout(()=>{ t.remove(); }, 1300);
}

document.addEventListener('click', (e)=>{
  const btn = e.target.closest('[data-copy],[data-copy-full]');
  if (btn) {
    const text = btn.getAttribute('data-copy') || btn.getAttribute('data-copy-full') || '';
    copyText(text);
  }
});
</script>
</body>
</html>
