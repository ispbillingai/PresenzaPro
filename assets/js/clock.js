(function () {
  'use strict';
  var root = document.getElementById('clock');
  if (!root) return;

  var btn = document.getElementById('btn-clock');
  var geoBox = document.getElementById('geo-status');
  var resultBox = document.getElementById('result');
  var stateBox = document.getElementById('clock-state');
  var list = document.getElementById('today-list');
  var timeEl = document.getElementById('clock-time');

  var nextType = root.dataset.next;
  var csrf = root.dataset.csrf;
  var maxAccuracy = parseFloat(root.dataset.maxAccuracy || '150');
  var locations = [];
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

  function updateButton() {
    btn.className = 'btn-clock ' + nextType;
    btn.textContent = nextType === 'in' ? 'Timbra ENTRATA' : 'Timbra USCITA';
    btn.disabled = sending || !fix || locations.length === 0;
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
      if (n.inside) {
        setGeo('inside', 'Sei presso ' + n.loc.name, 'Distanza ' + d + ' m · precisione ±' + acc + ' m');
      } else {
        setGeo('outside', 'Fuori sede: ' + d + ' m da ' + n.loc.name,
          'Raggio consentito ' + n.loc.radius + ' m · precisione ±' + acc + ' m');
      }
      if (fix.accuracy > maxAccuracy) {
        setGeo('waiting', 'Precisione GPS bassa (±' + acc + ' m)',
          'Serve almeno ±' + Math.round(maxAccuracy) + ' m. Attiva il GPS e spostati all\'aperto.');
      }
    }
    updateButton();
  }

  function onError(err) {
    fix = null;
    var msg = 'Impossibile rilevare la posizione.';
    if (err && err.code === 1) msg = 'Permesso posizione negato. Abilitalo nelle impostazioni del browser e ricarica.';
    else if (err && err.code === 2) msg = 'Posizione non disponibile. Attiva il GPS.';
    else if (err && err.code === 3) msg = 'Timeout nel rilevamento della posizione. Riprovo…';
    setGeo('outside', 'Posizione non disponibile', msg);
    updateButton();
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
    navigator.geolocation.watchPosition(onPosition, onError, {
      enableHighAccuracy: true,
      maximumAge: 5000,
      timeout: 20000
    });
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
    left.textContent = t + ' · ' + (r.type === 'in' ? 'Entrata' : 'Uscita') + (r.location ? ' · ' + r.location : '');
    var badge = document.createElement('span');
    badge.className = 'badge ' + (r.ok ? 'badge-ok' : 'badge-rej');
    badge.textContent = r.ok ? 'OK' : 'Rifiutata';
    li.appendChild(left);
    li.appendChild(badge);
    list.insertBefore(li, list.firstChild);
  }

  btn.addEventListener('click', function () {
    if (!fix || sending) return;
    sending = true;
    updateButton();
    resultBox.innerHTML = '';
    fetch('/api/clock.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({
        type: nextType,
        lat: fix.lat,
        lng: fix.lng,
        accuracy: fix.accuracy,
        fix_ts: fix.ts
      })
    }).then(function (res) {
      return res.json().then(function (data) { return { status: res.status, data: data }; });
    }).then(function (r) {
      var d = r.data || {};
      if (r.status === 401) { window.location.href = '/login.php'; return; }
      if (d.error && d.ok === undefined) { showResult(false, d.error); return; }
      showResult(!!d.ok, d.message || (d.ok ? 'Registrata.' : 'Rifiutata.'));
      if (d.clocked_at) prependToday(d);
      if (d.next_type) nextType = d.next_type;
      if (d.ok) {
        if (d.type === 'in') {
          stateBox.innerHTML = '<span class="badge badge-in">In servizio</span> dalle ' + d.clocked_at.substr(11, 5);
        } else {
          stateBox.innerHTML = '<span class="badge badge-out">Non in servizio</span>';
        }
        if (navigator.vibrate) navigator.vibrate(80);
      }
    }).catch(function () {
      showResult(false, 'Errore di rete. Controlla la connessione e riprova.');
    }).then(function () {
      sending = false;
      updateButton();
    });
  });

  updateButton();
  startWatch();
})();
