<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';

$user = requireRole('admin');

$employees = fetchAll('SELECT id, full_name FROM users WHERE role = "employee" AND is_active = 1 ORDER BY full_name');
$lastByUser = fetchAll(
    'SELECT c.user_id, c.type, c.clocked_at, l.name AS location_name
     FROM clockings c
     JOIN (SELECT user_id, MAX(id) AS id FROM clockings WHERE status = "accepted" GROUP BY user_id) m ON m.id = c.id
     LEFT JOIN locations l ON l.id = c.location_id'
);
$lastMap = [];
foreach ($lastByUser as $r) {
    $lastMap[(int)$r['user_id']] = $r;
}
$present = [];
foreach ($employees as $emp) {
    $l = $lastMap[(int)$emp['id']] ?? null;
    if ($l && $l['type'] === 'in' && substr($l['clocked_at'], 0, 10) === date('Y-m-d')) {
        $present[] = ['name' => $emp['full_name'], 'since' => $l['clocked_at'], 'location' => $l['location_name']];
    }
}

$todayStats = fetchOne(
    'SELECT SUM(status = "accepted") AS ok, SUM(status = "rejected") AS rej FROM clockings WHERE DATE(clocked_at) = CURDATE()'
) ?: ['ok' => 0, 'rej' => 0];
$locCount = (int)(fetchOne('SELECT COUNT(*) AS n FROM locations WHERE is_active = 1')['n'] ?? 0);

$recent = fetchAll(
    'SELECT c.*, u.full_name, l.name AS location_name FROM clockings c
     JOIN users u ON u.id = c.user_id
     LEFT JOIN locations l ON l.id = c.location_id
     ORDER BY c.clocked_at DESC, c.id DESC LIMIT 15'
);

pageStart('Riepilogo', $user);
?>
<h1>Riepilogo di oggi</h1>
<div class="stats">
  <div class="stat"><span class="n"><?= count($present) ?></span><span class="l">in servizio ora</span></div>
  <div class="stat"><span class="n"><?= (int)$todayStats['ok'] ?></span><span class="l">timbrature accettate</span></div>
  <div class="stat"><span class="n"><?= (int)$todayStats['rej'] ?></span><span class="l">timbrature rifiutate</span></div>
  <div class="stat"><span class="n"><?= count($employees) ?></span><span class="l">dipendenti attivi</span></div>
</div>

<?php if ($locCount === 0 || !$employees): ?>
<div class="card" style="margin-top:1rem">
  <h2>Per iniziare</h2>
  <ol style="margin:0;padding-left:1.2rem">
    <li><a href="/admin/locations.php">Crea una sede di lavoro</a> con posizione sulla mappa e raggio consentito.</li>
    <li><a href="/admin/employees.php">Aggiungi i dipendenti</a> e assegna a ciascuno la sua sede.</li>
    <li><a href="/admin/shifts.php">Definisci le fasce orarie</a> e assegna l'orario settimanale a ogni dipendente, per calcolare ore previste, ritardi e assenze.</li>
    <li>Ogni dipendente accede dal telefono con il suo link personale (o nome utente e password) e timbra solo se si trova sul posto.</li>
  </ol>
</div>
<?php endif; ?>

<div class="grid" style="margin-top:1rem">
  <div class="card">
    <h2>In servizio adesso</h2>
    <?php if (!$present): ?><span class="help">Nessuno.</span><?php endif; ?>
    <ul class="today-list">
      <?php foreach ($present as $p): ?>
        <li><span><?= e($p['name']) ?><?= $p['location'] ? ' · ' . e($p['location']) : '' ?></span><span class="badge badge-in">dalle <?= e(fmtDate($p['since'], 'H:i')) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card">
    <h2>Ultime timbrature</h2>
    <?php if (!$recent): ?><span class="help">Nessuna timbratura.</span><?php endif; ?>
    <ul class="today-list">
      <?php foreach ($recent as $c): ?>
        <li>
          <span><?= e(fmtDate($c['clocked_at'], 'd/m H:i')) ?> · <?= e($c['full_name']) ?> · <?= $c['type'] === 'in' ? 'Entrata' : 'Uscita' ?></span>
          <?php if ($c['status'] === 'accepted'): ?>
            <span class="badge badge-ok">OK</span>
          <?php else: ?>
            <span class="badge badge-rej"><?= e(rejectLabel($c['reject_reason'])) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <p style="margin:.75rem 0 0"><a href="/admin/clockings.php">Tutte le timbrature ›</a></p>
  </div>
</div>
<?php pageEnd(); ?>
