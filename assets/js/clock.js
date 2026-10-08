(function () {
  'use strict';
  var root = document.getElementById('clock');
  if (!root) return;

  var buttonsBox = document.getElementById('clock-buttons');
  var geoBox = document.getElementById('geo-status');
  var resultBox = document.getElementById('result');
  var stateBox = document.getElementById('clock-state');
  var list = document.getElementById('today-list');
  var timeEl = document.getElementById('clock-time');

  var csrf = root.dataset.csrf;
  var maxAccuracy = parseFloat(root.dataset.maxAccuracy || '150');
  var allowed = [], labels = {}, locations = [];
  try { allowed = JSON.parse(root.dataset.allowed || '["in"]'); } catch (e) { allowed = ['in']; }
  try { labels = JSON.parse(root.dataset.labels || '{}'); } catch (e) {}
  try { locations = JSON.parse(root.dataset.locations || '[]'); } catch (e) {}

  var fix = null;      // last position
  var sending = false;

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function tick() {
    var d = new Date();
    timeEl.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }
  tick();
  setInterval(tick, 1000);

  function distance(lat1, lng1, lat2, lng2) {
    var R = 6371008.8, toRad = Math.PI / 180;
    var dp = (lat2 - lat1) * toRad, dl = (lng2 - lng1) * toRad;
    var a = Math.sin(dp / 2) * Math.sin(dp / 2) +
      Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) * Math.sin(dl / 2) * Math.sin(dl / 2);
    return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function nearest(lat, lng) {
    var best = null, bestD = null;
    locations.forEach(function (l) {
      var d = distance(lat, lng, l.lat, l.lng);
      if (bestD === null || d < bestD) { best = l; bestD = d; }
    });
    return { loc: best, dist: bestD, inside: best !== null && bestD <= best.radius };
  }

  function setGeo(cls, title, detail) {
    geoBox.className = 'geo-status ' + cls;
    geoBox.innerHTML = '<strong></strong><small></small>';
    geoBox.querySelector('strong').textContent = title;
    geoBox.querySelector('small').textContent = detail || '';
  }

  function renderButtons() {
    buttonsBox.innerHTML = '';
    allowed.forEach(function (t, i) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn-clock ' + t + (i > 0 ? ' secondary' : '');
      b.dataset.type = t;
      b.textContent = i === 0 ? 'Timbra ' + (labels[t] || t).toUpperCase() : (labels[t] || t);
      b.disabled = sending || !fix || locations.length === 0;
      b.addEventListener('click', function () { send(t); });
      buttonsBox.appendChild(b);
    });
  }

  function updateButtons() {
    var dis = sending || !fix || locations.length === 0;
    Array.prototype.forEach.call(buttonsBox.querySelectorAll('button'), function (b) { b.disabled = dis; });
  }

  function onPosition(pos) {
    fix = {
      lat: pos.coords.latitude,
      lng: pos.coords.longitude,
      accuracy: pos.coords.accuracy,
      ts: pos.timestamp
    };
    var acc = Math.round(fix.accuracy);
    if (locations.length === 0) {
      setGeo('waiting', 'Posizione rilevata (±' + acc + ' m)', 'Nessuna sede assegnata.');
    } else {
      var n = nearest(fix.lat, fix.lng);
      var d = Math.round(n.dist);
      var src = acc <= 20 ? 'GPS' : (acc <= 100 ? 'Wi-Fi/GPS' : 'rete');
      if (n.inside) {
        setGeo('inside', 'Sei presso ' + n.loc.name, 'Distanza ' + d + ' m · precisione ±' + acc + ' m (' + src + ')');
      } else {
        setGeo('outside', 'Fuori sede: ' + d + ' m da ' + n.loc.name,
          'Raggio consentito ' + n.loc.radius + ' m · precisione ±' + acc + ' m (' + src + ')');
      }
      if (fix.accuracy > maxAccuracy) {
        setGeo('waiting', 'Precisione GPS bassa (±' + acc + ' m)',
          'Serve almeno ±' + Math.round(maxAccuracy) + ' m. Attiva il GPS e spostati all\'aperto.');
      }
    }
    updateButtons();
  }

  function onError(err) {
    fix = null;
    var msg = 'Impossibile rilevare la posizione.';
    if (err && err.code === 1) msg = 'Permesso posizione negato. Abilitalo nelle impostazioni del browser e ricarica.';
    else if (err && err.code === 2) msg = 'Posizione non disponibile. Attiva il GPS.';
    else if (err && err.code === 3) msg = 'Timeout nel rilevamento della posizione. Nuovo tentativo tra 10 secondi.';
    setGeo('outside', 'Posizione non disponibile', msg);
    updateButtons();
  }

  var GEO_INTERVAL_MS = 10000;
  var geoBusy = false;
  var geoTimer = null;

  function readPosition() {
    if (geoBusy || document.hidden) return;
    geoBusy = true;
    navigator.geolocation.getCurrentPosition(
      function (pos) { geoBusy = false; onPosition(pos); },
      function (err) { geoBusy = false; onError(err); },
      { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 }
    );
  }

  function startWatch() {
    if (!('geolocation' in navigator)) {
      setGeo('outside', 'Geolocalizzazione non supportata', 'Usa un browser aggiornato.');
      return;
    }
    if (window.isSecureContext === false) {
      setGeo('outside', 'Connessione non sicura', 'La posizione è disponibile solo in HTTPS.');
      return;
    }
    // One reading now, then one every 10 seconds (paused while the page is hidden).
    readPosition();
    geoTimer = setInterval(readPosition, GEO_INTERVAL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) readPosition(); });
  }

  function showResult(ok, text) {
    resultBox.innerHTML = '<div class="alert ' + (ok ? 'alert-ok' : 'alert-error') + '"></div>';
    resultBox.firstChild.textContent = text;
  }

  function prependToday(r) {
    var empty = document.getElementById('today-empty');
    if (empty) empty.remove();
    var li = document.createElement('li');
    var t = r.clocked_at.substr(11, 5);
    var left = document.createElement('span');
    left.textContent = t + ' · ' + (r.type_label || r.type) + (r.location ? ' · ' + r.location : '');
    var badge = document.createElement('span');
    badge.className = 'badge ' + (r.ok ? 'badge-ok' : 'badge-rej');
    badge.textContent = r.ok ? 'OK' : 'Rifiutata';
    li.appendChild(left);
    li.appendChild(badge);
    list.insertBefore(li, list.firstChild);
  }

  // ----- Permit code dialog (in-app, no browser prompt) -----
  var permitCodes = parseInt(root.dataset.permitCodes || '0', 10);
  var modal = document.getElementById('permit-modal');
  var permitInput = document.getElementById('permit-code');
  var permitError = document.getElementById('permit-error');
  var permitNoCode = document.getElementById('permit-nocode');
  var permitForm = document.getElementById('permit-form');
  var permitConfirm = document.getElementById('permit-confirm');

  function openPermitDialog() {
    permitError.hidden = true;
    permitError.textContent = '';
    permitInput.value = '';
    var none = permitCodes <= 0;
    permitNoCode.hidden = !none;
    permitForm.hidden = none;
    permitConfirm.hidden = none;
    modal.hidden = false;
    if (!none) setTimeout(function () { permitInput.focus(); }, 50);
  }
  function closePermitDialog() { modal.hidden = true; }
  function permitFail(text) { permitError.textContent = text; permitError.hidden = false; }

  document.getElementById('permit-cancel').addEventListener('click', closePermitDialog);
  modal.addEventListener('click', function (e) { if (e.target === modal) closePermitDialog(); });
  permitConfirm.addEventListener('click', function () {
    var code = permitInput.value.trim().toUpperCase();
    if (!code) { permitFail('Inserisci il codice del permesso.'); return; }
    if (!/^[A-Z0-9]{6}$/.test(code)) { permitFail('Il codice è di 6 lettere o cifre.'); return; }
    closePermitDialog();
    send('permit_start', code);
  });
  permitInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') permitConfirm.click(); });

  function send(type, code) {
    if (!fix || sending) return;
    if (type === 'permit_start' && !code) { openPermitDialog(); return; }
    sending = true;
    updateButtons();
    resultBox.innerHTML = '';
    fetch('/api/clock.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ type: type, lat: fix.lat, lng: fix.lng, accuracy: fix.accuracy, fix_ts: fix.ts, code: code })
    }).then(function (res) {
      return res.json().then(function (data) { return { status: res.status, data: data }; });
    }).then(function (r) {
      var d = r.data || {};
      if (r.status === 401) { window.location.href = '/login.php'; return; }
      if (d.error && d.ok === undefined) { showResult(false, d.error); return; }
      showResult(!!d.ok, d.message || (d.ok ? 'Registrata.' : 'Rifiutata.'));
      if (d.clocked_at) prependToday(d);
      if (d.allowed && d.allowed.length) { allowed = d.allowed; renderButtons(); }
      if (typeof d.permit_codes_today === 'number') permitCodes = d.permit_codes_today;
      if (d.ok) {
        var hm = d.clocked_at.substr(11, 5);
        if (d.type === 'out') stateBox.innerHTML = '<span class="badge badge-out">Non in servizio</span>';
        else if (d.type === 'break_start') stateBox.innerHTML = '<span class="badge badge-warn">In pausa</span> dalle ' + hm;
        else if (d.type === 'permit_start') stateBox.innerHTML = '<span class="badge badge-info">Fuori per permesso</span> dalle ' + hm;
        else stateBox.innerHTML = '<span class="badge badge-in">In servizio</span>';
        if (navigator.vibrate) navigator.vibrate(80);
      }
    }).catch(function () {
      showResult(false, 'Errore di rete. Controlla la connessione e riprova.');
    }).then(function () {
      sending = false;
      updateButtons();
    });
  }

  renderButtons();
  startWatch();
})();
