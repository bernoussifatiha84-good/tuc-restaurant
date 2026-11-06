const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { chromium } = require('playwright');

const baseUrl = process.env.TUC_MOBILE_E2E_URL || 'http://127.0.0.1:8080';
const staffUser = process.env.TUC_MOBILE_E2E_CASHIER_USER || 'tuc-mobile-cashier';
const password = process.env.TUC_MOBILE_E2E_PASSWORD || '';
const inboundSecret = process.env.TUC_N8N_INBOUND_SECRET || '';
const n8nUrl = (process.env.TUC_N8N_URL || 'http://127.0.0.1:5678').replace(/\/$/, '');
const customerPhone = '+212600000055';
const errors = [];
const orderIds = {};
let checkpoint = 'initialisation';

async function ajouterPlat(page) {
  const card = page.locator('.tuc-client-card').filter({ hasText: 'Plat initial E2E' });
  await card.locator('[data-add]').click();
  await page.locator('#tuc-customize-groups [data-group-id="format"] input[value="regular"]').check();
  await page.locator('#tuc-customize-form button[type="submit"]').click();
  await page.locator('#tuc-open-cart').waitFor({ state: 'visible' });
  await page.locator('#tuc-open-cart').click();
  await page.locator('#tuc-cart-dialog[open]').waitFor();
  assert((await page.locator('#tuc-cart-lines').innerText()).includes('Plat initial E2E'), 'le panier conserve le plat choisi');
}

async function choisirMode(page, mode) {
  await page.locator('#tuc-order-mode').selectOption(mode);
  assert.equal(await page.locator('#tuc-order-mode').inputValue(), mode, `mode ${mode} sélectionné`);
}

async function validerCommande(page, name) {
  await page.locator('#tuc-submit-order').click();
  await page.locator('#tuc-order-success:not([hidden])').waitFor({ timeout: 20000 });
  const reference = (await page.locator('#tuc-order-success strong').innerText()).replace(/[^0-9]/g, '');
  assert(reference.length > 0, `${name}: la commande retourne une référence`);
  orderIds[name] = reference;
  return reference;
}

async function faireTraiterParN8n(code, tel) {
  const timestamp = String(Math.floor(Date.now() / 1000));
  const eventId = `checkout-e2e-${crypto.randomUUID()}`;
  const normalizedPhone = tel.replace(/[^0-9]/g, '');
  const canonical = [timestamp, eventId, code.toUpperCase(), normalizedPhone].join('\n');
  const signature = crypto.createHmac('sha256', inboundSecret).update(canonical).digest('hex');
  const body = JSON.stringify({ event_id: eventId, code, tel });
  const headers = {
    'Content-Type': 'application/json',
    'X-TUC-Timestamp': timestamp,
    'X-TUC-Signature': signature,
  };
  const endpoints = [
    `${n8nUrl}/webhook/7b75d76f-f3a6-4579-bfc0-8a6e9561fd74/tuc-whatsapp-inbound-smoke`,
    `${n8nUrl}/webhook/tuc-whatsapp-inbound-smoke`,
  ];
  let response;
  for (const endpoint of endpoints) {
    response = await fetch(endpoint, { method: 'POST', headers, body });
    if (response.status !== 404) break;
  }
  const data = await response.json().catch(() => ({}));
  assert.equal(response.status, 200, `le workflow n8n traite le message WhatsApp: ${JSON.stringify(data)}`);
  assert(data.ok === true && data.reponse.includes('Identifiant :') && data.reponse.includes('Connexion directe'));
  assert(data.reponse.includes(normalizedPhone), 'n8n retourne le téléphone vérifié du nouveau compte');
  assert(!data.reponse.includes('Mot de passe :'), 'aucun mot de passe en clair n’est envoyé dans WhatsApp');
  return data;
}

