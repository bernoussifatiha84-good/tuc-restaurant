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
  var searchUrl = dialog.dataset.searchUrl || window.location.origin + '/';
  var MAX_NEIGHBORHOOD_DISTANCE_METERS = 25000;

  trigger.addEventListener('click', function () {
    if (!dialog.open) dialog.showModal();
    if (message) message.textContent = '';
  });
  if (closeButton) closeButton.addEventListener('click', function () { dialog.close(); });
  dialog.addEventListener('click', function (event) {
    if (event.target === dialog) dialog.close();
  });

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
      || !Number.isFinite(accuracy) || accuracy <= 0 || accuracy > 5000) {
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

    // Seul le slug de quartier, jamais la latitude/longitude, est placé dans l’URL.
    var destination = new URL(searchUrl, window.location.href);
    destination.search = '';
    destination.searchParams.set('q', nearest.slug);
    window.location.assign(destination.href);
  }

  if (geoButton) geoButton.addEventListener('click', function () {
    if (!navigator.geolocation) {
      message.textContent = 'La géolocalisation n’est pas prise en charge. Choisissez un quartier dans la liste.';
      return;
    }
    geoButton.disabled = true;
    message.textContent = 'Recherche du quartier le plus proche…';
    navigator.geolocation.getCurrentPosition(function (position) {
      try { goToNeighborhood(position); }
      catch (error) { message.textContent = error.message; }
      finally { geoButton.disabled = false; }
    }, function (error) {
      geoButton.disabled = false;
      if (error.code === 1) message.textContent = 'Permission refusée. Vous pouvez choisir un quartier manuellement; aucun service n’est bloqué.';
      else if (error.code === 3) message.textContent = 'La recherche a expiré. Réessayez ou choisissez un quartier manuellement.';
      else message.textContent = 'Position indisponible. Réessayez ou choisissez un quartier manuellement.';
    }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
  });
})();
