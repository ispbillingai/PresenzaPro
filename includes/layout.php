<?php
declare(strict_types=1);

function pageStart(string $title, ?array $user = null, array $opts = []): void
{
    $company = setting('company_name', APP_NAME) ?: APP_NAME;
    $nav = [];
    if ($user) {
        if ($user['role'] === 'admin') {
            $nav = [
                '/admin/' => 'Riepilogo',
                '/admin/employees.php' => 'Dipendenti',
                '/admin/locations.php' => 'Sedi',
                '/admin/shifts.php' => 'Fasce',
                '/admin/schedule.php' => 'Pianificazione',
                '/admin/clockings.php' => 'Timbrature',
                '/admin/absences.php' => 'Assenze',
                '/admin/requests.php' => 'Richieste',
                '/admin/reports.php' => 'Mensile',
                '/admin/balances.php' => 'Saldi',
                '/admin/settings.php' => 'Impostazioni',
            ];
        } else {
            $nav = [
                '/employee/' => 'Timbra',
                '/employee/history.php' => 'Storico',
                '/employee/requests.php' => 'Richieste',
            ];
        }
    }
    $pending = ($user && $user['role'] === 'admin' && function_exists('pendingRequestsCount')) ? pendingRequestsCount() : 0;
    $GLOBALS['__pp_user'] = $user;
    $GLOBALS['__pp_pending'] = $pending;
    $current = $_SERVER['SCRIPT_NAME'] ?? '';
    $current = preg_replace('#index\.php$#', '', $current);
    ?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0786c4">
<title><?= e($title) ?> · <?= e($company) ?></title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/assets/brand/icon-72.png" type="image/png">
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= e(APP_VERSION) ?>">
<?php if (!empty($opts['leaflet'])): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<?php endif; ?>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="<?= $user ? ($user['role'] === 'admin' ? '/admin/' : '/employee/') : '/login.php' ?>">
      <img src="/assets/brand/upgrade-mark.png" alt="Upgrade" width="30" height="30"> <?= e($company) ?>
    </a>
    <?php if ($user): ?>
    <nav class="nav">
      <?php foreach ($nav as $href => $label): ?>
        <a href="<?= e($href) ?>" class="<?= $current === $href ? 'active' : '' ?>"><?= e($label) ?><?= $href === '/admin/requests.php' && $pending ? ' <span class="nav-badge">' . $pending . '</span>' : '' ?></a>
      <?php endforeach; ?>
      <a href="/logout.php" class="nav-logout" title="<?= e($user['full_name']) ?>">Esci</a>
    </nav>
    <?php endif; ?>
  </div>
</header>
<main class="container">
<?php foreach (takeFlashes() as $f): ?>
  <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
<?php
}

function pageEnd(): void
{
    ?>
</main>
<?php if (!empty($GLOBALS['__pp_user']) && $GLOBALS['__pp_user']['role'] === 'admin'): ?>
<script>
(function () {
  var last = <?= (int)($GLOBALS['__pp_pending'] ?? 0) ?>;
  var onRequests = window.location.pathname === '/admin/requests.php';
  function check() {
    if (document.hidden) return;
    fetch('/api/requests-status.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.ok || typeof d.pending !== 'number') return;
        var link = document.querySelector('.nav a[href="/admin/requests.php"]');
        if (link) {
          var b = link.querySelector('.nav-badge');
          if (d.pending > 0) {
            if (!b) { b = document.createElement('span'); b.className = 'nav-badge'; link.appendChild(document.createTextNode(' ')); link.appendChild(b); }
            b.textContent = d.pending;
          } else if (b) { b.remove(); }
        }
        if (onRequests && d.pending !== last) { window.location.reload(); }
        last = d.pending;
      }).catch(function () {});
  }
  setInterval(check, 15000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
})();
</script>
<?php endif; ?>
<footer class="footer"><span class="powered">powered by <img src="/assets/brand/upgrade-logo.png" alt="Upgrade" width="86" height="28"></span><span class="help"><?= e(APP_NAME) ?> <?= e(APP_VERSION) ?></span></footer>
</body>
</html>
<?php
}