async function connecterParWhatsApp(page, tel) {
  await page.locator('#tuc-submit-order').click();
  await page.locator('#tuc-account-dialog[open]').waitFor();
  const responsePromise = page.waitForResponse((response) => response.url().includes('/wp-json/tuc/v1/wa-lien'));
  await page.locator('#tuc-start-whatsapp').click();
  await page.locator('#tuc-open-whatsapp:not([hidden])').waitFor();
  const linkData = await (await responsePromise).json();
  const waUrl = new URL(await page.locator('#tuc-open-whatsapp').getAttribute('href'));
  assert.equal(waUrl.hostname, 'wa.me');
  assert((waUrl.searchParams.get('text') || '').includes(linkData.code));
  const n8nReply = await faireTraiterParN8n(linkData.code, tel);
  await page.locator('#tuc-wa-resume:not([hidden])').waitFor({ timeout: 12000 });
  await page.locator('#tuc-wa-resume').click();
  await page.waitForFunction(() => Boolean(window.TUCClient?.loggedIn), undefined, { timeout: 20000 });
  await page.locator('#tuc-cart-dialog[open]').waitFor({ timeout: 10000 });
  return n8nReply;
}

async function connecterWordPress(page, login) {
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(login);
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await page.waitForLoadState('domcontentloaded');
  assert(!page.url().includes('wp-login.php'), `connexion WordPress pour ${login}`);
}

async function modifierPlat(managerPage, itemId, changes) {
  const result = await managerPage.evaluate(async ({ id, payload }) => {
    const response = await fetch(`${TUCPro.rest}menu/${id}`, {
      method: 'PATCH', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': TUCPro.nonce },
      body: JSON.stringify(payload),
    });
    return { status: response.status, body: await response.json().catch(() => ({})) };
  }, { id: itemId, payload: changes });
  assert.equal(result.status, 200, `le gérant met à jour le plat: ${JSON.stringify(result.body)}`);
  return result.body;
}

