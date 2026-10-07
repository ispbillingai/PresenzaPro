<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';
require_once dirname(__DIR__) . '/includes/attendance.php';

$user = requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'settings') {
        $company = trim((string)($_POST['company_name'] ?? ''));
        $maxAcc = (int)($_POST['max_accuracy_m'] ?? 150);
        $maxAge = (int)($_POST['max_fix_age_s'] ?? 120);
        $otMin = (int)($_POST['overtime_min_minutes'] ?? 15);
        if ($company === '') $company = APP_NAME;
        setSetting('company_name', $company);
        setSetting('max_accuracy_m', (string)max(10, min(2000, $maxAcc)));
        setSetting('max_fix_age_s', (string)max(10, min(3600, $maxAge)));
        setSetting('overtime_min_minutes', (string)max(0, min(240, $otMin)));
        setSetting('webhooks_enabled', !empty($_POST['webhooks_enabled']) ? '1' : '0');
        flash('ok', 'Impostazioni salvate.');
        redirect('/admin/settings.php');
    }

    if ($action === 'alerts') {
        setSetting('alerts_enabled', !empty($_POST['alerts_enabled']) ? '1' : '0');
        setSetting('alert_email', trim((string)($_POST['alert_email'] ?? '')));
        setSetting('alert_phone', trim((string)($_POST['alert_phone'] ?? '')));
        $key = trim((string)($_POST['textmebot_api_key'] ?? ''));
        if ($key !== '********') {
            setSetting('textmebot_api_key', $key);
        }
        setSetting('late_alert_minutes', (string)max(1, min(240, (int)($_POST['late_alert_minutes'] ?? 15))));
        setSetting('missing_out_alert_minutes', (string)max(1, min(600, (int)($_POST['missing_out_alert_minutes'] ?? 60))));
        if (!empty($_POST['test'])) {
            require_once dirname(__DIR__) . '/includes/notify.php';
            $sent = notifyAdmin('Test avvisi ' . (setting('company_name', APP_NAME) ?: APP_NAME), 'Questo è un messaggio di prova dagli avvisi di PresenzaPro.');
            flash($sent ? 'ok' : 'error', $sent ? 'Messaggio di prova inviato via ' . implode(' e ', $sent) . '.' : 'Nessun canale ha funzionato: controlla email, numero e chiave API.');
            redirect('/admin/settings.php#alerts');
        }
        flash('ok', 'Impostazioni salvate.');
        redirect('/admin/settings.php');
    }

    if ($action === 'holiday_add') {
        $date = (string)($_POST['date'] ?? '');
        $name = trim((string)($_POST['name'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $name === '') {
            flash('error', 'Data e nome obbligatori.');
        } else {
            q('INSERT INTO holidays (`date`, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)', [$date, $name]);
            flash('ok', 'Festività aggiunta.');
        }
        redirect('/admin/settings.php#holidays');
    }

    if ($action === 'holiday_delete') {
        q('DELETE FROM holidays WHERE `date` = ?', [(string)($_POST['date'] ?? '')]);
        flash('ok', 'Festività rimossa.');
        redirect('/admin/settings.php#holidays');
    }

    if ($action === 'password') {
        $cur = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $rep = (string)($_POST['repeat'] ?? '');
        if (!password_verify($cur, $user['password_hash'])) {
            flash('error', 'Password attuale errata.');
        } elseif (strlen($new) < 8) {
            flash('error', 'La nuova password deve avere almeno 8 caratteri.');
        } elseif ($new !== $rep) {
            flash('error', 'Le due password non coincidono.');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
            flash('ok', 'Password aggiornata.');
        }
        redirect('/admin/settings.php');
    }
}

$customHolidays = fetchAll('SELECT * FROM holidays WHERE `date` >= ? ORDER BY `date`', [date('Y-01-01')]);
$year = (int)date('Y');

pageStart('Impostazioni', $user);
?>
<h1>Impostazioni</h1>
<div class="grid">
  <div class="card">
    <h2>Azienda e timbratura</h2>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="settings">
      <label>Nome azienda <input type="text" name="company_name" value="<?= e(setting('company_name', APP_NAME)) ?>"></label>
      <label>Precisione GPS massima accettata (metri)
        <input type="number" name="max_accuracy_m" min="10" max="2000" value="<?= e(setting('max_accuracy_m', '150')) ?>">
        <span class="help">Timbrature con precisione peggiore vengono rifiutate. 100-150 m è un buon compromesso per gli smartphone.</span>
      </label>
      <label>Età massima della posizione (secondi)
        <input type="number" name="max_fix_age_s" min="10" max="3600" value="<?= e(setting('max_fix_age_s', '120')) ?>">
        <span class="help">Rileva posizioni vecchie o orologi manomessi sul telefono.</span>
      </label>
      <label>Straordinario: minuti minimi oltre l'orario
        <input type="number" name="overtime_min_minutes" min="0" max="240" value="<?= e(setting('overtime_min_minutes', '15')) ?>">
        <span class="help">Sotto questa soglia il tempo in più non viene conteggiato come straordinario.</span>
      </label>
      <label class="checks"><input type="checkbox" name="webhooks_enabled" value="1" <?= setting('webhooks_enabled', '1') !== '0' ? 'checked' : '' ?>> Webhook alla timbratura attivi
        <span class="help">(interruttore generale: se spento non viene chiamato nessun URL, anche se impostato nei dipendenti)</span>
      </label>
      <button class="btn btn-primary" type="submit">Salva</button>
    </form>
  </div>

  <div class="card">
    <h2>Cambia la tua password</h2>
    <form method="post" autocomplete="off">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="password">
      <label>Password attuale <input type="password" name="current" required autocomplete="current-password"></label>
      <label>Nuova password <input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
      <label>Ripeti nuova password <input type="password" name="repeat" required minlength="8" autocomplete="new-password"></label>
      <button class="btn btn-primary" type="submit">Aggiorna password</button>
    </form>
    <p class="help" style="margin-top:1rem">Accesso come <strong><?= e($user['full_name']) ?></strong> (<?= e($user['username']) ?>).</p>
  </div>

  <div class="card" id="alerts">
    <h2>Avvisi all'amministratore</h2>
    <p class="help" style="margin:0 0 .5rem">Un controllo automatico ogni 5 minuti segnala chi non ha timbrato l'entrata e chi risulta ancora in servizio dopo la fine del turno. Gli avvisi compaiono nel Riepilogo e vengono inviati ai contatti qui sotto.</p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="alerts">
      <label class="checks"><input type="checkbox" name="alerts_enabled" value="1" <?= setting('alerts_enabled', '1') !== '0' ? 'checked' : '' ?>> Avvisi attivi</label>
      <div class="inline-fields">
        <label>Mancata entrata dopo (min) <input type="number" name="late_alert_minutes" min="1" max="240" value="<?= e(setting('late_alert_minutes', '15')) ?>"></label>
        <label>Uscita mancante dopo fine turno (min) <input type="number" name="missing_out_alert_minutes" min="1" max="600" value="<?= e(setting('missing_out_alert_minutes', '60')) ?>"></label>
      </div>
      <label>Email di destinazione <input type="email" name="alert_email" value="<?= e(setting('alert_email', '')) ?>" placeholder="titolare@azienda.it"></label>
      <label>Numero WhatsApp di destinazione <input type="tel" name="alert_phone" value="<?= e(setting('alert_phone', '')) ?>" placeholder="es. 3331234567"></label>
      <label>Chiave API TextMeBot (per WhatsApp) <input type="text" name="textmebot_api_key" value="<?= setting('textmebot_api_key', '') ? '********' : '' ?>" autocomplete="off">
        <span class="help">Servizio esterno textmebot.com: collega un numero WhatsApp aziendale e ottieni la chiave. Lascia il campo vuoto per rimuoverla.</span>
      </label>
      <div class="actions">
        <button class="btn btn-primary" type="submit">Salva</button>
        <button class="btn" type="submit" name="test" value="1">Salva e invia prova</button>
      </div>
    </form>
  </div>

  <div class="card" id="holidays">
    <h2>Festività</h2>
    <p class="help" style="margin:0 0 .5rem">Nei giorni festivi non ci sono ore previste. Le festività nazionali italiane sono già incluse; aggiungi qui il patrono o le chiusure aziendali.</p>
    <form method="post" class="inline-fields">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="holiday_add">
      <label>Data <input type="date" name="date" required></label>
      <label>Nome <input type="text" name="name" required placeholder="es. Santo Patrono"></label>
      <label style="flex:0 0 auto"><span>&nbsp;</span><button class="btn btn-primary" type="submit">Aggiungi</button></label>
    </form>
    <?php if ($customHolidays): ?>
    <table style="margin-top:.5rem">
      <tbody>
      <?php foreach ($customHolidays as $hd): ?>
        <tr>
          <td><?= e(fmtDate($hd['date'], 'd/m/Y')) ?></td>
          <td><?= e($hd['name']) ?></td>
          <td class="num">
            <form method="post" class="inline">
              <?= csrfField() ?><input type="hidden" name="action" value="holiday_delete"><input type="hidden" name="date" value="<?= e($hd['date']) ?>">
              <button class="btn btn-sm btn-danger" type="submit">Rimuovi</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <details style="margin-top:.75rem">
      <summary class="help">Festività nazionali <?= $year ?></summary>
      <ul class="help" style="margin:.4rem 0 0;padding-left:1.2rem">
        <?php foreach (italianHolidays($year) as $d => $n): ?>
          <li><?= e(fmtDate($d, 'd/m')) ?> <?= e($n) ?></li>
        <?php endforeach; ?>
      </ul>
    </details>
  </div>
</div>
<?php pageEnd(); ?>
