/* =========================================================
   TUC Market — interactions du thème, sans dépendance.
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

    function update() {
      var max = rail.scrollWidth - rail.clientWidth - 2;
      prev.disabled = rail.scrollLeft <= 2;
      next.disabled = rail.scrollLeft >= max || max <= 0;
    }
    rail.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update, { passive: true });
    update();
  });

  /* ---- ancres des catégories ---- */
  document.querySelectorAll('.cat[href^="#"]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      var target = document.getElementById(link.hash.slice(1));
      if (!target) return;
      event.preventDefault();
      target.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
      history.replaceState(null, '', link.hash);
    });
  });

  /* ---- choix de zone de recherche : la position exacte reste dans le navigateur ---- */
  var trigger = document.getElementById('tuc-addr');
  var dialog = document.getElementById('tuc-location-dialog');
  if (!trigger || !dialog) return;

  var geoButton = document.getElementById('tuc-use-location');
  var closeButton = document.getElementById('tuc-location-close');
  var message = document.getElementById('tuc-location-message');
  var intro = document.getElementById('tuc-location-intro');
  var manual = document.getElementById('tuc-manual-location');
  var manualButton = document.getElementById('tuc-location-choose-manual');
  var backButton = document.getElementById('tuc-location-back');
  var searchUrl = dialog.dataset.searchUrl || window.location.origin + '/';
  var MAX_NEIGHBORHOOD_DISTANCE_METERS = 25000;
  var MAX_SEARCH_ACCURACY_METERS = 1000;
  var ONBOARDING_KEY = 'tuc-location-onboarding-v1';
  var SEARCH_POSITION_KEY = 'tuc-search-position-v1';
  var SEARCH_NEIGHBORHOOD_KEY = 'tuc-search-neighborhood-v1';

  function rememberChoice() {
    try { localStorage.setItem(ONBOARDING_KEY, 'done'); } catch (_) { /* préférence de confort facultative */ }
  }
  function showManualChoice() {
    if (intro) intro.hidden = true;
    if (manual) manual.hidden = false;
    var firstChoice = manual && manual.querySelector('a');
    if (firstChoice) firstChoice.focus();
  }
  function showIntro() {
    if (intro) intro.hidden = false;
    if (manual) manual.hidden = true;
    if (geoButton) geoButton.focus();
  }
  function openDialog(manualFirst) {
    if (!dialog.open) dialog.showModal();
    if (message) message.textContent = '';
    if (manualFirst) showManualChoice(); else showIntro();
  }

  trigger.addEventListener('click', function () { openDialog(true); });
  if (manualButton) manualButton.addEventListener('click', showManualChoice);
  if (backButton) backButton.addEventListener('click', showIntro);
  if (closeButton) closeButton.addEventListener('click', function () { rememberChoice(); dialog.close(); });
  dialog.addEventListener('cancel', rememberChoice);
  dialog.addEventListener('click', function (event) {
    if (event.target === dialog) { rememberChoice(); dialog.close(); }
  });
  dialog.querySelectorAll('.tuc-city-list a, .tuc-quartier-list a').forEach(function (link) {
    link.addEventListener('click', function () {
      rememberChoice();
      try {
        sessionStorage.removeItem(SEARCH_POSITION_KEY);
        sessionStorage.removeItem(SEARCH_NEIGHBORHOOD_KEY);
      } catch (_) { /* navigation manuelle sans stockage */ }
    });
  });

  // Accueil : présenter notre propre explication avant toute demande native du navigateur.
  // Aucune permission GPS n'est sollicitée à l'ouverture automatique de cette boîte.
  var locationParams = new URLSearchParams(window.location.search);
  var alreadyFiltered = locationParams.has('q') || locationParams.has('ville');
  var onboardingDone = false;
  try { onboardingDone = localStorage.getItem(ONBOARDING_KEY) === 'done'; } catch (_) { /* on peut toujours demander via le bouton */ }
  if (!alreadyFiltered && !onboardingDone) {
    window.setTimeout(function () {
      if (!dialog.open) openDialog(false);
    }, 180);
  }

  function distanceMeters(lat1, lng1, lat2, lng2) {
    var radians = function (degrees) { return degrees * Math.PI / 180; };
    var dLat = radians(lat2 - lat1);
    var dLng = radians(lng2 - lng1);
    var a = Math.sin(dLat / 2) ** 2
      + Math.cos(radians(lat1)) * Math.cos(radians(lat2)) * Math.sin(dLng / 2) ** 2;
    return 6371000 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function goToNeighborhood(position) {
    var latitude = Number(position.coords.latitude);
    var longitude = Number(position.coords.longitude);
    var accuracy = Number(position.coords.accuracy);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
      || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180
      || !Number.isFinite(accuracy) || accuracy <= 0 || accuracy > MAX_SEARCH_ACCURACY_METERS) {
      throw new Error('La position n’est pas assez précise pour choisir un quartier. Choisissez-le dans la liste.');
    }

    var nearest = null;
    document.querySelectorAll('.tuc-quartier-list [data-quartier]').forEach(function (link) {
      var lat = Number(link.dataset.centerLat);
      var lng = Number(link.dataset.centerLng);
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
      var distance = distanceMeters(latitude, longitude, lat, lng);
      if (!nearest || distance < nearest.distance) nearest = { slug: link.dataset.quartier, distance: distance };
    });

    if (!nearest || nearest.distance > MAX_NEIGHBORHOOD_DISTANCE_METERS) {
      throw new Error('Votre position est en dehors des quartiers actuellement référencés. Choisissez une zone dans la liste.');
    }

    // Coordonnées de recherche conservées uniquement dans sessionStorage : jamais dans l'URL ni envoyées au serveur.
    try {
      sessionStorage.setItem(SEARCH_POSITION_KEY, JSON.stringify({ lat: latitude, lng: longitude, accuracy: accuracy, capturedAt: Math.floor(Date.now() / 1000) }));
      sessionStorage.setItem(SEARCH_NEIGHBORHOOD_KEY, nearest.slug);
    } catch (_) {
      try { sessionStorage.removeItem(SEARCH_POSITION_KEY); sessionStorage.removeItem(SEARCH_NEIGHBORHOOD_KEY); } catch (_) { /* stockage bloqué */ }
      throw new Error('Le stockage temporaire est bloqué par le navigateur. Choisissez votre ville ou votre quartier manuellement.');
    }
    rememberChoice();
    var destination = new URL(searchUrl, window.location.href);
    destination.search = '';
    destination.searchParams.set('ville', 'casablanca');
    window.location.assign(destination.href);
  }

  if (geoButton) geoButton.addEventListener('click', function () {
    if (!navigator.geolocation) {
      message.textContent = 'La géolocalisation n’est pas prise en charge. Choisissez une ville ou un quartier.';
      showManualChoice();
      return;
    }
    geoButton.disabled = true;
    message.textContent = 'Recherche de restaurants proches…';
    navigator.geolocation.getCurrentPosition(function (position) {
      try { goToNeighborhood(position); }
      catch (error) { message.textContent = error.message; showManualChoice(); }
      finally { geoButton.disabled = false; }
    }, function (error) {
      geoButton.disabled = false;
      if (error.code === 1) message.textContent = 'Accès à la position refusé. Choisissez une ville ou un quartier ci-dessous.';
      else if (error.code === 3) message.textContent = 'La recherche a expiré. Choisissez une ville ou un quartier, ou réessayez.';
      else message.textContent = 'Position indisponible. Choisissez une ville ou un quartier, ou réessayez.';
      showManualChoice();
    }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
  });

  function applyLocalDistances() {
    var results = document.querySelector('.quartier-results');
    if (!results) return;
    var saved;
    try { saved = JSON.parse(sessionStorage.getItem(SEARCH_POSITION_KEY) || 'null'); } catch (_) { saved = null; }
    var age = saved && Math.floor(Date.now() / 1000) - Number(saved.capturedAt);
    var nearest = document.getElementById('tuc-location-nearest');
    if (!saved) {
      if (nearest) { nearest.textContent = 'Partagez votre position si vous souhaitez voir les distances et le tri par proximité.'; nearest.hidden = false; }
      return;
    }
    if (!Number.isFinite(Number(saved.lat)) || Number(saved.lat) < -90 || Number(saved.lat) > 90
      || !Number.isFinite(Number(saved.lng)) || Number(saved.lng) < -180 || Number(saved.lng) > 180
      || !Number.isFinite(Number(saved.accuracy)) || Number(saved.accuracy) <= 0 || Number(saved.accuracy) > MAX_SEARCH_ACCURACY_METERS
      || !Number.isFinite(age) || age < 0 || age > 30 * 60) {
      try { sessionStorage.removeItem(SEARCH_POSITION_KEY); sessionStorage.removeItem(SEARCH_NEIGHBORHOOD_KEY); } catch (_) { /* stockage indisponible */ }
      if (nearest) { nearest.textContent = 'Votre position temporaire a expiré. Partagez-la à nouveau pour voir les distances.'; nearest.hidden = false; }
      return;
    }
    window.setTimeout(function () {
      try { sessionStorage.removeItem(SEARCH_POSITION_KEY); sessionStorage.removeItem(SEARCH_NEIGHBORHOOD_KEY); } catch (_) { /* stockage indisponible */ }
    }, 30 * 60 * 1000);

    var cards = Array.prototype.slice.call(results.querySelectorAll('.quartier-card'));
    cards.forEach(function (card) {
      var lat = Number(card.dataset.lat);
      var lng = Number(card.dataset.lng);
      if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
        card.dataset.distance = 'unavailable';
        return;
      }
      var meters = distanceMeters(Number(saved.lat), Number(saved.lng), lat, lng);
      card.dataset.distance = String(meters);
      var output = card.querySelector('.quartier-distance');
      if (output) {
        var km = (meters / 1000).toLocaleString('fr-MA', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        output.textContent = '≈ ' + km + ' km à vol d’oiseau';
        output.hidden = false;
      }
    });
    cards.sort(function (a, b) {
      var aDistance = a.dataset.distance === 'unavailable' ? Infinity : Number(a.dataset.distance);
      var bDistance = b.dataset.distance === 'unavailable' ? Infinity : Number(b.dataset.distance);
      return aDistance - bDistance;
    }).forEach(function (card) { results.appendChild(card); });

    nearest = document.getElementById('tuc-location-nearest');
    var nearestSlug = '';
    try { nearestSlug = sessionStorage.getItem(SEARCH_NEIGHBORHOOD_KEY) || ''; } catch (_) { /* no-op */ }
    var selected = Array.prototype.find.call(dialog.querySelectorAll('[data-quartier]'), function (link) {
      return link.dataset.quartier === nearestSlug;
    });
    if (nearest) {
      var missing = cards.some(function (card) { return card.dataset.distance === 'unavailable'; });
      nearest.textContent = (selected ? 'Zone estimée : ' + selected.textContent + '. ' : '')
        + 'Distances approximatives à vol d’oiseau.'
        + (missing ? ' Certains restaurants n’ont pas renseigné leur position.' : '');
      nearest.hidden = false;
    }
  }
  applyLocalDistances();
})();
