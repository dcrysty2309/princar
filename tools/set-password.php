<?php
// Setează utilizatorul, parola și (opțional) codul din telefon pentru panoul de administrare.
// Rulează din terminal:  php tools/set-password.php <utilizator>
// Parola se tastează ascuns și se salvează doar ca hash în data/admin.json.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/bootstrap.php';

$user = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $user)) {
    fwrite(STDERR, "Folosire: php tools/set-password.php <utilizator>  (3-60 caractere: litere, cifre, . _ -)\n");
    exit(1);
}

function ask(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);
    if ($hidden) {
        shell_exec('stty -echo');
    }
    $v = rtrim((string) fgets(STDIN), "\r\n");
    if ($hidden) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $v;
}

$p1 = ask('Parola nouă (minimum 12 caractere): ', true);
if (mb_strlen($p1) < 12) {
    fwrite(STDERR, "Parola e prea scurtă.\n");
    exit(1);
}
if (ask('Repetă parola: ', true) !== $p1) {
    fwrite(STDERR, "Parolele nu coincid.\n");
    exit(1);
}

$acc = ['user' => $user, 'hash' => password_hash($p1, PASSWORD_DEFAULT)];

// Al doilea pas de verificare: cod de 6 cifre din Google Authenticator / Microsoft Authenticator
if (strtolower(ask('Activezi codul de verificare din telefon? (recomandat) [d/n]: ')) !== 'n') {
    $secret = totp_secret();
    $uri = 'otpauth://totp/' . rawurlencode('princar.ro:' . $user) . '?secret=' . $secret . '&issuer=princar.ro';
    echo "\nÎn aplicația de autentificare alege „Adaugă cont” → „Introdu cheia manual” și scrie:\n";
    echo "  Cont:  princar.ro ({$user})\n  Cheie: " . implode(' ', str_split($secret, 4)) . "\n";
    echo "(sau transformă acest link în cod QR: {$uri})\n\n";
    $ok = false;
    for ($i = 0; $i < 3 && !$ok; $i++) {
        $ok = totp_match($secret, (string) preg_replace('/\D/', '', ask('Scrie codul de 6 cifre afișat acum în aplicație: '))) !== null;
        if (!$ok) {
            echo "Cod greșit. Verifică ora telefonului și încearcă din nou.\n";
        }
    }
    if (!$ok) {
        fwrite(STDERR, "Nu am putut verifica codul. Nimic nu a fost salvat.\n");
        exit(1);
    }
    $acc['totp'] = $secret;
}

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0750, true);
}
save_admin_account($acc);
echo "Gata. Te poți loga în /admin/ cu utilizatorul „{$user}”" . (isset($acc['totp']) ? ' și codul din telefon' : '') . ".\n";
