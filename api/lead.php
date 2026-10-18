<?php
// Primește cererea din formularul de pe site și o salvează în baza de date.
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'Metodă nepermisă.']);
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

// Capcană pentru roboți: câmp ascuns care trebuie să rămână gol
if (!empty($in['website'])) {
    reply(200, ['ok' => true]);
}

$nume = trim((string) ($in['nume'] ?? ''));
$tel = preg_replace('/\s+/', '', (string) ($in['telefon'] ?? ''));
$suma = isset($in['suma']) ? (int) $in['suma'] : null;
$gdpr = !empty($in['consimtamant']);

if ($nume === '' || mb_strlen($nume) > 80) {
    reply(422, ['ok' => false, 'error' => 'Completează numele și prenumele.']);
}
if (!preg_match('/^(\+4)?07\d{8}$/', $tel)) {
    reply(422, ['ok' => false, 'error' => 'Introdu un număr de telefon valid, de forma 07xx xxx xxx.']);
}
if (!$gdpr) {
    reply(422, ['ok' => false, 'error' => 'Bifează acordul ca să te putem suna.']);
}
if ($suma !== null && ($suma < 0 || $suma > 1000000)) {
    $suma = null;
}

$pdo = db();
$ip = client_ip();

// Maximum 5 cereri pe oră de la aceeași adresă
$q = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE ip = ? AND created_at > ?");
$q->execute([$ip, (new DateTime('-1 hour', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d H:i:s')]);
if ((int) $q->fetchColumn() >= 5) {
    reply(429, ['ok' => false, 'error' => 'Ai trimis deja mai multe cereri. Te sunăm noi în curând.']);
}

$sursa = mb_substr((string) ($in['sursa'] ?? ''), 0, 200);
$st = $pdo->prepare('INSERT INTO leads (created_at, nume, telefon, suma, consimtamant, sursa, ip) VALUES (?, ?, ?, ?, 1, ?, ?)');
$st->execute([now(), $nume, $tel, $suma, $sursa, $ip]);

reply(200, ['ok' => true]);
