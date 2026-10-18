<?php
// Panoul de administrare: login, cereri (status, note, export CSV) și mesaje din formularul de contact.
declare(strict_types=1);
require __DIR__ . '/auth.php';

$error = null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($action === 'login' && $post) {
    check_csrf();
    $error = try_login(trim((string) ($_POST['user'] ?? '')), (string) ($_POST['pass'] ?? ''));
    if ($error === null || $error === 'totp') {
        header('Location: ./');
        exit;
    }
}

if ($action === 'totp' && $post) {
    check_csrf();
    $error = try_totp((string) ($_POST['code'] ?? ''));
    if ($error === null) {
        header('Location: ./');
        exit;
    }
}

if ($action === 'logout' && $post) {
    check_csrf();
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

if (!logged_in()) {
    if (!empty($_SESSION['pending']) && time() - $_SESSION['pending']['at'] <= 300) {
        render_totp($error);
    } else {
        render_login($error);
    }
    exit;
}

$pdo = db();
$tab = ($_GET['tab'] ?? $_POST['tab'] ?? '') === 'mesaje' ? 'mesaje' : 'cereri';

// ---------- Acțiuni ----------
if ($post) {
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'update') {
        $status = (string) ($_POST['status'] ?? '');
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 2000);
        if ($id > 0 && isset(STATUSES[$status])) {
            $pdo->prepare('UPDATE leads SET status = ?, note = ?, updated_at = ? WHERE id = ?')->execute([$status, $note, now(), $id]);
        }
        header('Location: ./?' . http_build_query(['q' => $_POST['q'] ?? '', 'f' => $_POST['f'] ?? '']) . '#l' . $id);
        exit;
    }
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
    }
    if ($action === 'msg_read') {
        $pdo->prepare('UPDATE messages SET citit = 1 - citit WHERE id = ?')->execute([$id]);
    }
    if ($action === 'msg_delete') {
        $pdo->prepare('DELETE FROM messages WHERE id = ?')->execute([$id]);
    }
    header('Location: ./' . ($tab === 'mesaje' ? '?tab=mesaje' : ''));
    exit;
}

$unread = (int) $pdo->query('SELECT COUNT(*) FROM messages WHERE citit = 0')->fetchColumn();

if ($tab === 'mesaje') {
    $msgs = $pdo->query('SELECT * FROM messages ORDER BY id DESC')->fetchAll();
    render_messages($msgs, $unread);
    exit;
}

