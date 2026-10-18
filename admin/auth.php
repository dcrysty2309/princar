<?php
// Sesiune, login și protecție CSRF pentru panoul de administrare.
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('princar_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

const SESSION_IDLE = 7200; // deconectare după 2 ore fără activitate

function logged_in(): bool
{
    if (empty($_SESSION['admin'])) {
        return false;
    }
    if (time() - ($_SESSION['seen'] ?? 0) > SESSION_IDLE) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['seen'] = time();
    return true;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sesiune expirată. Reîncarcă pagina.');
    }
}

// Maximum 5 încercări greșite în 15 minute de la aceeași adresă
function too_many_attempts(): bool
{
    $q = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > ?');
    $q->execute([client_ip(), (new DateTime('-15 minutes', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d H:i:s')]);
    return (int) $q->fetchColumn() >= 5;
}

function try_login(string $user, string $pass): ?string
{
    $acc = admin_account();
    if (!$acc) {
        return 'Contul de administrator nu e configurat încă. Rulează tools/set-password.php.';
    }
    if (too_many_attempts()) {
        return 'Prea multe încercări greșite. Încearcă din nou peste 15 minute.';
    }
    if (hash_equals($acc['user'], $user) && password_verify($pass, $acc['hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = $acc['user'];
        $_SESSION['seen'] = time();
        db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
        return null;
    }
    db()->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, ?)')->execute([client_ip(), now()]);
    usleep(400000);
    return 'Utilizator sau parolă greșită.';
}
