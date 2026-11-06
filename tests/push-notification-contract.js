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

const payload = {
  titre: 'Nouvelle commande · Table 7',
  texte: '32 MAD · 1 article',
  url: '/caisse/?c=42',
  tag: 'tuc-cmd-42',
  commande: 42,
  requireInteraction: true,
  vibrate: [120, 60, 120, 60, 220],
};

async function envoyerPushAvecClients(clientsPourTest) {
  clients = clientsPourTest;
  let completion;
  handlers.get('push')({
    data: { json: () => payload },
    waitUntil: (promise) => { completion = promise; },
  });
  await completion;
}

async function main() {
  await envoyerPushAvecClients([]);
  assert.equal(notifications.length, 1, 'le push en arrière-plan affiche une notification système');
  assert.equal(notifications[0].title, payload.titre, 'la notification identifie la table 7');
  assert.equal(notifications[0].options.body, payload.texte, 'la notification donne le total et le nombre d’articles');
  assert.equal(notifications[0].options.silent, false, 'la notification demande le son système par défaut');
  assert.equal(notifications[0].options.requireInteraction, true, 'la notification reste visible jusqu’à action/fermeture');
  assert.deepEqual(Array.from(notifications[0].options.vibrate), payload.vibrate, 'la notification demande une vibration');

  let refreshed = false;
  await envoyerPushAvecClients([{
    visibilityState: 'visible',
    focused: true,
    postMessage: (message) => { postedMessages.push(message); refreshed = true; },
  }]);
  assert.equal(refreshed, true, 'si la caisse est au premier plan, le push demande son rafraîchissement');
  assert.deepEqual(JSON.parse(JSON.stringify(postedMessages[0])), { type: 'tuc-rafraichir', commande: 42 });
  assert.equal(notifications.length, 1, 'pas de notification en double quand la caisse est déjà visible');
  console.log('PASS: push Table 7 en arrière-plan → notification non silencieuse, persistante et vibrante; caisse visible → rafraîchissement');
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
