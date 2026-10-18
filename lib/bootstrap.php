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
