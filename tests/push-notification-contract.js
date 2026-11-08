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
const cashierPayload = {
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

  await envoyerPush(cashierPayload, []);
  assert.equal(notifications.length, 2, 'le caissier reçoit aussi la commande');
  assert.equal(notifications[1].options.silent, true, 'la notification de secours du caissier reste silencieuse');
  assert.deepEqual(Array.from(notifications[1].options.vibrate), [], 'le caissier n’a pas de vibration de serveur');

  let refreshed = false;
  await envoyerPush(serverPayload, [{
    visibilityState: 'visible',
    focused: true,
    postMessage: (message) => { postedMessages.push(message); refreshed = true; },
  }]);
  assert.equal(refreshed, true, 'si la caisse serveur est au premier plan, le push demande son rafraîchissement');
  assert.deepEqual(JSON.parse(JSON.stringify(postedMessages[0])), { type: 'tuc-rafraichir', commande: 42 });
  assert.equal(notifications.length, 2, 'pas de notification système en double quand la caisse est déjà visible');
  console.log('PASS: Table 7 → serveur bip/vibreur, caissier notification silencieuse, caisse visible rafraîchie');
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
