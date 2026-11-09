const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../wordpress/tuc-market-core/assets/caisse/sw.js'), 'utf8');
const handlers = new Map();
const notifications = [];
const postedMessages = [];
let clients = [];
const self = {
  addEventListener: (type, handler) => handlers.set(type, handler),
  clients: { matchAll: async () => clients },
  location: { origin: 'https://example.test' },
  registration: {
    showNotification: async (title, options) => notifications.push({ title, options }),
  },
  skipWaiting: async () => {},
};
vm.runInNewContext(source, { self, URL, Promise, fetch: async () => { throw new Error('unused'); } });

const serverPayload = {
  titre: 'Nouvelle commande · Table 7',
  texte: '32 MAD · 1 article',
  url: '/caisse/?c=42',
  tag: 'tuc-cmd-42',
  commande: 42,
  silent: false,
  requireInteraction: true,
  vibrate: [120, 60, 120, 60, 220],
};
// Le cas silencieux n'est plus le caissier : depuis la décision propriétaire,
// tous les rôles caisse rattachés entendent. Le repli silencieux concerne un
// compte non rattaché ou rattaché à un autre restaurant.
const horsTenantPayload = {
  ...serverPayload,
  titre: 'Copie silencieuse · Table 7',
  silent: true,
  vibrate: [],
};

async function envoyerPush(payload, clientsPourTest) {
  clients = clientsPourTest;
  let completion;
  handlers.get('push')({
    data: { json: () => payload },
    waitUntil: (promise) => { completion = promise; },
  });
  await completion;
}

async function main() {
  await envoyerPush(serverPayload, []);
  assert.equal(notifications.length, 1, 'le push arrière-plan affiche la notification du serveur');
  assert.equal(notifications[0].title, serverPayload.titre, 'le serveur voit que la commande est à la table 7');
  assert.equal(notifications[0].options.body, serverPayload.texte, 'le serveur voit le total et le nombre d’articles');
  assert.equal(notifications[0].options.silent, false, 'le push du serveur demande le son système par défaut');
  assert.equal(notifications[0].options.requireInteraction, true, 'la notification serveur reste visible');
  assert.deepEqual(Array.from(notifications[0].options.vibrate), serverPayload.vibrate, 'le push serveur demande une vibration');

  await envoyerPush(horsTenantPayload, []);
  assert.equal(notifications.length, 2, 'le compte hors tenant reçoit aussi la commande');
  assert.equal(notifications[1].options.silent, true, 'la copie hors tenant reste silencieuse');
  assert.deepEqual(Array.from(notifications[1].options.vibrate), [], 'le compte hors tenant n’a pas de vibration');

  let refreshed = false;
  await envoyerPush(serverPayload, [{
    visibilityState: 'visible',
    focused: true,
    postMessage: (message) => { postedMessages.push(message); refreshed = true; },
  }]);
  assert.equal(refreshed, true, 'si la caisse serveur est au premier plan, le push demande son rafraîchissement');
  assert.deepEqual(JSON.parse(JSON.stringify(postedMessages[0])), { type: 'tuc-rafraichir', commande: 42 });
  // A2 : la notification systeme est TOUJOURS emise, meme caisse visible.
  // L'ancienne assertion (length === 2) verrouillait le court-circuit qui a
  // produit le defaut : onglet au premier plan + son non arme = aucun signal
  // durable. Ici : 3 notifications (arriere-plan, hors tenant, caisse visible).
  assert.equal(notifications.length, 3, 'la notification est emise meme quand la caisse est visible (A2)');
  assert.equal(notifications[2].title, serverPayload.titre, 'la caisse visible recoit la meme notification');
  console.log('PASS: Table 7 → caisse rattachée bip/vibreur, compte hors tenant silencieux, caisse visible rafraîchie');
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
