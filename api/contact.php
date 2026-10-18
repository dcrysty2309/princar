<?php
// Primește mesajul din formularul de contact și îl salvează pentru panoul de administrare.
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

// Capcană pentru roboți
if (!empty($in['website'])) {
    reply(200, ['ok' => true]);
}

$nume = trim((string) ($in['nume'] ?? ''));
$email = trim((string) ($in['email'] ?? ''));
$mesaj = trim((string) ($in['mesaj'] ?? ''));

if ($nume === '' || mb_strlen($nume) > 80) {
    reply(422, ['ok' => false, 'error' => 'Completează numele.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) {
    reply(422, ['ok' => false, 'error' => 'Introdu o adresă de e-mail validă.']);
}
if (mb_strlen($mesaj) < 5 || mb_strlen($mesaj) > 2000) {
    reply(422, ['ok' => false, 'error' => 'Scrie un mesaj (maximum 2000 de caractere).']);
}
if (empty($in['gdpr'])) {
    reply(422, ['ok' => false, 'error' => 'Bifează acordul pentru prelucrarea datelor.']);
}

$pdo = db();
$ip = client_ip();

// Maximum 3 mesaje pe oră de la aceeași adresă
$q = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE ip = ? AND created_at > ?');
$q->execute([$ip, (new DateTime('-1 hour', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d H:i:s')]);
if ((int) $q->fetchColumn() >= 3) {
    reply(429, ['ok' => false, 'error' => 'Ai trimis deja mai multe mesaje. Îți răspundem în curând.']);
}

$pdo->prepare('INSERT INTO messages (created_at, nume, email, mesaj, ip) VALUES (?, ?, ?, ?, ?)')
    ->execute([now(), $nume, $email, $mesaj, $ip]);

reply(200, ['ok' => true]);
