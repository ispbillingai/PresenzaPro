<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';
require_once dirname(__DIR__) . '/includes/notify.php';

$user = requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $adminNote = trim((string)($_POST['admin_note'] ?? '')) ?: null;
    $r = fetchOne('SELECT * FROM leave_requests WHERE id = ? AND status = "pending"', [$id]);
    if ($r && ($action === 'approve' || $action === 'reject')) {
        $pdo = db();
        $pdo->beginTransaction();
        q('UPDATE leave_requests SET status = ?, admin_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?',
            [$action === 'approve' ? 'approved' : 'rejected', $adminNote, (int)$user['id'], $id]);
        if ($action === 'approve') {
            q('INSERT INTO absences (user_id, type, date_from, date_to, hours, note, created_by, request_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [(int)$r['user_id'], $r['type'], $r['date_from'], $r['date_to'], $r['hours'], $r['note'], (int)$user['id'], $id]);
        }
        $pdo->commit();
        $emp = fetchOne('SELECT * FROM users WHERE id = ?', [(int)$r['user_id']]);
        $company = setting('company_name', APP_NAME) ?: APP_NAME;
        $text = sprintf("Ciao %s, la tua richiesta %s è stata %s.%s\n%s", $emp['full_name'], requestSummary($r),
            $action === 'approve' ? 'APPROVATA' : 'RIFIUTATA', $adminNote ? "\nNota: " . $adminNote : '', $company);
        $sent = setting('notify_requests', '1') !== '0' ? notifyEmployee($emp, ($action === 'approve' ? 'Richiesta approvata' : 'Richiesta rifiutata') . ' - ' . $company, $text) : [];
        flash('ok', ($action === 'approve' ? 'Richiesta approvata e registrata tra le assenze.' : 'Richiesta rifiutata.')
            . ($sent ? ' Dipendente avvisato via ' . implode(' e ', $sent) . '.' : ' Nessun avviso inviato al dipendente (serve il cellulare con la chiave WhatsApp in Impostazioni, o l\'email).'));
    }
    redirect('/admin/requests.php' . (($_POST['show'] ?? '') === 'all' ? '?show=all' : ''));
}

$showAll = ($_GET['show'] ?? '') === 'all';
$rows = fetchAll(
    'SELECT r.*, u.full_name, a.full_name AS admin_name FROM leave_requests r
     JOIN users u ON u.id = r.user_id LEFT JOIN users a ON a.id = r.decided_by'
    . ($showAll ? '' : ' WHERE r.status = "pending"')
    . ' ORDER BY FIELD(r.status, "pending") DESC, r.created_at DESC LIMIT 300'
);

pageStart('Richieste', $user);
?>
<div class="actions" style="justify-content:space-between;margin-bottom:.75rem">
  <h1 style="margin:0">Richieste ferie e permessi</h1>
  <a class="btn" href="?<?= $showAll ? '' : 'show=all' ?>"><?= $showAll ? 'Solo in attesa' : 'Mostra tutte' ?></a>
</div>
<div class="card table-wrap">
  <?php if (!$rows): ?><span class="help"><?= $showAll ? 'Nessuna richiesta.' : 'Nessuna richiesta in attesa.' ?></span><?php endif; ?>
  <?php if ($rows): ?>
  <table>
    <thead><tr><th>Richiesta il</th><th>Dipendente</th><th>Tipo</th><th>Periodo</th><th class="num">Ore</th><th>Nota</th><th>Stato</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td style="white-space:nowrap"><?= e(fmtDate($r['created_at'])) ?></td>
        <td><?= e($r['full_name']) ?></td>
        <td><span class="badge <?= $r['type'] === 'malattia' ? 'badge-warn' : 'badge-info' ?>"><?= e(absenceLabel($r['type'])) ?></span></td>
        <td style="white-space:nowrap"><?= e(fmtDate($r['date_from'], 'd/m/Y')) ?><?= $r['date_to'] !== $r['date_from'] ? ' - ' . e(fmtDate($r['date_to'], 'd/m/Y')) : '' ?></td>
        <td class="num"><?= $r['hours'] !== null ? e(fmtHoursDec((float)$r['hours'])) . ' h' : 'giornata' ?></td>
        <td class="help"><?= e($r['note'] ?? '') ?><?= $r['admin_note'] ? '<br>Risposta: ' . e($r['admin_note']) : '' ?></td>
        <td>
          <span class="badge <?= ['pending' => 'badge-warn', 'approved' => 'badge-ok', 'rejected' => 'badge-rej'][$r['status']] ?>"><?= e(REQUEST_STATUS_LABELS[$r['status']]) ?></span>
          <?= $r['admin_name'] ? '<br><span class="help">' . e($r['admin_name']) . ' ' . e(fmtDate($r['decided_at'], 'd/m H:i')) . '</span>' : '' ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($r['status'] === 'pending'): ?>
          <form method="post" class="inline-fields" style="gap:.3rem">
            <?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="show" value="<?= $showAll ? 'all' : '' ?>">
            <input type="text" name="admin_note" placeholder="Nota (facoltativa)" style="margin:0;flex:1 1 140px">
            <button class="btn btn-sm btn-primary" type="submit" name="action" value="approve">Approva</button>
            <button class="btn btn-sm btn-danger" type="submit" name="action" value="reject">Rifiuta</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<p class="help">Una richiesta approvata viene registrata automaticamente in <a href="/admin/absences.php">Assenze</a> e conteggiata nel riepilogo mensile e nei saldi.</p>
<?php pageEnd(); ?>
