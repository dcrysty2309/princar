<?php
// Setări comune: baza de date SQLite cu cererile și contul de administrator.
declare(strict_types=1);

const DATA_DIR = __DIR__ . '/../data';
const DB_FILE = DATA_DIR . '/leads.sqlite';
const ADMIN_FILE = DATA_DIR . '/admin.json';

const STATUSES = [
    'nou' => 'Nou',
    'sunat' => 'Sunat',
    'trimis_car' => 'Trimis la CAR CFR',
    'inscris' => 'Înscris',
    'imprumut' => 'A luat împrumut',
    'respins' => 'Nu e interesat',
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0750, true);
    }
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        nume TEXT NOT NULL,
        telefon TEXT NOT NULL,
        suma INTEGER,
        consimtamant INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT \'nou\',
        note TEXT NOT NULL DEFAULT \'\',
        sursa TEXT NOT NULL DEFAULT \'\',
        ip TEXT NOT NULL DEFAULT \'\',
        updated_at TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_leads_ip_time ON leads (ip, created_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT NOT NULL, at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        nume TEXT NOT NULL,
        email TEXT NOT NULL,
        mesaj TEXT NOT NULL,
        citit INTEGER NOT NULL DEFAULT 0,
        ip TEXT NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_ip_time ON messages (ip, created_at)');
    return $pdo;
}

function now(): string
{
    return (new DateTime('now', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d H:i:s');
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function admin_account(): ?array
{
    if (!is_file(ADMIN_FILE)) {
        return null;
    }
    $data = json_decode((string) file_get_contents(ADMIN_FILE), true);
    return is_array($data) && isset($data['user'], $data['hash']) ? $data : null;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function save_admin_account(array $acc): void
{
    file_put_contents(ADMIN_FILE, json_encode($acc, JSON_PRETTY_PRINT), LOCK_EX);
    @chmod(ADMIN_FILE, 0640);
}

// ---------- Coduri de autentificare (TOTP, standardul folosit de Google/Microsoft Authenticator) ----------

function base32_decode(string $s): string
{
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(rtrim($s, '='))) as $c) {
        $i = strpos($map, $c);
        if ($i === false) {
            continue;
        }
        $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $b) {
        if (strlen($b) === 8) {
            $out .= chr(bindec($b));
        }
    }
    return $out;
}

function totp_secret(): string
{
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = '';
    for ($i = 0; $i < 32; $i++) {
        $s .= $map[random_int(0, 31)];
    }
    return $s;
}

function totp_code(string $secret, int $step): string
{
    $h = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

// Întoarce pasul de timp potrivit (acceptă ±30 de secunde decalaj de ceas) sau null
function totp_match(string $secret, string $code): ?int
{
    if (strlen($code) !== 6) {
        return null;
    }
    $now = intdiv(time(), 30);
    foreach ([0, -1, 1] as $d) {
        if (hash_equals(totp_code($secret, $now + $d), $code)) {
            return $now + $d;
        }
    }
    return null;
}
