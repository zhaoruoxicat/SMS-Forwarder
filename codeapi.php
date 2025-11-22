<?php
// codeapi.php — 返回“最新验证码短信”的最小 JSON（phone, code, content, time）
// 规则：筛选数据库里时间最新且包含 “验证码” 或英文单词 “code” 的短信；从中提取 4–6 位数字为验证码。
// 访问：/codeapi.php?token=YOUR_TOKEN  或  Authorization: Bearer YOUR_TOKEN

declare(strict_types=1);
mb_internal_encoding('UTF-8');

require __DIR__ . '/db.php';

/** 输出 JSON 并结束 */
function json_out(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 读取 Authorization/Bearer */
function get_auth_header_token(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$h) return '';
    if (stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));
    return trim($h);
}

/** 取 token：优先 GET ?token=，其次 Authorization: Bearer；禁止 Cookie 登录 */
$token = '';
if (isset($_GET['token']) && $_GET['token'] !== '') {
    $token = (string)$_GET['token'];
} elseif ($auth = get_auth_header_token()) {
    $token = $auth;
}
if ($token === '') {
    json_out(401, ['error' => 'missing_token', 'message' => '需要 token 参数或 Authorization: Bearer']);
}

/** 校验 token（复用 access_tokens 表） */
try {
    /** @var PDO $pdo */
    $st = $pdo->prepare("SELECT id FROM access_tokens WHERE token = ? AND is_enabled = 1 LIMIT 1");
    $st->execute([$token]);
    $tok = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tok) {
        json_out(403, ['error' => 'invalid_token', 'message' => 'Token 无效或已禁用']);
    }
    // 更新最近使用时间
    $upd = $pdo->prepare("UPDATE access_tokens SET last_used_at = NOW() WHERE id = ?");
    $upd->execute([(int)$tok['id']]);
} catch (Throwable $e) {
    json_out(500, ['error' => 'auth_failed', 'message' => 'Token 校验失败', 'detail' => $e->getMessage()]);
}

/**
 * 判断是否“包含验证码关键词”：
 * - 中文：出现“验证码”
 * - 英文：出现单词边界的 code（\bcode\b，大小写不敏感）
 */
function contains_otp_keyword(string $text): bool {
    if ($text === '') return false;
    if (mb_stripos($text, '验证码') !== false) return true;
    return (bool)preg_match('/\bcode\b/i', $text);
}

/** 提取 4–6 位数字验证码（返回第一处） */
function extract_4_6_digits(string $text): ?string {
    if ($text === '') return null;
    if (preg_match('/(?<!\d)(\d{4,6})(?!\d)/u', $text, $m)) {
        return $m[1];
    }
    return null;
}

try {
    // 粗筛 SQL（LIKE），再在 PHP 层做严格词边界与提取校验。
    $limit = 100;

    $sql = "
        SELECT id, phone, content, received_at
        FROM sms_records
        WHERE content LIKE '%验证码%' OR content LIKE '%code%' OR content LIKE '%Code%' OR content LIKE '%CODE%'
        ORDER BY received_at DESC
        LIMIT :lim
    ";
    $st = $pdo->prepare($sql);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $latest = null;
    foreach ($rows as $r) {
        $content = (string)($r['content'] ?? '');
        if (!contains_otp_keyword($content)) {
            continue;
        }
        $code = extract_4_6_digits($content);
        if ($code !== null) {
            $time = '';
            if (!empty($r['received_at'])) {
                $ts = strtotime($r['received_at']);
                $time = $ts ? date('Y-m-d H:i:s', $ts) : (string)$r['received_at'];
            }
            $latest = [
                'phone'   => (string)($r['phone'] ?? ''),
                'code'    => $code,
                'content' => $content,
                'time'    => $time, // 新增时间字段
            ];
            break;
        }
    }

    if (!$latest) {
        json_out(404, ['error' => 'not_found', 'message' => '未找到包含验证码关键词且可提取4-6位数字的最新短信']);
    }

    json_out(200, $latest);

} catch (Throwable $e) {
    json_out(500, ['error' => 'server_error', 'message' => '查询或解析失败', 'detail' => $e->getMessage()]);
}
