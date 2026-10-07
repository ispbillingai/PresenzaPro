<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$user = requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle' && $id) {
        $loc = fetchOne('SELECT * FROM locations WHERE id = ?', [$id]);
        if ($loc) {
            q('UPDATE locations SET is_active = ? WHERE id = ?', [(int)$loc['is_active'] ? 0 : 1, $id]);
            flash('ok', (int)$loc['is_active'] ? 'Sede disattivata.' : 'Sede riattivata.');
        }
        redirect('/admin/locations.php');
    }

    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $address = trim((string)($_POST['address'] ?? '')) ?: null;
        $lat = is_numeric($_POST['latitude'] ?? null) ? (float)$_POST['latitude'] : null;
        $lng = is_numeric($_POST['longitude'] ?? null) ? (float)$_POST['longitude'] : null;
        $radius = (int)($_POST['radius_m'] ?? 0);

        $errors = [];
        if ($name === '') $errors[] = 'Il nome della sede è obbligatorio.';
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) $errors[] = 'Seleziona la posizione sulla mappa.';
        if ($radius < 10 || $radius > 5000) $errors[] = 'Il raggio deve essere tra 10 e 5000 metri.';
        if ($errors) {
            foreach ($errors as $m) flash('error', $m);
            redirect('/admin/locations.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }

        if ($id > 0) {
            q('UPDATE locations SET name = ?, address = ?, latitude = ?, longitude = ?, radius_m = ? WHERE id = ?',
                [$name, $address, $lat, $lng, $radius, $id]);
        } else {
            q('INSERT INTO locations (name, address, latitude, longitude, radius_m) VALUES (?, ?, ?, ?, ?)',
                [$name, $address, $lat, $lng, $radius]);
        }
        flash('ok', 'Sede salvata.');
        redirect('/admin/locations.php');
    }
}

$editing = isset($_GET['edit']) ? fetchOne('SELECT * FROM locations WHERE id = ?', [(int)$_GET['edit']]) : null;
$showForm = $editing || isset($_GET['new']);

$locations = fetchAll(
    'SELECT l.*, COUNT(ul.user_id) AS n_users FROM locations l
     LEFT JOIN user_locations ul ON ul.location_id = l.id
     GROUP BY l.id ORDER BY l.is_active DESC, l.name'
);

pageStart('Sedi', $user, ['leaflet' => $showForm]);
?>
<div class="actions" style="justify-content:space-between;margin-bottom:.75rem">
  <h1 style="margin:0">Sedi di lavoro</h1>
  <a class="btn btn-primary" href="?new=1">+ Nuova sede</a>
</div>

<?php if ($showForm): ?>
<div class="card">
  <h2><?= $editing ? 'Modifica ' . e($editing['name']) : 'Nuova sede' ?></h2>
  <form method="post" id="loc-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
    <div class="inline-fields">
      <label>Nome sede <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>"></label>
      <label>Raggio consentito (metri) <input type="number" name="radius_m" id="radius" min="10" max="5000" required value="<?= (int)($editing['radius_m'] ?? 100) ?>"></label>
    </div>
    <label>Indirizzo
      <div class="inline-fields" style="margin-top:.3rem">
        <input type="text" name="address" id="address" style="flex:1 1 240px;margin:0" value="<?= e($editing['address'] ?? '') ?>" placeholder="Via, numero, città">
        <button type="button" class="btn" id="btn-geocode">Cerca sulla mappa</button>
        <button type="button" class="btn" id="btn-mypos">Usa la mia posizione</button>
      </div>
    </label>
    <p class="help" style="margin:.25rem 0">Tocca la mappa per posizionare la sede. Il cerchio mostra l'area entro cui i dipendenti possono timbrare.</p>
    <div id="map"></div>
    <div class="inline-fields">
      <label>Latitudine <input type="text" name="latitude" id="lat" required inputmode="decimal" value="<?= e(isset($editing['latitude']) ? (string)$editing['latitude'] : '') ?>"></label>
      <label>Longitudine <input type="text" name="longitude" id="lng" required inputmode="decimal" value="<?= e(isset($editing['longitude']) ? (string)$editing['longitude'] : '') ?>"></label>
    </div>
    <div class="actions">
      <button class="btn btn-primary" type="submit">Salva</button>
      <a class="btn" href="/admin/locations.php">Annulla</a>
    </div>
  </form>
