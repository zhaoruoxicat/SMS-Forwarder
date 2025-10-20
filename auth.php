<?php
// auth.php — 仅检测是否登录 + 工具（极简稳）
declare(strict_types=1);

// ——推荐：根据反代/CF判断 HTTPS，确保生产环境 cookie 正确
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    if (!empty($_SERVER['HTTP_CF_VISITOR']) && stripos($_SERVER['HTTP_CF_VISITOR'], '"https"') !== false) return true;
    return false;
}

// ——统一且早启动 session（放在所有页面“第一行输出之前”引入本文件）
if (session_status() !== PHP_SESSION_ACTIVE) {
    // 让 session cookie 在 HTTPS 下更稳；本地 http 可照常用
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',              // 同 host
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',           // 表单提交流畅
    ]);
    session_start();
}

/** 是否已登录 */
function is_logged_in(): bool {
    return !empty($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

/** 未登录则跳转到登录页（仅允许站内相对路径回跳） */
function require_login(): void {
    if (!is_logged_in()) {
        $u = $_SERVER['REQUEST_URI'] ?? '/sms_index.php';
        header('Location: /login.php?redirect=' . urlencode($u));
        exit;
    }
}

/** 仅允许站内路径的安全跳转（防开放重定向） */
function safe_redirect(string $cand, string $fallback = '/sms_index.php'): string {
    return ($cand !== '' && $cand[0] === '/' && !preg_match('#^//|https?://#i', $cand))
        ? $cand : $fallback;
}

/** 简单转义 */
function h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** 方便读取当前用户 */
function current_user(): array {
    return is_logged_in() ? $_SESSION['user'] : [];
}
