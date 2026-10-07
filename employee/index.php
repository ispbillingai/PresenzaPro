<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';

function strftime_it(int $ts): string
{
    $days = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];
    $months = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
    return $days[(int)date('w', $ts)] . ' ' . date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

$user = requireRole('employee');
$locations = userLocations((int)$user['id']);
$last = lastAccepted((int)$user['id']);
$next = nextClockType($last);
$present = isPresentNow($last);

$today = fetchAll(
    'SELECT c.*, l.name AS location_name FROM clockings c
     LEFT JOIN locations l ON l.id = c.location_id
     WHERE c.user_id = ? AND DATE(c.clocked_at) = CURDATE()
     ORDER BY c.clocked_at DESC, c.id DESC',
    [(int)$user['id']]
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
     data-next="<?= e($next) ?>"
     data-csrf="<?= e(csrfToken()) ?>"
     data-max-accuracy="<?= e(setting('max_accuracy_m', '150')) ?>"
     data-locations="<?= e($locJson) ?>">
  <div class="clock-time" id="clock-time">--:--</div>
  <div class="clock-date"><?= e(ucfirst(strftime_it(time()))) ?></div>
  <p style="margin:0 0 .5rem">Ciao <strong><?= e($user['full_name']) ?></strong></p>
  <div class="clock-state" id="clock-state">
    <?php if ($present): ?>
      <span class="badge badge-in">In servizio</span> dalle <?= e(fmtDate($last['clocked_at'], 'H:i')) ?>
    <?php else: ?>
      <span class="badge badge-out">Non in servizio</span>
    <?php endif; ?>
  </div>

  <?php if (!$locations): ?>
    <div class="alert alert-warn">Nessuna sede di lavoro assegnata. Chiedi al responsabile di assegnartene una.</div>
  <?php endif; ?>

  <button type="button" class="btn-clock <?= e($next) ?>" id="btn-clock" disabled>
    <?= $next === 'in' ? 'Timbra ENTRATA' : 'Timbra USCITA' ?>
  </button>

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
        <span><?= e(fmtDate($c['clocked_at'], 'H:i')) ?> · <?= $c['type'] === 'in' ? 'Entrata' : 'Uscita' ?><?= $c['location_name'] ? ' · ' . e($c['location_name']) : '' ?></span>
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

<script src="/assets/js/clock.js?v=<?= e(APP_VERSION) ?>"></script>
<?php pageEnd(); ?>