</div>
<script>
(function () {
  var latEl = document.getElementById('lat'), lngEl = document.getElementById('lng'), rEl = document.getElementById('radius');
  var hasPos = latEl.value !== '' && lngEl.value !== '';
  var start = hasPos ? [parseFloat(latEl.value), parseFloat(lngEl.value)] : [41.8719, 12.5674];
  var map = L.map('map').setView(start, hasPos ? 17 : 6);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
  var marker = null, circle = null;

  function place(lat, lng, zoom) {
    latEl.value = lat.toFixed(7); lngEl.value = lng.toFixed(7);
    if (!marker) {
      marker = L.marker([lat, lng], { draggable: true }).addTo(map);
      marker.on('dragend', function () { var p = marker.getLatLng(); place(p.lat, p.lng); });
      circle = L.circle([lat, lng], { radius: parseInt(rEl.value || '100', 10), color: '#1f4e79', fillOpacity: .12 }).addTo(map);
    } else {
      marker.setLatLng([lat, lng]); circle.setLatLng([lat, lng]);
    }
    if (zoom) map.setView([lat, lng], zoom);
  }
  if (hasPos) place(start[0], start[1]);
  map.on('click', function (e) { place(e.latlng.lat, e.latlng.lng); });
  rEl.addEventListener('input', function () { if (circle) circle.setRadius(parseInt(rEl.value || '0', 10)); });
  function manual() { var a = parseFloat(latEl.value), b = parseFloat(lngEl.value); if (!isNaN(a) && !isNaN(b)) place(a, b, 17); }
  latEl.addEventListener('change', manual); lngEl.addEventListener('change', manual);

  document.getElementById('btn-mypos').addEventListener('click', function () {
    if (!navigator.geolocation) { alert('Geolocalizzazione non disponibile.'); return; }
    navigator.geolocation.getCurrentPosition(function (p) { place(p.coords.latitude, p.coords.longitude, 17); },
      function () { alert('Impossibile rilevare la posizione (serve HTTPS e permesso del browser).'); }, { enableHighAccuracy: true, timeout: 15000 });
  });
  document.getElementById('btn-geocode').addEventListener('click', function () {
    var qs = document.getElementById('address').value.trim();
    if (!qs) return;
    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(qs), { headers: { 'Accept-Language': 'it' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.length) { alert('Indirizzo non trovato.'); return; }
        place(parseFloat(res[0].lat), parseFloat(res[0].lon), 17);
      }).catch(function () { alert('Ricerca non riuscita.'); });
  });
})();
</script>
<?php endif; ?>

<div class="card table-wrap">
  <?php if (!$locations): ?><span class="help">Nessuna sede ancora.</span><?php endif; ?>
  <?php if ($locations): ?>
  <table>
    <thead><tr><th>Sede</th><th>Indirizzo</th><th class="num">Raggio</th><th class="num">Dipendenti</th><th>Stato</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($locations as $l): ?>
      <tr class="<?= (int)$l['is_active'] ? '' : 'muted' ?>">
        <td><?= e($l['name']) ?><br><a class="coords" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=<?= e((string)$l['latitude']) ?>&mlon=<?= e((string)$l['longitude']) ?>#map=18/<?= e((string)$l['latitude']) ?>/<?= e((string)$l['longitude']) ?>"><?= e((string)$l['latitude']) ?>, <?= e((string)$l['longitude']) ?></a></td>
        <td><?= e($l['address'] ?? '') ?></td>
        <td class="num"><?= (int)$l['radius_m'] ?> m</td>
        <td class="num"><?= (int)$l['n_users'] ?></td>
        <td><?= (int)$l['is_active'] ? '<span class="badge badge-ok">attiva</span>' : '<span class="badge badge-off">disattivata</span>' ?></td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm" href="?edit=<?= (int)$l['id'] ?>">Modifica</a>
          <form method="post" class="inline" onsubmit="return confirm('<?= (int)$l['is_active'] ? 'Disattivare' : 'Riattivare' ?> questa sede?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="btn btn-sm <?= (int)$l['is_active'] ? 'btn-danger' : '' ?>" type="submit"><?= (int)$l['is_active'] ? 'Disattiva' : 'Riattiva' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php pageEnd(); ?>
