<?php
// auth.php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

const USERS = [
    'admin' => ['password' => 'admin123', 'role' => 'admin', 'label' => 'Administrator'],
    'user'  => ['password' => 'user123',  'role' => 'user',  'label' => 'Scanner User'],
];

const TAB_ACCESS = [
    'admin' => ['scan', 'pending', 'upload', 'inventory', 'kpi', 'guide'],
    'user'  => ['scan', 'pending', 'guide'],
];

function current_user(): ?array {
    if (empty($_SESSION['user'])) return null;
    return USERS[(string)$_SESSION['user']] ?? null;
}

function current_role(): ?string {
    return current_user()['role'] ?? null;
}

function is_logged_in(): bool { return current_user() !== null; }

function can_access(string $tab): bool {
    $role = current_role();
    if ($role === null) return false;
    return in_array($tab, TAB_ACCESS[$role] ?? [], true);
}

function is_admin(): bool { return current_role() === 'admin'; }

function attempt_login(string $username, string $password): bool {
    $username = trim($username);
    if (!isset(USERS[$username])) return false;
    $user = USERS[$username];
    if (!hash_equals($user['password'], $password)) return false;
    session_regenerate_id(true);
    $_SESSION['user']       = $username;
    $_SESSION['role']       = $user['role'];
    $_SESSION['user_label'] = $user['label'];
    return true;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
                  $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}