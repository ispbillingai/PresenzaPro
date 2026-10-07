<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';
require_once dirname(__DIR__) . '/includes/notify.php';

$user = requireRole('employee');
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'cancel') {
        q('DELETE FROM leave_requests WHERE id = ? AND user_id = ? AND status = "pending"', [(int)($_POST['id'] ?? 0), $uid]);
        flash('ok', 'Richiesta annullata.');
        redirect('/employee/requests.php');
    }

    if ($action === 'new') {
        $type = in_array($_POST['type'] ?? '', array_keys(ABSENCE_LABELS), true) ? $_POST['type'] : '';
        $from = (string)($_POST['date_from'] ?? '');
        $to = (string)($_POST['date_to'] ?? '') ?: $from;
        $hours = trim((string)($_POST['hours'] ?? ''));
        $hours = $hours === '' ? null : (float)str_replace(',', '.', $hours);
        $note = trim((string)($_POST['note'] ?? '')) ?: null;
        $errors = [];
        if ($type === '') $errors[] = 'Seleziona il tipo.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) $errors[] = 'Date non valide.';
        if ($hours !== null && ($hours <= 0 || $hours > 24)) $errors[] = 'Le ore devono essere tra 0 e 24.';
        if ($hours !== null && $from !== $to) $errors[] = 'Un permesso a ore vale per un solo giorno.';
        if (($type === 'permesso' || $type === 'permesso_servizio') && $hours === null && $from !== $to) $errors[] = 'Per più giorni usa Ferie.';
        if (!$errors) {
            $dup = fetchOne('SELECT id FROM leave_requests WHERE user_id = ? AND status = "pending" AND date_from <= ? AND date_to >= ?', [$uid, $to, $from]);
            if ($dup) $errors[] = 'Hai già una richiesta in attesa per quelle date.';
        }
        if ($errors) {
            foreach ($errors as $m) flash('error', $m);
            redirect('/employee/requests.php?new=1');
        }
        q('INSERT INTO leave_requests (user_id, type, date_from, date_to, hours, note) VALUES (?, ?, ?, ?, ?, ?)', [$uid, $type, $from, $to, $hours, $note]);
        $req = fetchOne('SELECT * FROM leave_requests WHERE id = ?', [(int)db()->lastInsertId()]);
        if (setting('notify_requests', '1') !== '0') {
            $host = $_SERVER['HTTP_HOST'] ?? 'presenzapro.upgradesrls.com';
            notifyAdmin('Nuova richiesta: ' . $user['full_name'],
                sprintf("%s ha richiesto: %s.%s\nApprova o rifiuta su https://%s/admin/requests.php", $user['full_name'], requestSummary($req), $note ? "\nMotivo: " . $note : '', $host));
        }
        flash('ok', 'Richiesta inviata. Riceverai l\'esito qui' . (whatsappNumber($user['phone']) || $user['email'] ? ' e con un messaggio' : '') . '.');
        redirect('/employee/requests.php');
    }
}

$requests = fetchAll('SELECT * FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 100', [$uid]);
$snapshot = [];
foreach ($requests as $r) {
    $snapshot[(int)$r['id']] = $r['status'] . '|' . (string)$r['admin_note'] . '|' . (string)$r['decided_at'];
}
$snapshotHash = md5(json_encode($snapshot));
if (isset($_GET['changed'])) {
    flash('ok', 'Il responsabile ha risposto a una tua richiesta: vedi l\'esito qui sotto.');
}
$bal = leaveBalances((int)date('Y'), $uid)[$uid] ?? null;

pageStart('Richieste', $user);
?>
<div class="actions" style="justify-content:space-between;margin-bottom:.75rem">
  <h1 style="margin:0">Ferie e permessi</h1>
  <a class="btn btn-primary" href="?new=1">+ Nuova richiesta</a>
</div>

<?php if ($bal): ?>
<div class="stats" style="margin-bottom:1rem">
  <div class="stat"><span class="n"><?= e(fmtHoursDec($bal['leave_left'])) ?></span><span class="l">giorni di ferie residui <?= date('Y') ?><?= $bal['leave_planned'] ? ' (' . (int)$bal['leave_planned'] . ' pianificati)' : '' ?></span></div>
  <div class="stat"><span class="n"><?= e(fmtMinutes(max(0, $bal['permit_left_min']))) ?></span><span class="l">permessi personali residui</span></div>
  <div class="stat"><span class="n"><?= $bal['bank_min'] < 0 ? '-' : '+' ?><?= e(fmtMinutes(abs($bal['bank_min']))) ?></span><span class="l">banca ore (lavorate meno previste)</span></div>
</div>
<?php endif; ?>

<?php if (isset($_GET['new'])): ?>
<div class="card">
  <h2>Nuova richiesta</h2>
  <form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="new">
    <label>Tipo
      <select name="type" required>
        <?php foreach (ABSENCE_LABELS as $k => $v): ?>
          <option value="<?= e($k) ?>"><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="help">Il permesso personale scala il tuo monte ore; il permesso per servizio (es. trasferta, commissione per l'azienda) giustifica le ore senza scalarle.</span>
    </label>
    <div class="inline-fields">
      <label>Dal <input type="date" name="date_from" required value="<?= e(date('Y-m-d')) ?>"></label>
      <label>Al <span class="help">(vuoto = stesso giorno)</span> <input type="date" name="date_to"></label>
      <label>Ore <span class="help">(solo permesso parziale)</span> <input type="text" name="hours" inputmode="decimal" placeholder="es. 2"></label>
    </div>
    <label>Motivo / nota <input type="text" name="note" maxlength="255"></label>
    <div class="actions">
      <button class="btn btn-primary" type="submit">Invia richiesta</button>
      <a class="btn" href="/employee/requests.php">Annulla</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>Le tue richieste</h2>
  <?php if (!$requests): ?><span class="help">Nessuna richiesta.</span><?php endif; ?>
  <ul class="today-list">
    <?php foreach ($requests as $r): ?>
      <li style="flex-wrap:wrap;gap:.3rem">
        <span>
          <strong><?= e(absenceLabel($r['type'])) ?></strong>
          <?= e(fmtDate($r['date_from'], 'd/m/Y')) ?><?= $r['date_to'] !== $r['date_from'] ? ' - ' . e(fmtDate($r['date_to'], 'd/m/Y')) : '' ?>
          <?= $r['hours'] !== null ? '· ' . e(fmtHoursDec((float)$r['hours'])) . ' h' : '' ?>
          <?= $r['note'] ? '<br><span class="help">' . e($r['note']) . '</span>' : '' ?>
          <?= $r['admin_note'] ? '<br><span class="help">Risposta: ' . e($r['admin_note']) . '</span>' : '' ?>
        </span>
        <span>
          <span class="badge <?= ['pending' => 'badge-warn', 'approved' => 'badge-ok', 'rejected' => 'badge-rej'][$r['status']] ?>"><?= e(REQUEST_STATUS_LABELS[$r['status']]) ?></span>
          <?php if ($r['status'] === 'pending'): ?>
            <form method="post" class="inline" onsubmit="return confirm('Annullare la richiesta?')">
              <?= csrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm" type="submit">Annulla</button>
            </form>
          <?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<script>
(function () {
  var hash = <?= json_encode($snapshotHash) ?>;
  function check() {
    if (document.hidden) return;
    fetch('/api/requests-status.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.ok) return;
        if (d.hash !== hash) { window.location.replace('/employee/requests.php?changed=1'); }
      }).catch(function () {});
  }
  setInterval(check, 7000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
})();
</script>
<?php pageEnd(); ?>
