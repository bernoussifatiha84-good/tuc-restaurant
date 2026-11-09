/* =========================================================
   TUC — service worker de la caisse
   Servi à /caisse/sw.js (obligatoire : un SW ne contrôle que son dossier).

   Rôle : afficher la notification de commande quand l'écran est
   éteint ou que le navigateur est en arrière-plan — c'est-à-dire
   exactement le moment où un dashboard classique ne sert à rien.

   Pas de cache agressif : une caisse doit voir les vraies
   commandes, pas une version d'hier.
   ========================================================= */

const VERSION = 'tuc-caisse-v1';
const SHELL = [
  './icone-192.png',
  './icone-512.png',
  './fonts/manrope.woff2',
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(VERSION).then((c) => c.addAll(SHELL)).catch(() => {}).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((noms) => Promise.all(noms.filter((n) => n !== VERSION).map((n) => caches.delete(n))))
      .then(() => self.clients.claim())
  );
});

/* ---------------- fetch : réseau d'abord ---------------- */
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;

  // le HTML et l'API ne sont JAMAIS servis depuis le cache
  const dynamique = url.pathname.includes('/wp-json/') || e.request.mode === 'navigate';
  if (dynamique) return;

  e.respondWith(
    caches.match(e.request).then((hit) => hit || fetch(e.request).catch(() => hit))
  );
});

/* ---------------- push : le cœur du module ---------------- */
self.addEventListener('push', (e) => {
  let d = { titre: 'TUC Caisse', texte: '', url: './', tag: 'tuc' };
  try {
    if (e.data) d = Object.assign(d, e.data.json());
  } catch (_) {
    d.texte = e.data ? e.data.text() : '';
  }

  e.waitUntil((async () => {
    const liste = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

    // On rafraîchit la page si elle est ouverte : elle affichera la commande en
    // tête, tout de suite.
    liste.forEach((c) => c.postMessage({ type: 'tuc-rafraichir', commande: d.commande || 0 }));

    // ET on affiche TOUJOURS la notification, même onglet visible.
    //
    // Avant, un onglet visible court-circuitait la notification : « le push est
    // superflu ». C'etait faux des que le son n'est pas arme — et il ne l'est
    // jamais au chargement (`son.actif = false`), ni apres un 401/403 qui
    // provoque un location.reload(). Resultat : caisse au premier plan, son
    // muet, aucune notification, un toast de 2,6 s. Une commande perdue.
    //
    // Le service worker ne peut pas lire l'etat du son (c'est un contexte de
    // page). Le choix retenu est donc : le signal durable est TOUJOURS emis,
    // et c'est la PAGE qui retire son toast si elle a deja sonne.
    await self.registration.showNotification(d.titre, {
      body: d.texte,
      icon: './icone-192.png',
      tag: d.tag,
      renotify: true,
      silent: d.silent === true,
      requireInteraction: d.requireInteraction !== false,
      vibrate: Array.isArray(d.vibrate) ? d.vibrate : [],
      badge: './icone-192.png',
      data: { url: d.url || './', commande: d.commande || 0 },
      actions: [{ action: 'ouvrir', title: 'Voir la commande' }],
    });
  })());
});

/* ---------------- clic sur la notification ---------------- */
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const cible = (e.notification.data && e.notification.data.url) || './';

  e.waitUntil((async () => {
    const liste = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of liste) {
      if (c.url.includes('/caisse')) {
        c.postMessage({ type: 'tuc-rafraichir', commande: (e.notification.data || {}).commande || 0 });
        return c.focus();
      }
    }
    return self.clients.openWindow(cible);
  })());
});

/* ---------------- la page demande un rafraîchissement ---------------- */
self.addEventListener('message', (e) => {
  if (e.data === 'tuc-activer') self.skipWaiting();
});
