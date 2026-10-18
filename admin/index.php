<?php
// Panoul de administrare: login, lista de cereri, status, note, export CSV.
declare(strict_types=1);
require __DIR__ . '/auth.php';

$error = null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $error = try_login(trim((string) ($_POST['user'] ?? '')), (string) ($_POST['pass'] ?? ''));
    if ($error === null) {
        header('Location: ./');
        exit;
    }
}

if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

if (!logged_in()) {
    render_login($error);
    exit;
}

$pdo = db();

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 2000);
    if ($id > 0 && isset(STATUSES[$status])) {
        $pdo->prepare('UPDATE leads SET status = ?, note = ?, updated_at = ? WHERE id = ?')->execute([$status, $note, now(), $id]);
    }
    header('Location: ./?' . http_build_query(['q' => $_POST['q'] ?? '', 'f' => $_POST['f'] ?? '']) . '#l' . $id);
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
    header('Location: ./');
    exit;
}

// Filtre
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
$sql = 'SELECT * FROM leads' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC';
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

if ($action === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cereri-princar-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Data', 'Nume', 'Telefon', 'Suma (lei)', 'Status', 'Note', 'Sursa'], ';', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['created_at'], $r['nume'], $r['telefon'], $r['suma'], STATUSES[$r['status']] ?? $r['status'], $r['note'], $r['sursa']], ';', '"', '');
    }
    exit;
}

$counts = [];
foreach ($pdo->query('SELECT status, COUNT(*) c FROM leads GROUP BY status') as $c) {
    $counts[$c['status']] = (int) $c['c'];
}
$total = array_sum($counts);
$today = (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE date(created_at) = date('now','localtime')")->fetchColumn();

render_list($rows, $counts, $total, $today, $q, $f);

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
<link rel="stylesheet" href="admin.css?v=1">
</head>
<body>
<?php }

function render_login(?string $error): void
{
    head('Autentificare'); ?>
<main class="login">
  <form method="post" class="card">
    <h1>princar<span>.ro</span></h1>
    <p class="muted">Panou de administrare</p>
    <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>Utilizator<input name="user" autocomplete="username" required autofocus></label>
    <label>Parolă<input name="pass" type="password" autocomplete="current-password" required></label>
    <button class="btn">Intră în cont</button>
  </form>
</main>
</body></html>
<?php }

function render_list(array $rows, array $counts, int $total, int $today, string $q, string $f): void
{
    head('Cereri'); ?>
<header class="bar">
  <strong>princar<span>.ro</span> · Cereri</strong>
  <form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="link">Ieși din cont (<?= e($_SESSION['admin']) ?>)</button></form>
</header>
<main class="wrap">
  <section class="stats">
    <div><b><?= $total ?></b><span>cereri în total</span></div>
    <div><b><?= $today ?></b><span>azi</span></div>
    <div><b><?= $counts['nou'] ?? 0 ?></b><span>nesunate</span></div>
    <div><b><?= ($counts['inscris'] ?? 0) + ($counts['imprumut'] ?? 0) ?></b><span>înscriși la CAR CFR</span></div>
  </section>

  <form class="filters" method="get">
    <input name="q" value="<?= e($q) ?>" placeholder="Caută după nume, telefon sau note">
    <select name="f">
      <option value="">Toate statusurile</option>
      <?php foreach (STATUSES as $k => $v): ?>
        <option value="<?= e($k) ?>" <?= $f === $k ? 'selected' : '' ?>><?= e($v) ?> (<?= $counts[$k] ?? 0 ?>)</option>
      <?php endforeach; ?>
    </select>
    <button class="btn">Filtrează</button>
    <a class="btn ghost" href="./?<?= e(http_build_query(['q' => $q, 'f' => $f, 'action' => 'export'])) ?>">Descarcă CSV</a>
  </form>

  <?php if (!$rows): ?>
    <p class="empty">Nicio cerere<?= ($q || $f) ? ' pentru filtrul ales' : ' încă' ?>.</p>
  <?php else: ?>
  <div class="table">
    <table>
      <thead><tr><th>#</th><th>Data</th><th>Nume</th><th>Telefon</th><th>Sumă</th><th>Status și note</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr id="l<?= (int) $r['id'] ?>" class="st-<?= e($r['status']) ?>">
          <td><?= (int) $r['id'] ?></td>
          <td class="nowrap"><?= e(date('d.m.Y H:i', strtotime($r['created_at']))) ?></td>
          <td><?= e($r['nume']) ?></td>
          <td class="nowrap"><a href="tel:<?= e($r['telefon']) ?>"><?= e($r['telefon']) ?></a></td>
          <td class="nowrap"><?= $r['suma'] ? number_format((int) $r['suma'], 0, ',', '.') . ' lei' : '–' ?></td>
          <td>
            <form method="post" class="row-form">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="q" value="<?= e($q) ?>">
              <input type="hidden" name="f" value="<?= e($f) ?>">
              <select name="status">
                <?php foreach (STATUSES as $k => $v): ?>
                  <option value="<?= e($k) ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
              <input name="note" value="<?= e($r['note']) ?>" placeholder="Notă (ex: sună după ora 17)">
              <button class="btn sm">Salvează</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</main>
</body></html>
<?php }
