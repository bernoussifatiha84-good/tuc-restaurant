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

    assert.deepEqual(errors, [], `erreurs JavaScript navigateur: ${errors.join('\n')}`);
    console.log('PASS scénario 1: WhatsApp prérempli → compte via webhook signé → lien magique → panier repris → GPS livraison');
    console.log('PASS scénarios 2–5: client connecté → emporter, QR Table 7, livraison enregistrée et position actualisée');
    console.log('PASS scénario 5 côté restaurant: appel, WhatsApp, carte, partage natif du téléphone et de la position au livreur');
  } catch (error) {
    const diagnostics = await page.evaluate(() => ({
      path: location.pathname,
      loggedIn: window.TUCClient?.loggedIn ?? null,
      accountStatus: document.querySelector('#tuc-account-status')?.textContent || '',
      resumeHref: document.querySelector('#tuc-wa-resume')?.getAttribute('href') || '',
    })).catch(() => null);
    if (diagnostics?.resumeHref) {
      try { diagnostics.resumeHref = new URL(diagnostics.resumeHref).pathname; } catch (_) { diagnostics.resumeHref = '[invalid]'; }
    }
    console.error(JSON.stringify({ checkpoint, error: error.message, errors, orderIds, diagnostics }, null, 2));
    await page.screenshot({ path: '/tmp/tuc-checkout-scenarios-failure.png', fullPage: true }).catch(() => {});
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