// ---------- Cereri: filtre ----------
$q = trim((string) ($_GET['q'] ?? ''));
$f = (string) ($_GET['f'] ?? '');
$where = [];
$args = [];
if ($q !== '') {
    $where[] = '(nume LIKE ? OR telefon LIKE ? OR note LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%");
}
if (isset(STATUSES[$f])) {
    $where[] = 'status = ?';
    $args[] = $f;
}
$st = $pdo->prepare('SELECT * FROM leads' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC');
$st->execute($args);
$rows = $st->fetchAll();

if ($action === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cereri-princar-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Data', 'Nume', 'Telefon', 'Suma (lei)', 'Status', 'Note', 'Sursa'], ';', '"', '');
    foreach ($rows as $r) {
        // Previne executarea de formule când fișierul e deschis în Excel
        $cells = array_map(fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v,
            [$r['id'], $r['created_at'], $r['nume'], $r['telefon'], $r['suma'], STATUSES[$r['status']] ?? $r['status'], $r['note'], $r['sursa']]);
        fputcsv($out, $cells, ';', '"', '');
    }
    exit;
}

$counts = [];
foreach ($pdo->query('SELECT status, COUNT(*) c FROM leads GROUP BY status') as $c) {
    $counts[$c['status']] = (int) $c['c'];
}
$total = array_sum($counts);
$today = (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE date(created_at) = date('now','localtime')")->fetchColumn();

render_list($rows, $counts, $total, $today, $q, $f, $unread);

// ---------- Pagini ----------

function head(string $title): void
{ ?>
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · princar.ro</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css" crossorigin="anonymous">
<link rel="stylesheet" href="admin.css?v=2">
</head>
<?php }

function brand(): string
{
    return '<span class="brand">princar<span>.ro</span></span>';
}

function auth_page(string $title, string $sub, ?string $error, string $fields, string $button): void
{
    head($title); ?>
<body class="d-flex flex-column">
<div class="page page-center">
  <div class="container container-tight py-4">
    <div class="text-center mb-4"><?= brand() ?></div>
    <form method="post" class="card card-md" autocomplete="off">
      <div class="card-body">
        <h2 class="h2 text-center mb-1"><?= e($title) ?></h2>
        <p class="text-secondary text-center mb-4"><?= e($sub) ?></p>
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?= $fields ?>
        <div class="form-footer"><button class="btn btn-primary w-100"><?= e($button) ?></button></div>
      </div>
    </form>
    <p class="text-center text-secondary mt-3 small">Acces doar pentru administratori. Încercările greșite repetate blochează temporar autentificarea.</p>
  </div>
</div>
</body></html>
<?php }

function render_login(?string $error): void
{
    auth_page('Autentificare', 'Panou de administrare', $error,
        '<input type="hidden" name="action" value="login">
        <div class="mb-3"><label class="form-label">Utilizator</label><input class="form-control" name="user" autocomplete="username" required autofocus maxlength="60"></div>
        <div class="mb-2"><label class="form-label">Parolă</label><input class="form-control" name="pass" type="password" autocomplete="current-password" required maxlength="200"></div>',
        'Intră în cont');
}

function render_totp(?string $error): void
{
    auth_page('Cod de verificare', 'Introdu codul de 6 cifre din aplicația de autentificare de pe telefon.', $error,
        '<input type="hidden" name="action" value="totp">
        <div class="mb-2"><input class="form-control form-control-lg text-center totp" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus placeholder="000000"></div>',
        'Verifică');
}

function shell(string $title, string $tab, int $unread): void
{
    head($title); ?>
<body>
<div class="page">
  <header class="navbar navbar-expand-md d-print-none">
    <div class="container-xl">
      <a class="navbar-brand" href="./"><?= brand() ?></a>
      <div class="navbar-nav flex-row order-md-last">
        <form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn btn-ghost-secondary btn-sm">Ieși din cont (<?= e($_SESSION['admin']) ?>)</button></form>
      </div>
    </div>
  </header>
  <div class="page-wrapper">
    <div class="container-xl pt-3">
      <ul class="nav nav-tabs">
        <li class="nav-item"><a class="nav-link <?= $tab === 'cereri' ? 'active' : '' ?>" href="./">Cereri</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab === 'mesaje' ? 'active' : '' ?>" href="./?tab=mesaje">Mesaje<?php if ($unread): ?> <span class="badge bg-red text-red-fg ms-2"><?= $unread ?></span><?php endif; ?></a></li>
      </ul>
    </div>
    <div class="page-body mt-3">
      <div class="container-xl">
<?php }

function foot(): void
{ ?>
      </div>
    </div>
  </div>
</div>
</body></html>
<?php }

function hidden_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function render_list(array $rows, array $counts, int $total, int $today, string $q, string $f, int $unread): void
{
    shell('Cereri', 'cereri', $unread);
    $stats = [[$total, 'cereri în total'], [$today, 'azi'], [$counts['nou'] ?? 0, 'nesunate'], [($counts['inscris'] ?? 0) + ($counts['imprumut'] ?? 0), 'înscriși la CAR CFR']]; ?>
  <div class="row row-cards mb-3">
    <?php foreach ($stats as [$n, $l]): ?>
    <div class="col-6 col-lg-3"><div class="card card-sm"><div class="card-body"><div class="h1 mb-0"><?= (int) $n ?></div><div class="text-secondary"><?= e($l) ?></div></div></div></div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-body border-bottom">
      <form class="row g-2" method="get">
        <div class="col-md-5"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Caută după nume, telefon sau note"></div>
        <div class="col-md-3">
          <select class="form-select" name="f">
            <option value="">Toate statusurile</option>
            <?php foreach (STATUSES as $k => $v): ?>
              <option value="<?= e($k) ?>" <?= $f === $k ? 'selected' : '' ?>><?= e($v) ?> (<?= $counts[$k] ?? 0 ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto"><button class="btn btn-primary">Filtrează</button></div>
        <div class="col-auto"><a class="btn btn-outline-secondary" href="./?<?= e(http_build_query(['q' => $q, 'f' => $f, 'action' => 'export'])) ?>">Descarcă CSV</a></div>
      </form>
    </div>
    <?php if (!$rows): ?>
      <div class="empty"><p class="empty-title">Nicio cerere<?= ($q || $f) ? ' pentru filtrul ales' : ' încă' ?>.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>#</th><th>Data</th><th>Nume</th><th>Telefon</th><th>Sumă</th><th>Status și note</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr id="l<?= (int) $r['id'] ?>" class="st-<?= e($r['status']) ?>">
            <td class="text-secondary"><?= (int) $r['id'] ?></td>
            <td class="text-nowrap"><?= e(date('d.m.Y H:i', strtotime($r['created_at']))) ?></td>
            <td><?= e($r['nume']) ?></td>
            <td class="text-nowrap"><a href="tel:<?= e($r['telefon']) ?>"><?= e($r['telefon']) ?></a></td>
            <td class="text-nowrap"><?= $r['suma'] ? number_format((int) $r['suma'], 0, ',', '.') . ' lei' : '–' ?></td>
            <td>
              <form method="post" class="d-flex gap-2 row-form">
                <input type="hidden" name="action" value="update"><?= hidden_csrf() ?>
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="f" value="<?= e($f) ?>">
                <select class="form-select form-select-sm" name="status">
                  <?php foreach (STATUSES as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                  <?php endforeach; ?>
                </select>
                <input class="form-control form-control-sm" name="note" value="<?= e($r['note']) ?>" placeholder="Notă (ex: sună după ora 17)" maxlength="2000">
                <button class="btn btn-primary btn-sm">Salvează</button>
              </form>
            </td>
            <td>
              <form method="post"><input type="hidden" name="action" value="delete"><?= hidden_csrf() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-ghost-danger btn-sm" title="Șterge cererea">Șterge</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
<?php foot();
}

function render_messages(array $msgs, int $unread): void
{
    shell('Mesaje', 'mesaje', $unread); ?>
  <div class="card">
    <?php if (!$msgs): ?>
      <div class="empty"><p class="empty-title">Niciun mesaj încă.</p><p class="empty-subtitle text-secondary">Mesajele trimise din formularul de contact apar aici.</p></div>
    <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($msgs as $m): ?>
      <div class="list-group-item <?= $m['citit'] ? '' : 'unread' ?>">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
          <?php if (!$m['citit']): ?><span class="badge bg-blue text-blue-fg">Nou</span><?php endif; ?>
          <strong><?= e($m['nume']) ?></strong>
          <a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a>
          <span class="text-secondary ms-auto"><?= e(date('d.m.Y H:i', strtotime($m['created_at']))) ?></span>
        </div>
        <p class="msg mb-2"><?= nl2br(e($m['mesaj'])) ?></p>
        <div class="d-flex gap-2">
          <form method="post"><input type="hidden" name="action" value="msg_read"><input type="hidden" name="tab" value="mesaje"><?= hidden_csrf() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <button class="btn btn-outline-secondary btn-sm"><?= $m['citit'] ? 'Marchează necitit' : 'Marchează citit' ?></button></form>
          <form method="post"><input type="hidden" name="action" value="msg_delete"><input type="hidden" name="tab" value="mesaje"><?= hidden_csrf() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <button class="btn btn-ghost-danger btn-sm">Șterge</button></form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
<?php foot();
}
