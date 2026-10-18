<?php
// Sesiune, login în doi pași (parolă + cod din aplicația de autentificare), limitare încercări și CSRF.
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name($secure ? '__Host-princar_admin' : 'princar_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'none'; style-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
if ($secure) {
    header('Strict-Transport-Security: max-age=31536000');
}

const SESSION_IDLE = 1800;    // deconectare după 30 de minute fără activitate
const SESSION_MAX = 28800;    // și oricum după 8 ore
const MAX_IP_FAILS = 5;       // încercări greșite permise de pe o adresă în 15 minute
const MAX_ALL_FAILS = 30;     // încercări greșite permise în total într-o oră (atac din mai multe adrese)

function logged_in(): bool
{
    if (empty($_SESSION['admin'])) {
        return false;
    }
    $t = time();
    if ($t - ($_SESSION['seen'] ?? 0) > SESSION_IDLE || $t - ($_SESSION['start'] ?? 0) > SESSION_MAX
        || ($_SESSION['ua'] ?? '') !== hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['seen'] = $t;
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

function too_many_attempts(): bool
{
    $tz = new DateTimeZone('Europe/Bucharest');
    $q = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > ?');
    $q->execute([client_ip(), (new DateTime('-15 minutes', $tz))->format('Y-m-d H:i:s')]);
    if ((int) $q->fetchColumn() >= MAX_IP_FAILS) {
        return true;
    }
    $q = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE at > ?');
    $q->execute([(new DateTime('-1 hour', $tz))->format('Y-m-d H:i:s')]);
    return (int) $q->fetchColumn() >= MAX_ALL_FAILS;
}

function record_fail(): void
{
    db()->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, ?)')->execute([client_ip(), now()]);
    db()->exec("DELETE FROM login_attempts WHERE at < datetime('now','localtime','-1 day')");
    usleep(random_int(400000, 900000));
}

function finish_login(array $acc): void
{
    session_regenerate_id(true);
    unset($_SESSION['pending']);
    $_SESSION['admin'] = $acc['user'];
    $_SESSION['start'] = $_SESSION['seen'] = time();
    $_SESSION['ua'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
}

// Pasul 1: utilizator + parolă. Întoarce null la succes, 'totp' dacă urmează codul, altfel mesajul de eroare.
function try_login(string $user, string $pass): ?string
{
    $acc = admin_account();
    if (!$acc) {
        return 'Contul de administrator nu e configurat încă. Rulează tools/set-password.php.';
    }
    if (too_many_attempts()) {
        return 'Prea multe încercări greșite. Încearcă din nou mai târziu.';
    }
    if (hash_equals($acc['user'], $user) && password_verify($pass, $acc['hash'])) {
        if (!empty($acc['totp'])) {
            session_regenerate_id(true);
            $_SESSION['pending'] = ['user' => $acc['user'], 'at' => time()];
            return 'totp';
        }
        finish_login($acc);
        return null;
    }
    record_fail();
    return 'Utilizator sau parolă greșită.';
}

// Pasul 2: codul de 6 cifre din aplicația de autentificare (Google Authenticator, Microsoft Authenticator etc.)
function try_totp(string $code): ?string
{
    $acc = admin_account();
    $p = $_SESSION['pending'] ?? null;
    if (!$acc || !$p || $p['user'] !== $acc['user'] || time() - $p['at'] > 300) {
        unset($_SESSION['pending']);
        return 'Sesiunea de autentificare a expirat. Introdu din nou parola.';
    }
    if (too_many_attempts()) {
        unset($_SESSION['pending']);
        return 'Prea multe încercări greșite. Încearcă din nou mai târziu.';
    }
    $step = totp_match((string) $acc['totp'], (string) preg_replace('/\D/', '', $code));
    if ($step !== null && $step > (int) ($acc['totp_last'] ?? 0)) {
        $acc['totp_last'] = $step; // un cod nu poate fi folosit de două ori
        save_admin_account($acc);
        finish_login($acc);
        return null;
    }
    record_fail();
    return 'Cod greșit sau expirat.';
}
