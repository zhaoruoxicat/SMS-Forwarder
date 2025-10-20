<?php
// api_sms_receive.php — 接收短信（兼容 JSON/表单 + Token 校验 + 字段别名 + 时间规范化）
declare(strict_types=1);
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

// 只接受 POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'method not allowed']); exit;
}

// ---------- 读取请求体（JSON / 表单） ----------
$raw = file_get_contents('php://input');
$ct  = $_SERVER['CONTENT_TYPE'] ?? '';
$isJson = (stripos($ct, 'application/json') !== false);

if ($isJson) {
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        // 合并到 $_POST，后续统一从 $_POST 取
        $_POST = $json + $_POST;
    }
}

// ---------- 取 token（GET / POST / JSON） ----------
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if ($token === '') {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'missing token']); exit;
}

// ---------- 校验 token ----------
$st = $pdo->prepare("SELECT id FROM access_tokens WHERE token = ? AND is_enabled = 1 LIMIT 1");
$st->execute([$token]);
$tok = $st->fetch(PDO::FETCH_ASSOC);
if (!$tok) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'invalid token']); exit;
}

// ---------- 字段取值（做别名映射，尽量兼容不同客户端） ----------
function pick(array $src, array $keys, string $default=''): string {
    foreach ($keys as $k) {
        if (isset($src[$k]) && $src[$k] !== '') {
            return trim((string)$src[$k]);
        }
    }
    return $default;
}
$phone   = pick($_POST, ['phone','sender','from','mobile','msisdn']);
$content = pick($_POST, ['content','text','message','body','msg']);
$time    = pick($_POST, ['time','timestamp','receive_time','received_at','date','datetime']);
$device  = pick($_POST, ['device','sim','sim_slot','sim_name','device_name']);

// 必填校验
if ($phone === '' || $content === '') {
    http_response_code(400);
    echo json_encode([
        'success'=>false,
        'error'=>'missing params',
        'debug'=>[
            'got_keys'=>array_keys($_POST),
            'content_type'=>$ct,
        ]
    ]);
    exit;
}

// ---------- 规范化时间 ----------
function normalize_time(string $t): string {
    if ($t === '') {
        return date('Y-m-d H:i:s');
    }
    // 纯数字：按秒级时间戳
    if (ctype_digit($t)) {
        $ts = (int)$t;
        // 可能是毫秒时间戳
        if ($ts > 2000000000) { $ts = (int)floor($ts / 1000); }
        return date('Y-m-d H:i:s', $ts);
    }
    // 其他格式尽量解析
    $ts = strtotime($t);
    return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}
$receivedAt = normalize_time($time);

// ---------- 入库 ----------
try {
    $ins = $pdo->prepare("INSERT INTO sms_records (phone, content, received_at, device) VALUES (?,?,?,?)");
    $ins->execute([$phone, $content, $receivedAt, $device]);

    // 更新 token 最后使用时间
    $upd = $pdo->prepare("UPDATE access_tokens SET last_used_at = NOW() WHERE id = ?");
    $upd->execute([$tok['id']]);

    echo json_encode(['success'=>true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'server error','detail'=>$e->getMessage()]);
}
