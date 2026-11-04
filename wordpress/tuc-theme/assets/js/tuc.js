/* =========================================================
   TUC Market — scripts du thème
   Aucune dépendance. 4 Ko. Uniquement de l'interface.
   ========================================================= */
(function () {
  'use strict';

  /* ---- flèches des rails ---- */
  document.querySelectorAll('.sec').forEach(function (sec) {
    var rail = sec.querySelector('.rail');
    var prev = sec.querySelector('[data-prev]');
    var next = sec.querySelector('[data-next]');
    if (!rail || !prev || !next) return;

    function step() { return Math.max(rail.clientWidth * 0.8, 170); }
    prev.addEventListener('click', function () { rail.scrollBy({ left: -step(), behavior: 'smooth' }); });
    next.addEventListener('click', function () { rail.scrollBy({ left: step(), behavior: 'smooth' }); });

    function upd() {
      var max = rail.scrollWidth - rail.clientWidth - 2;
      prev.disabled = rail.scrollLeft <= 2;
      next.disabled = rail.scrollLeft >= max || max <= 0;
    }
    rail.addEventListener('scroll', upd, { passive: true });
    upd();
  });

  /* ---- ancres des catégories ---- */
  document.querySelectorAll('.cat').forEach(function (a) {
    var href = a.getAttribute('href') || '';
    if (href.charAt(0) !== '#') return;
    a.addEventListener('click', function (e) {
      var t = document.querySelector(href);
      if (!t) return;
      e.preventDefault();
      window.scrollTo({ top: t.offsetTop - 12, behavior: 'smooth' });
    });
  });

  /* ---- quartier : modale légère ---- */
  var btn = document.getElementById('tuc-addr');
  if (!btn) return;

  var QUARTIERS = ['Maârif', 'Gauthier', 'Racine', 'Anfa', 'Bourgogne', 'Derb Ghallef',
    'Sidi Maârouf', 'Hay Hassani', 'Aïn Diab', 'Oulfa', 'CIL', 'Beauséjour',
    'Bouskoura', 'Sidi Belyout', 'Hay Mohammadi', 'Derb Sultan'];

  btn.addEventListener('click', function () {
    var scrim = document.createElement('div');
    scrim.style.cssText = 'position:fixed;inset:0;z-index:200;background:rgba(15,15,15,.45);'
      + 'display:flex;align-items:flex-end;justify-content:center';
    scrim.innerHTML =
      '<div style="width:100%;max-width:520px;background:#fff;border-radius:20px 20px 0 0;'
      + 'padding:20px 16px 28px;max-height:86vh;overflow:auto;box-shadow:0 -12px 40px rgba(0,0,0,.3)">'
      + '<div style="display:flex;align-items:center;gap:12px">'
      + '<h2 style="margin:0;font-size:19px;font-weight:800;letter-spacing:-.4px">Où cherchez-vous ?</h2>'
      + '<button id="tuc-x" style="margin-inline-start:auto;width:32px;height:32px;border-radius:50%;'
      + 'background:#F7F7F7;display:flex;align-items:center;justify-content:center;font-size:16px">✕</button></div>'
      + '<button id="tuc-geo" style="margin-top:14px;width:100%;height:48px;border-radius:99px;'
      + 'background:#E8F7F3;color:#0A8166;font-size:14px;font-weight:800">Utiliser ma position</button>'
      + '<div style="margin:16px 0 8px;font-size:10.5px;font-weight:800;letter-spacing:.08em;'
      + 'text-transform:uppercase;color:#9A9A9A">Quartiers</div>'
      + '<div id="tuc-q" style="display:grid;grid-template-columns:1fr 1fr;gap:7px">'
      + QUARTIERS.map(function (n) {
          return '<button class="tq" data-q="' + n + '" style="height:42px;border-radius:10px;'
            + 'background:#F7F7F7;font-size:12.5px;font-weight:700;padding:0 11px;text-align:start">' + n + '</button>';
        }).join('')
      + '</div></div>';
    document.body.appendChild(scrim);

    scrim.querySelector('#tuc-x').addEventListener('click', function () { scrim.remove(); });
    scrim.addEventListener('click', function (e) { if (e.target === scrim) scrim.remove(); });
    scrim.querySelector('#tuc-geo').addEventListener('click', function () {
      if (!navigator.geolocation) return;
      navigator.geolocation.getCurrentPosition(function () {
        setQuartier('Maârif'); scrim.remove();
      }, function () {}, { maximumAge: 600000, timeout: 8000, enableHighAccuracy: false });
    });
    scrim.querySelectorAll('.tq').forEach(function (b) {
      b.addEventListener('click', function () { setQuartier(b.dataset.q); scrim.remove(); });
    });
  });

  function setQuartier(q) {
    var t = document.getElementById('tuc-addr-txt');
    if (t) t.textContent = q;
    btn.classList.remove('todo');
    try { localStorage.setItem('tuc_quartier', q); } catch (e) {}
  }

  try {
    var saved = localStorage.getItem('tuc_quartier');
    if (saved) setQuartier(saved);
  } catch (e) {}
})();
