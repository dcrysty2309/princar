<?php
// Setează utilizatorul și parola pentru panoul de administrare.
// Rulează din terminal:  php tools/set-password.php <utilizator>
// Parola se tastează ascuns și se salvează doar ca hash în data/admin.json.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/bootstrap.php';

$user = $argv[1] ?? '';
if ($user === '') {
    fwrite(STDERR, "Folosire: php tools/set-password.php <utilizator>\n");
    exit(1);
}

function ask_hidden(string $label): string
{
    fwrite(STDOUT, $label);
    shell_exec('stty -echo');
    $v = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
    return $v;
}

$p1 = ask_hidden('Parola nouă (minimum 10 caractere): ');
if (mb_strlen($p1) < 10) {
    fwrite(STDERR, "Parola e prea scurtă.\n");
    exit(1);
}
$p2 = ask_hidden('Repetă parola: ');
if ($p1 !== $p2) {
    fwrite(STDERR, "Parolele nu coincid.\n");
    exit(1);
}

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0750, true);
}
file_put_contents(ADMIN_FILE, json_encode(['user' => $user, 'hash' => password_hash($p1, PASSWORD_DEFAULT)], JSON_PRETTY_PRINT));
chmod(ADMIN_FILE, 0640);
echo "Gata. Te poți loga în /admin/ cu utilizatorul „{$user}”.\n";
