<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

function api_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function api_request_method(): string
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function api_get_json_body(): array
{
    static $loaded = false;
    static $payload = [];

    if ($loaded) {
        return $payload;
    }

    $loaded = true;
    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') === false) {
        return $payload;
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return $payload;
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $payload = $decoded;
    }

    return $payload;
}

function api_get_header_token(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['HTTP_X_API_TOKEN'] ?? '',
        $_SERVER['HTTP_X_TOKEN'] ?? '',
    ];

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            $candidates[] = (string)($headers['Authorization'] ?? '');
            $candidates[] = (string)($headers['authorization'] ?? '');
            $candidates[] = (string)($headers['X-API-Token'] ?? '');
            $candidates[] = (string)($headers['X-Token'] ?? '');
        }
    }

    foreach ($candidates as $header) {
        if (!is_string($header) || trim($header) === '') {
            continue;
        }

        $header = trim($header);
        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        return $header;
    }

    return '';
}

function api_extract_token(): string
{
    $headerToken = api_get_header_token();
    if ($headerToken !== '') {
        return $headerToken;
    }

    if (isset($_GET['token']) && trim((string)$_GET['token']) !== '') {
        return trim((string)$_GET['token']);
    }

    if (isset($_POST['token']) && trim((string)$_POST['token']) !== '') {
        return trim((string)$_POST['token']);
    }

    $jsonBody = api_get_json_body();
    if (isset($jsonBody['token']) && trim((string)$jsonBody['token']) !== '') {
        return trim((string)$jsonBody['token']);
    }

    return '';
}

function api_extract_action(): string
{
    if (isset($_GET['action']) && trim((string)$_GET['action']) !== '') {
        return trim((string)$_GET['action']);
    }

    if (isset($_POST['action']) && trim((string)$_POST['action']) !== '') {
        return trim((string)$_POST['action']);
    }

    $jsonBody = api_get_json_body();
    if (isset($jsonBody['action']) && trim((string)$jsonBody['action']) !== '') {
        return trim((string)$jsonBody['action']);
    }

    return '';
}

function api_require_token(PDO $pdo, string $token): array
{
    try {
        $st = $pdo->prepare(
            'SELECT id, name FROM access_tokens WHERE token = ? AND is_enabled = 1 LIMIT 1'
        );
        $st->execute([$token]);
        $tokenRow = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        api_json(500, [
            'success' => false,
            'error' => 'token_check_failed',
            'message' => 'Failed to validate token.',
            'detail' => $e->getMessage(),
        ]);
    }

    if (!$tokenRow) {
        api_json(403, [
            'success' => false,
            'error' => 'invalid_token',
            'message' => 'Token is invalid or disabled.',
        ]);
    }

    return $tokenRow;
}

function api_touch_token(PDO $pdo, int $tokenId): void
{
    if ($tokenId <= 0) {
        return;
    }

    try {
        $st = $pdo->prepare('UPDATE access_tokens SET last_used_at = NOW() WHERE id = ?');
        $st->execute([$tokenId]);
    } catch (Throwable $e) {
        // Ignore audit update failures.
    }
}

function api_output_messages(PDO $pdo, array $tokenRow): void
{
    try {
        $rows = $pdo->query(
            'SELECT id, phone, content, received_at, device FROM sms_records ORDER BY received_at DESC, id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        api_touch_token($pdo, (int)$tokenRow['id']);

        api_json(200, [
            'success' => true,
            'action' => 'list',
            'total' => count($rows),
            'server_time' => date('Y-m-d H:i:s'),
            'records' => $rows,
        ]);
    } catch (Throwable $e) {
        api_json(500, [
            'success' => false,
            'error' => 'query_failed',
            'message' => 'Failed to load sms records.',
            'detail' => $e->getMessage(),
        ]);
    }
}

function api_purge_messages(PDO $pdo, array $tokenRow): void
{
    try {
        $beforeTotal = (int)$pdo->query('SELECT COUNT(*) FROM sms_records')->fetchColumn();
        $deleteMode = 'TRUNCATE';

        try {
            $pdo->exec('TRUNCATE TABLE sms_records');
        } catch (Throwable $truncateError) {
            $deleteMode = 'DELETE';
            $pdo->exec('DELETE FROM sms_records');
        }

        $remainingTotal = (int)$pdo->query('SELECT COUNT(*) FROM sms_records')->fetchColumn();

        api_touch_token($pdo, (int)$tokenRow['id']);

        api_json(200, [
            'success' => true,
            'action' => 'purge_all',
            'message' => 'All sms records have been deleted.',
            'delete_mode' => $deleteMode,
            'before_total' => $beforeTotal,
            'remaining_total' => $remainingTotal,
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        api_json(500, [
            'success' => false,
            'error' => 'purge_failed',
            'message' => 'Failed to delete sms records.',
            'detail' => $e->getMessage(),
        ]);
    }
}

$method = api_request_method();
$action = strtolower(api_extract_action());
$token = api_extract_token();

if ($token === '') {
    api_json(401, [
        'success' => false,
        'error' => 'missing_token',
        'message' => 'Token is required.',
    ]);
}

require dirname(__DIR__) . '/db.php';

$tokenRow = api_require_token($pdo, $token);

if ($method === 'GET') {
    if ($action === '' || $action === 'list' || $action === 'messages') {
        api_output_messages($pdo, $tokenRow);
    }

    api_json(400, [
        'success' => false,
        'error' => 'invalid_action',
        'message' => 'GET only supports action=list.',
    ]);
}

if ($method === 'POST' || $method === 'DELETE') {
    if ($action === 'purge_all' || $action === 'purge' || $action === 'delete_all') {
        api_purge_messages($pdo, $tokenRow);
    }

    api_json(400, [
        'success' => false,
        'error' => 'invalid_action',
        'message' => 'POST and DELETE require action=purge_all.',
    ]);
}

api_json(405, [
    'success' => false,
    'error' => 'method_not_allowed',
    'message' => 'Only GET, POST, and DELETE are supported.',
]);