async function main() {
  assert(password.length >= 24, 'mot de passe E2E éphémère configuré');
  assert(inboundSecret.length >= 32, 'secret entrant n8n éphémère configuré');
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true,
    geolocation: { latitude: 33.589, longitude: -7.621, accuracy: 75 },
  });
  await context.grantPermissions(['geolocation'], { origin: new URL(baseUrl).origin });
  const page = await context.newPage();
  let activePage = page;
  page.on('pageerror', (error) => errors.push(error.stack || error.message));

  try {
    // 1. Nouveau client : panier → WhatsApp prérempli → webhook/n8n → lien magique → retour au panier → géolocalisation livraison.
    checkpoint = 'scénario 1 · création de compte WhatsApp et reprise du panier livraison';
    await page.goto(`${baseUrl}/tuc-mobile-e2e/`, { waitUntil: 'networkidle' });
    assert.equal(Boolean(await page.evaluate(() => TUCClient.loggedIn)), false, 'le premier client est anonyme');
    await ajouterPlat(page);
    await choisirMode(page, 'livraison');
    assert.equal(await page.locator('#tuc-location-panel').isVisible(), true, 'la livraison annonce une demande de position après connexion');
    assert.equal(await page.locator('#tuc-customer-phone').isVisible(), false, 'le numéro est obtenu du message WhatsApp, pas demandé manuellement');
    await page.locator('#tuc-submit-order').click();
    await page.locator('#tuc-account-dialog[open]').waitFor();
    const waResponsePromise = page.waitForResponse((response) => response.url().includes('/wp-json/tuc/v1/wa-lien'));
    await page.locator('#tuc-start-whatsapp').click();
    await page.locator('#tuc-open-whatsapp:not([hidden])').waitFor();
    const waResponseData = await (await waResponsePromise).json();
    assert(waResponseData && waResponseData.code && waResponseData.poll, 'la fenêtre obtient le code et le secret de polling');
    const waUrl = new URL(await page.locator('#tuc-open-whatsapp').getAttribute('href'));
    assert.equal(waUrl.hostname, 'wa.me', 'le CTA ouvre bien wa.me');
    const draft = waUrl.searchParams.get('text') || '';
    assert(draft.includes('JE VEUX CRÉER MON COMPTE') && draft.includes(waResponseData.code), 'le message WhatsApp est prérempli avec le code de liaison');
    const n8nReply = await faireTraiterParN8n(waResponseData.code, customerPhone);
    assert(n8nReply.reponse.includes(customerPhone.replace('+', '')) || n8nReply.reponse.includes(n8nReply.login), 'n8n répond avec l’identifiant du compte créé');
    await page.locator('#tuc-wa-resume:not([hidden])').waitFor({ timeout: 12000 });
    await page.locator('#tuc-wa-resume').click();
    await page.waitForFunction(
      () => location.pathname.includes('tuc-mobile-e2e') && Boolean(window.TUCClient?.loggedIn),
      undefined,
      { timeout: 20000 },
    );
    await page.locator('#tuc-cart-dialog[open]').waitFor({ timeout: 10000 });
    assert.equal(await page.locator('#tuc-order-mode').inputValue(), 'livraison', 'le panier reprend le mode livraison choisi avant WhatsApp');
    assert((await page.locator('#tuc-cart-lines').innerText()).includes('Plat initial E2E'), 'le panier est restauré après le lien magique');
    assert.equal(await page.locator('#tuc-customer-phone').inputValue(), customerPhone, 'le téléphone vérifié du compte est repris en caisse');
    assert.equal(await page.locator('#tuc-customer-phone').evaluate((input) => input.readOnly), true, 'le client ne peut pas remplacer le numéro WhatsApp vérifié');
    assert.equal(await page.locator('#tuc-location-panel').isVisible(), true, 'après connexion, le client est invité à partager sa position');
    assert.equal(await page.locator('#tuc-get-location').isVisible(), true, 'la géolocalisation reste sous consentement explicite');
    await page.locator('#tuc-get-location').click();
    await page.getByText('33.58900', { exact: false }).waitFor();
    assert.equal(await page.locator('#tuc-submit-order').isEnabled(), true, 'la livraison peut être confirmée après géolocalisation');
    orderIds.inscriptionLivraison = await validerCommande(page, 'inscriptionLivraison');

    // 2. Compte déjà connecté : retrait à emporter, sans frais et sans demander la position.
    checkpoint = 'scénario 2 · compte connecté, commande à emporter';
    await page.locator('[data-success-close]').click();
    await ajouterPlat(page);
    await choisirMode(page, 'emporter');
    assert.equal(await page.locator('#tuc-location-panel').isVisible(), false, 'le retrait ne demande pas la localisation');
    orderIds.emporter = await validerCommande(page, 'emporter');

    // 3. Compte déjà connecté : QR Table 7, mode imposé par le QR et pas de géolocalisation.
    checkpoint = 'scénario 3 · compte connecté, commande QR Table 7';
    const tablePage = await context.newPage();
    tablePage.on('pageerror', (error) => errors.push(error.stack || error.message));
    await tablePage.goto(`${baseUrl}/tuc-mobile-e2e/?t=7`, { waitUntil: 'networkidle' });
    assert.equal(Boolean(await tablePage.evaluate(() => TUCClient.loggedIn)), true, 'le compte reste connecté après navigation');
    await ajouterPlat(tablePage);
    assert.equal(await tablePage.locator('#tuc-order-mode').isVisible(), false, 'le QR fixe le mode à table');
    assert((await tablePage.locator('.tuc-table-checkout-note').innerText()).includes('table 7'));
    assert.equal(await tablePage.locator('#tuc-location-panel').isVisible(), false, 'la commande à table ne demande pas de position');
    orderIds.table = await validerCommande(tablePage, 'table');

    // 4. Compte déjà connecté : nouvelle livraison avec la position mémorisée au scénario 1.
    checkpoint = 'scénario 4 · livraison connectée avec localisation enregistrée';
    await tablePage.goto(`${baseUrl}/tuc-mobile-e2e/`, { waitUntil: 'networkidle' });
    await ajouterPlat(tablePage);
    await choisirMode(tablePage, 'livraison');
    assert.equal(await tablePage.locator('#tuc-location-panel').isVisible(), true);
    await tablePage.getByText('Position enregistrée pour les prochaines livraisons', { exact: false }).waitFor();
    assert.equal(await tablePage.locator('#tuc-get-location').isEnabled(), true, 'la position peut être actualisée mais elle est déjà enregistrée');
    orderIds.livraisonEnregistree = await validerCommande(tablePage, 'livraisonEnregistree');

    // 5. Compte connecté : nouvelle livraison avec position actualisée, puis vérification côté restaurant/livreur.
    checkpoint = 'scénario 5 · livraison connectée avec position actualisée et partage au livreur';
    const refreshedLocationPage = await context.newPage();
    refreshedLocationPage.on('pageerror', (error) => errors.push(error.stack || error.message));
    await refreshedLocationPage.goto(`${baseUrl}/tuc-mobile-e2e/`, { waitUntil: 'networkidle' });
    await ajouterPlat(refreshedLocationPage);
    await choisirMode(refreshedLocationPage, 'livraison');
    await refreshedLocationPage.locator('#tuc-get-location').click();
    await refreshedLocationPage.getByText('Position partagée pour cette livraison', { exact: false }).waitFor();
    orderIds.livraisonActualisee = await validerCommande(refreshedLocationPage, 'livraisonActualisee');

    const staffPage = await context.newPage();
    staffPage.on('pageerror', (error) => errors.push(error.stack || error.message));
    await staffPage.addInitScript(() => {
      Object.defineProperty(navigator, 'share', {
        configurable: true,
        value: async (payload) => { window.__sharedPayload = payload; },
      });
    });
    await staffPage.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await staffPage.locator('#user_login').fill(staffUser);
    await staffPage.locator('#user_pass').fill(password);
    await staffPage.locator('#wp-submit').click();
    await staffPage.waitForLoadState('domcontentloaded');
    assert(!staffPage.url().includes('wp-login.php'), 'connexion du caissier de test');
    await staffPage.goto(`${baseUrl}/caisse/`, { waitUntil: 'networkidle' });
    const deliveryCard = staffPage.locator(`#liste .carte[data-id="${orderIds.livraisonActualisee}"]`);
    await deliveryCard.waitFor();
    assert((await deliveryCard.innerText()).includes(customerPhone), 'la caisse affiche le numéro transmis depuis WhatsApp');
    assert.equal(await deliveryCard.locator('a[href="tel:+212600000055"]').count(), 1, 'un bouton appelle le client normalement');
    const waContact = deliveryCard.locator('a[href^="https://wa.me/212600000055"]');
    assert.equal(await waContact.count(), 1, 'un bouton ouvre WhatsApp avec le numéro client');
    const mapLink = deliveryCard.locator('a[href^="https://maps.google.com/?q="]');
    assert.equal(await mapLink.count(), 1, 'la position de livraison ouvre la carte');
    const share = deliveryCard.locator('[data-share-text]');
    assert.equal(await share.count(), 1, 'le bouton de partage au livreur est disponible');
    await share.click();
    const shared = await staffPage.evaluate(() => window.__sharedPayload || null);
    assert(shared && shared.text.includes(customerPhone) && shared.text.includes('maps.google.com'), 'le sélecteur natif reçoit numéro et lien de localisation; le client choisit WhatsApp puis son contact');

    // 6. Client de retour : le même numéro retrouve son compte et sa position sans créer un doublon.
    checkpoint = 'scénario 6 · client fidèle reconnu par WhatsApp';
    const returningContext = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    const returningPage = await returningContext.newPage();
    activePage = returningPage;
    returningPage.on('pageerror', (error) => errors.push(error.stack || error.message));
    await returningPage.goto(`${baseUrl}/tuc-mobile-e2e/`, { waitUntil: 'networkidle' });
    assert.equal(Boolean(await returningPage.evaluate(() => TUCClient.loggedIn)), false);
    await ajouterPlat(returningPage);
    await choisirMode(returningPage, 'livraison');
    const returningReply = await connecterParWhatsApp(returningPage, customerPhone);
    assert.equal(returningReply.cree, false, 'le numéro WhatsApp existant est retrouvé, pas réinscrit');
    assert.equal(await returningPage.locator('#tuc-customer-phone').inputValue(), customerPhone);
    await returningPage.getByText('Position enregistrée pour les prochaines livraisons', { exact: false }).waitFor();
    assert.equal(await returningPage.locator('#tuc-submit-order').isEnabled(), true, 'la position du compte rend la livraison possible sans nouvelle demande GPS');

    // 7. GPS refusé : bloquer la livraison, expliquer le refus et permettre le retrait.
    checkpoint = 'scénario 7 · GPS refusé avec repli à emporter';
    const fallbackContext = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    const fallbackPage = await fallbackContext.newPage();
    activePage = fallbackPage;
    fallbackPage.on('pageerror', (error) => errors.push(error.stack || error.message));
    await fallbackPage.addInitScript(() => {
      Object.defineProperty(navigator, 'geolocation', {
        configurable: true,
        value: { getCurrentPosition: (_success, failure) => failure({ code: 1 }) },
      });
    });
    await fallbackPage.goto(`${baseUrl}/tuc-mobile-e2e/`, { waitUntil: 'networkidle' });
    await ajouterPlat(fallbackPage);
    await choisirMode(fallbackPage, 'livraison');
    const fallbackPhone = '+212600000056';
    const fallbackReply = await connecterParWhatsApp(fallbackPage, fallbackPhone);
    assert.equal(fallbackReply.cree, true, 'le client de test GPS reçoit son propre compte');
    await fallbackPage.locator('#tuc-get-location').click();
    await fallbackPage.waitForFunction(() => document.querySelector('#tuc-location-status')?.textContent.includes('Autorisez la localisation'));
    assert.equal(await fallbackPage.locator('#tuc-submit-order').isDisabled(), true, 'aucune livraison ne part sans position consentie');
    await choisirMode(fallbackPage, 'emporter');
    assert.equal(await fallbackPage.locator('#tuc-location-panel').isVisible(), false);
    assert.equal(await fallbackPage.locator('#tuc-submit-order').isEnabled(), true, 'le client peut terminer en retrait sans GPS');
    orderIds.gpsRefuseEmporter = await validerCommande(fallbackPage, 'gpsRefuseEmporter');

    // 8. Réseau coupé après création serveur, puis reprise après rechargement : une seule commande.
    checkpoint = 'scénario 8 · réponse réseau perdue et retry idempotent';
    await fallbackPage.locator('[data-success-close]').click();
    await ajouterPlat(fallbackPage);
    await choisirMode(fallbackPage, 'emporter');
    let retryRequests = 0;
    let createdBeforeLostResponse = '';
    const retryKeys = [];
    await fallbackPage.route('**/wp-json/tuc/v1/commande', async (route) => {
      if (route.request().method() !== 'POST') return route.continue();
      retryRequests += 1;
      retryKeys.push(route.request().postDataJSON().cle);
      if (retryRequests === 1) {
        const response = await route.fetch();
        const data = await response.json();
        assert.equal(response.status(), 201, 'la première tentative a été créée côté serveur avant la coupure');
        createdBeforeLostResponse = String(data.commande);
        await route.abort();
        return;
      }
      await route.continue();
    });
    await fallbackPage.locator('#tuc-submit-order').click();
    await fallbackPage.locator('#tuc-checkout-error:not([hidden])').waitFor();
    const keyBeforeReload = await fallbackPage.evaluate(() => Object.entries(localStorage).find(([key]) => key.startsWith('tuc-pending-order-v1:'))?.[1] || '');
    assert(keyBeforeReload.length >= 24, 'la clé d’idempotence survit à une réponse réseau perdue');
    await fallbackPage.reload({ waitUntil: 'networkidle' });
    await fallbackPage.locator('#tuc-open-cart').click();
    await fallbackPage.locator('#tuc-cart-dialog[open]').waitFor();
    assert((await fallbackPage.locator('#tuc-cart-lines').innerText()).includes('Plat initial E2E'), 'le panier réapparaît après rechargement');
    assert.equal(await fallbackPage.evaluate(() => Object.entries(localStorage).find(([key]) => key.startsWith('tuc-pending-order-v1:'))?.[1] || ''), keyBeforeReload);
    orderIds.retry = await validerCommande(fallbackPage, 'retry');
    await fallbackPage.unroute('**/wp-json/tuc/v1/commande');
    assert.equal(orderIds.retry, createdBeforeLostResponse, 'la reprise retourne la même référence de commande');
    assert.equal(retryRequests, 2);
    assert.equal(retryKeys[0], retryKeys[1], 'le retry réutilise la même clé d’idempotence');

    // 9. Le menu change pendant le checkout : actualiser les prix, demander confirmation, retirer un plat épuisé.
    checkpoint = 'scénario 9 · prix modifié et plat épuisé pendant le checkout';
    await fallbackPage.locator('[data-success-close]').click();
    await ajouterPlat(fallbackPage);
    await choisirMode(fallbackPage, 'emporter');
    const itemId = Number(await fallbackPage.locator('.tuc-client-card').filter({ hasText: 'Plat initial E2E' }).locator('[data-add]').getAttribute('data-add'));
    const managerContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const managerPage = await managerContext.newPage();
    activePage = managerPage;
    managerPage.on('pageerror', (error) => errors.push(error.stack || error.message));
    await connecterWordPress(managerPage, 'tuc-mobile-e2e');
    await managerPage.goto(`${baseUrl}/espace-pro/`, { waitUntil: 'networkidle' });
    await managerPage.waitForFunction(() => Boolean(window.TUCPro));
    await modifierPlat(managerPage, itemId, { price: 25 });
    activePage = fallbackPage;
    await fallbackPage.locator('#tuc-submit-order').click();
    await fallbackPage.locator('#tuc-checkout-error:not([hidden])').waitFor();
    assert((await fallbackPage.locator('#tuc-checkout-error').innerText()).includes('prix ou les frais ont changé'));
    assert((await fallbackPage.locator('#tuc-checkout-total').innerText()).includes('25'), 'le total actualisé est visible avant confirmation');
    orderIds.prixConfirme = await validerCommande(fallbackPage, 'prixConfirme');
    await fallbackPage.locator('[data-success-close]').click();
    await ajouterPlat(fallbackPage);
    await choisirMode(fallbackPage, 'emporter');
    await modifierPlat(managerPage, itemId, { available: false });
    await fallbackPage.locator('#tuc-submit-order').click();
    await fallbackPage.locator('#tuc-checkout-error:not([hidden])').waitFor();
    assert((await fallbackPage.locator('#tuc-checkout-error').innerText()).includes('plat vient d’être épuisé'));
    assert((await fallbackPage.locator('#tuc-cart-lines').innerText()).includes('Votre panier est vide'), 'le plat épuisé est retiré du panier actualisé');
    assert.equal(await fallbackPage.locator('#tuc-order-success').isVisible(), false, 'aucune commande vide n’est créée');
    await modifierPlat(managerPage, itemId, { available: true, price: 20 });

    // 10. File restaurant : le personnel prend en charge la commande et le client suit les étapes sans données privées.
    checkpoint = 'scénario 10 · commande visible en caisse et suivi client';
    await fallbackPage.reload({ waitUntil: 'networkidle' });
    await ajouterPlat(fallbackPage);
    await choisirMode(fallbackPage, 'emporter');
    orderIds.suiviEnCours = await validerCommande(fallbackPage, 'suiviEnCours');
    const trackingState = await fallbackPage.evaluate(() => JSON.parse(localStorage.getItem('tuc-order-track-v1:tuc-mobile-e2e') || 'null'));
    assert(trackingState?.token, 'le client conserve le jeton privé de suivi');
    await fallbackPage.locator('[data-open-tracking]').click();
    await fallbackPage.locator('#tuc-tracking-content .tuc-tracking-state').filter({ hasText: 'Reçue' }).waitFor();
    await staffPage.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    const delayedOrderCard = staffPage.locator(`#liste .carte[data-id="${orderIds.suiviEnCours}"]`);
    await delayedOrderCard.waitFor({ timeout: 10000 });
    assert((await delayedOrderCard.innerText()).includes('À emporter'), 'la commande reçue apparaît dans la file du restaurant');
    for (const [statut, label, nextAction] of [['acceptee', 'Acceptée', 'En cuisine'], ['preparation', 'En préparation', 'est prêt']]) {
      const update = await staffPage.evaluate(async ({ id, statut }) => {
        const response = await fetch('/wp-json/tuc/v1/caisse/statut', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': document.querySelector('meta[name="csrf-token"]').content },
          body: JSON.stringify({ id, statut }),
        });
        return { status: response.status, data: await response.json().catch(() => ({})) };
      }, { id: Number(orderIds.suiviEnCours), statut });
      assert.equal(update.status, 200, `le personnel fait avancer le statut vers ${statut}`);
      await staffPage.locator(`.ong[data-statut="${statut}"]`).click();
      await delayedOrderCard.locator('[data-avance]').filter({ hasText: nextAction }).waitFor();
      await fallbackPage.locator('[data-refresh-tracking]').click();
      await fallbackPage.locator('#tuc-tracking-content .tuc-tracking-state').filter({ hasText: label }).waitFor();
    }
    const trackingText = await fallbackPage.locator('#tuc-tracking-content').innerText();
    assert(!trackingText.includes(fallbackPhone) && !trackingText.includes('Localisation'), 'le suivi public ne révèle ni téléphone ni adresse/localisation');

    assert.deepEqual(errors, [], `erreurs JavaScript navigateur: ${errors.join('\n')}`);
    console.log('PASS scénarios 1–5: inscription, panier repris, modes client et partage livraison côté caisse');
    console.log('PASS scénarios 6–7: compte client retrouvé, GPS refusé et repli vers le retrait');
    console.log('PASS scénario 8: perte de réponse réseau, rechargement et reprise idempotente sans doublon');
    console.log('PASS scénario 9: prix actualisé avec reconfirmation et plat épuisé retiré du panier');
    console.log('PASS scénario 10: caisse fait progresser la commande, suivi client actualisé sans données privées');
  } catch (error) {
    const diagnostics = await activePage.evaluate(() => ({
      path: location.pathname,
      loggedIn: window.TUCClient?.loggedIn ?? null,
      accountStatus: document.querySelector('#tuc-account-status')?.textContent || '',
      resumeHref: document.querySelector('#tuc-wa-resume')?.getAttribute('href') || '',
    })).catch(() => null);
    if (diagnostics?.resumeHref) {
      try { diagnostics.resumeHref = new URL(diagnostics.resumeHref).pathname; } catch (_) { diagnostics.resumeHref = '[invalid]'; }
    }
    console.error(JSON.stringify({ checkpoint, error: error.message, errors, orderIds, diagnostics }, null, 2));
    await activePage.screenshot({ path: '/tmp/tuc-checkout-scenarios-failure.png', fullPage: true }).catch(() => {});
    throw error;
  } finally {
    await context.close().catch(() => {});
    await browser.close().catch(() => {});
  }
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
