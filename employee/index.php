<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';

if (!function_exists('strftime_it')) {
    function strftime_it(int $ts): string
    {
        $days = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];
        $months = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
        return $days[(int)date('w', $ts)] . ' ' . date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    }
}

$user = requireRole('employee');
$uid = (int)$user['id'];
$locations = userLocations($uid);
$last = lastAccepted($uid);
$present = isPresentNow($last);
$onBreak = isOnBreak($last);
$onPermit = isOnPermit($last);
$todayShift = todayShift($uid);
$todayHoliday = holidaysBetween(date('Y-m-d'), date('Y-m-d'))[date('Y-m-d')] ?? null;
$breakEnabled = $todayShift !== null && ($todayShift['break_mode'] ?? 'fixed') === 'clocked';
$allowed = allowedNextTypes($last, $breakEnabled);

$today = fetchAll(
    'SELECT c.*, l.name AS location_name FROM clockings c
     LEFT JOIN locations l ON l.id = c.location_id
     WHERE c.user_id = ? AND DATE(c.clocked_at) = CURDATE() AND c.status <> "voided"
     ORDER BY c.clocked_at DESC, c.id DESC',
    [$uid]
);

$locJson = json_encode(array_map(fn($l) => [
    'id' => (int)$l['id'],
    'name' => $l['name'],
    'lat' => (float)$l['latitude'],
    'lng' => (float)$l['longitude'],
    'radius' => (int)$l['radius_m'],
], $locations), JSON_UNESCAPED_UNICODE);

pageStart('Timbra', $user);
?>
<div class="card clock-card"
     id="clock"
     data-allowed="<?= e(json_encode($allowed)) ?>"
     data-labels="<?= e(json_encode(CLOCK_TYPE_LABELS, JSON_UNESCAPED_UNICODE)) ?>"
     data-csrf="<?= e(csrfToken()) ?>"
     data-max-accuracy="<?= e(setting('max_accuracy_m', '150')) ?>"
     data-locations="<?= e($locJson) ?>"
     data-permit-codes="<?= permitCodesToday($uid) ?>">
  <div class="clock-time" id="clock-time">--:--</div>
  <div class="clock-date"><?= e(ucfirst(strftime_it(time()))) ?></div>
  <p style="margin:0 0 .5rem">Ciao <strong><?= e($user['full_name']) ?></strong></p>
  <?php if ($todayHoliday): ?>
    <p class="help" style="margin:0 0 .5rem">Oggi è festivo (<?= e($todayHoliday) ?>).</p>
  <?php elseif ($todayShift): ?>
    <p class="help" style="margin:0 0 .5rem">Turno di oggi: <strong><?= e(substr($todayShift['start_time'], 0, 5)) ?> - <?= e(substr($todayShift['end_time'], 0, 5)) ?></strong> (<?= e($todayShift['name']) ?>)<?= $breakEnabled ? ' · pausa da timbrare' : '' ?></p>
  <?php endif; ?>
  <div class="clock-state" id="clock-state">
    <?php if ($onPermit): ?>
      <span class="badge badge-info">Fuori per permesso</span> dalle <?= e(fmtDate($last['clocked_at'], 'H:i')) ?>
    <?php elseif ($onBreak): ?>
      <span class="badge badge-warn">In pausa</span> dalle <?= e(fmtDate($last['clocked_at'], 'H:i')) ?>
    <?php elseif ($present): ?>
      <span class="badge badge-in">In servizio</span>
    <?php else: ?>
      <span class="badge badge-out">Non in servizio</span>
    <?php endif; ?>
  </div>

  <?php if (!$locations): ?>
    <div class="alert alert-warn">Nessuna sede di lavoro assegnata. Chiedi al responsabile di assegnartene una.</div>
  <?php endif; ?>

  <div id="clock-buttons">
    <?php foreach ($allowed as $i => $t): ?>
      <button type="button" class="btn-clock <?= e($t) ?> <?= $i > 0 ? 'secondary' : '' ?>" data-type="<?= e($t) ?>" disabled><?= $i === 0 ? 'Timbra ' . mb_strtoupper(clockTypeLabel($t)) : clockTypeLabel($t) ?></button>
    <?php endforeach; ?>
  </div>

  <p class="help" id="permit-help" style="margin:.6rem 0 0">"Uscita per permesso" chiede il codice ricevuto con l'approvazione del permesso di oggi (personale o per servizio). Al rientro premi "Rientro da permesso", senza codice.</p>
  <div class="geo-status waiting" id="geo-status">
    <strong>Rilevamento posizione…</strong>
    <small>Consenti l'accesso alla posizione quando richiesto.</small>
  </div>
  <div class="result" id="result"></div>
</div>

<div class="card">
  <h2>Oggi</h2>
  <ul class="today-list" id="today-list">
    <?php if (!$today): ?>
      <li id="today-empty"><span class="help">Nessuna timbratura oggi.</span></li>
    <?php endif; ?>
    <?php foreach ($today as $c): ?>
      <li>
        <span><?= e(fmtDate($c['clocked_at'], 'H:i')) ?> · <?= e(clockTypeLabel($c['type'])) ?><?= $c['location_name'] ? ' · ' . e($c['location_name']) : '' ?></span>
        <?php if ($c['status'] === 'accepted'): ?>
          <span class="badge badge-ok">OK</span>
        <?php else: ?>
          <span class="badge badge-rej" title="<?= e(rejectLabel($c['reject_reason'])) ?>">Rifiutata</span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>

<?php if ($locations): ?>
<div class="card">
  <h2>Le tue sedi</h2>
  <ul style="margin:0;padding-left:1.2rem">
    <?php foreach ($locations as $l): ?>
      <li><?= e($l['name']) ?> <span class="help">(raggio <?= (int)$l['radius_m'] ?> m)</span></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="modal-backdrop" id="permit-modal" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="permit-title">
    <h2 id="permit-title">Uscita per permesso</h2>
    <div id="permit-nocode" class="alert alert-error" hidden>Non hai nessun codice permesso valido per oggi. Chiedi al responsabile di approvare un permesso: riceverai il codice da inserire qui.</div>
    <div id="permit-form">
      <label>Codice del permesso approvato
        <input type="text" id="permit-code" maxlength="6" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="es. A7K2PX" style="text-transform:uppercase;letter-spacing:.15em;font-size:1.3rem;text-align:center">
      </label>
      <div id="permit-error" class="alert alert-error" hidden></div>
      <p class="help" style="margin:0 0 .75rem">Il codice vale per una sola uscita. Per il rientro non serve.</p>
    </div>
    <div class="actions" style="justify-content:flex-end">
      <button type="button" class="btn" id="permit-cancel">Annulla</button>
      <button type="button" class="btn btn-primary" id="permit-confirm">Conferma uscita</button>
    </div>
  </div>
</div>
<script src="/assets/js/clock.js?v=<?= e(APP_VERSION) ?>"></script>
<?php pageEnd(); ?>
