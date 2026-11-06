const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');
const { chromium } = require('playwright');

const baseUrl = process.env.TUC_MOBILE_E2E_URL || 'http://127.0.0.1:8080';
const username = process.env.TUC_MOBILE_E2E_USER || 'tuc-mobile-e2e';
const serverUsername = process.env.TUC_MOBILE_E2E_SERVER_USER || 'tuc-mobile-server';
const cashierUsername = process.env.TUC_MOBILE_E2E_CASHIER_USER || 'tuc-mobile-cashier';
const password = process.env.TUC_MOBILE_E2E_PASSWORD || '';
const artifacts = process.env.TUC_MOBILE_E2E_ARTIFACTS || '/tmp/tuc-mobile-e2e-artifacts';
const pageErrors = [];
let checkpoint = 'initialisation';

function crc32(buffer) {
  let crc = 0xffffffff;
  for (const byte of buffer) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
  }
  return (crc ^ 0xffffffff) >>> 0;
}
function pngChunk(type, data) {
  const name = Buffer.from(type);
  const length = Buffer.alloc(4); length.writeUInt32BE(data.length);
  const checksum = Buffer.alloc(4); checksum.writeUInt32BE(crc32(Buffer.concat([name, data])));
  return Buffer.concat([length, name, data, checksum]);
}
function tinyPng() {
  const header = Buffer.alloc(13);
  header.writeUInt32BE(1, 0); header.writeUInt32BE(1, 4);
  header[8] = 8; header[9] = 6;
  return Buffer.concat([
    Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
    pngChunk('IHDR', header),
    pngChunk('IDAT', zlib.deflateSync(Buffer.from([0, 37, 112, 63, 255]))),
    pngChunk('IEND', Buffer.alloc(0)),
  ]);
}
function normalizedText(value) { return String(value).replace(/[\s\u00a0\u202f]/g, ''); }
async function screenshot(page, name) {
  await fs.promises.mkdir(artifacts, { recursive: true });
  await page.screenshot({ path: path.join(artifacts, name), fullPage: true, animations: 'disabled' });
}

async function main() {
  assert(password.length >= 24, 'le compte WordPress E2E doit avoir un mot de passe éphémère');
  await fs.promises.mkdir(artifacts, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 1,
    isMobile: true,
    hasTouch: true,
  });
  const page = await context.newPage();
  page.on('pageerror', (error) => pageErrors.push(error.stack || error.message));

  try {
    checkpoint = 'connexion restaurateur';
    await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await page.locator('#user_login').fill(username);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
    await page.waitForLoadState('domcontentloaded');
    assert(!page.url().includes('wp-login.php'), 'connexion au compte restaurateur de test');

    const proResponse = await page.goto(`${baseUrl}/espace-pro/`, { waitUntil: 'networkidle' });
    assert(proResponse && proResponse.status() === 200, 'page Espace Pro servie par WordPress Docker');
    await page.locator('#tuc-menu-list .tuc-menu-row').first().waitFor();
    await page.getByRole('button', { name: 'Menu', exact: true }).waitFor();
    const proOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    assert.equal(proOverflow, false, 'Espace Pro mobile ne déborde pas horizontalement à 390 px');
    await screenshot(page, 'espace-pro-menu-mobile.png');

    checkpoint = 'affichage Espace Pro mobile';
    // Parcours réel du formulaire Menu: options, prix/promo, photo et publication.
    await page.locator('#tuc-new-item').click();
    await page.locator('#tuc-item-name').fill('Nouveau plat mobile E2E');
    await page.locator('#tuc-item-description').fill('Créé depuis le navigateur mobile de test.');
    await page.locator('#tuc-item-price').fill('18.50');
    await page.locator('#tuc-item-promo').fill('10');
    await page.locator('#tuc-add-group').click();
    const group = page.locator('#tuc-option-groups .tuc-option-group').first();
    await group.locator('[data-group-name]').fill('Accompagnement');
    await group.locator('.tuc-add-choice').click();
    const choice = group.locator('.tuc-choice-row').first();
    await choice.locator('[data-choice-name]').fill('Salade');
    await choice.locator('[data-choice-price]').fill('2');
    await page.locator('#tuc-item-photo').setInputFiles({
      name: 'e2e-mobile.png', mimeType: 'image/png', buffer: tinyPng(),
    });
    await page.locator('#tuc-photo-preview:not([hidden])').waitFor();
    await page.locator('#tuc-menu-editor [data-save-status="publish"]').click();
    const createdRow = page.locator('#tuc-menu-list .tuc-menu-row').filter({ hasText: 'Nouveau plat mobile E2E' });
    await createdRow.waitFor();
    await createdRow.locator('img.tuc-menu-thumb').waitFor();
    assert((await createdRow.innerText()).includes('Indisponible') === false, 'nouveau plat publié et disponible');

    checkpoint = 'création du plat, options et photo';
    // Modification rapide de disponibilité depuis le même écran tactile.
    await createdRow.locator('[data-edit]').click();
    await page.locator('label.tuc-toggle').filter({ has: page.locator('#tuc-item-available') }).click();
    assert.equal(await page.locator('#tuc-item-available').isChecked(), false, 'le plat est marqué indisponible');
    await page.locator('#tuc-menu-editor [data-save-status="publish"]').click();
    await page.locator('#tuc-menu-list .tuc-menu-row').filter({ hasText: 'Nouveau plat mobile E2E' }).getByText(/Indisponible/).waitFor();

    checkpoint = 'modification de disponibilité';
    // Réglages de boutique: mise en pause et reprise contrôlées côté serveur.
    await page.getByRole('button', { name: 'Boutique', exact: true }).click();
    await page.locator('#tuc-store-settings').waitFor();
    const storeToggle = page.locator('label.tuc-toggle').filter({ has: page.locator('#tuc-store-open') });
    await storeToggle.click();
    assert.equal(await page.locator('#tuc-store-open').isChecked(), false, 'la boutique est mise en pause');
    await page.locator('#tuc-store-settings button[type="submit"]').click();
    await page.locator('#tuc-open-dot.is-closed').waitFor();
    await storeToggle.click();
    assert.equal(await page.locator('#tuc-store-open').isChecked(), true, 'la boutique est rouverte');
    await page.locator('#tuc-store-settings button[type="submit"]').click();
    await page.locator('#tuc-open-dot:not(.is-closed)').waitFor();

    checkpoint = 'réglages boutique ouvert/fermé';
    // Commande réelle de test créée côté serveur, puis traitée dans l’Espace Pro.
    await page.getByRole('button', { name: /Commandes/ }).click();
    const orderCard = page.locator('#tuc-orders-list .tuc-order-card').first();
    await orderCard.waitFor();
    assert(!(await page.locator('#tuc-orders-list').innerText()).includes('+212600000099'), 'l’interface Pro ne montre pas le téléphone du client');
    await orderCard.locator('[data-order-status="acceptee"]').click();
    await orderCard.getByText('Acceptée').waitFor();
    await screenshot(page, 'espace-pro-commandes-mobile.png');

    checkpoint = 'connexion du serveur et activation du bip + vibration';
    const cashierContext = await browser.newContext({
      viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true,
    });
    await cashierContext.grantPermissions(['notifications'], { origin: new URL(baseUrl).origin });
    const cashierPage = await cashierContext.newPage();
    cashierPage.on('pageerror', (error) => pageErrors.push(`caisse serveur: ${error.stack || error.message}`));
    await cashierPage.addInitScript(() => {
      if (!window.OscillatorNode) return;
      const start = window.OscillatorNode.prototype.start;
      window.OscillatorNode.prototype.start = function (...args) {
        window.__tucToneStarts = (window.__tucToneStarts || 0) + 1;
        return start.apply(this, args);
      };
    });
    await cashierPage.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await cashierPage.locator('#user_login').fill(serverUsername);
    await cashierPage.locator('#user_pass').fill(password);
    await cashierPage.locator('#wp-submit').click();
    await cashierPage.waitForLoadState('domcontentloaded');
    assert(!cashierPage.url().includes('wp-login.php'), 'connexion au compte synthétique du serveur');
    const cashierResponse = await cashierPage.goto(`${baseUrl}/caisse/`, { waitUntil: 'networkidle' });
    assert(cashierResponse && cashierResponse.status() === 200, 'le serveur accède à la caisse mobile de son restaurant');
    await cashierPage.locator('#btn-son:not([hidden])').click();
    await cashierPage.locator('#btn-son[aria-pressed="true"]').waitFor();
    assert((await cashierPage.evaluate(() => window.__tucToneStarts || 0)) >= 3, 'le serveur active le bip et la vibration avec un geste explicite');
    await cashierPage.locator('#etat.on').waitFor();
    await cashierPage.locator('#liste:not([hidden]), #vide:not([hidden])').waitFor();
    await screenshot(cashierPage, 'caisse-restaurateur-mobile.png');

    checkpoint = 'lecture du QR de la table 7';
    const qrResponse = await cashierPage.request.get(`${baseUrl}/wp-json/tuc/v1/qr/tuc-mobile-e2e`);
    assert.equal(qrResponse.status(), 200, 'la planche QR du restaurant est disponible');
    const qrSheet = await qrResponse.json();
    const tableSevenQr = (qrSheet.qr || []).find((entry) => entry.type === 'table' && Number(entry.table) === 7);
    assert(tableSevenQr && new URL(tableSevenQr.url).searchParams.get('t') === '7', 'le QR Table 7 ouvre la bonne URL');

    // On simule le scan du QR en ouvrant son URL réelle, avec le menu et le contexte table.
    const clientResponse = await page.goto(tableSevenQr.url, { waitUntil: 'networkidle' });
    assert(clientResponse && clientResponse.status() === 200, 'la fiche restaurant du QR est servie par WordPress Docker');
    assert.equal(await page.locator('#tuc-client-menu').getAttribute('data-table'), '7', 'la page client conserve la table 7 issue du QR');
    await page.getByText('Vous êtes à la table 7', { exact: false }).waitFor();
    const initialCard = page.locator('.tuc-client-card').filter({ hasText: 'Plat initial E2E' });
    await initialCard.locator('[data-add]').click();
    const formatGroup = page.locator('#tuc-customize-groups [data-group-id="format"]');
    await formatGroup.locator('input[value="large"]').check();
    const conditionalGroup = page.locator('#tuc-customize-groups [data-group-id="sauce"]');
    await conditionalGroup.waitFor();
    assert.equal(await conditionalGroup.isVisible(), true, 'le groupe conditionnel apparaît après le choix Grand');
    await conditionalGroup.locator('input[value="bbq"]').check();
    await page.locator('#tuc-customize-form button[type="submit"]').click();
    await page.locator('#tuc-open-cart').click();
    const checkoutTotal = normalizedText(await page.locator('#tuc-checkout-total').innerText());
    assert(checkoutTotal.includes('32,00MAD') || checkoutTotal.includes('32.00MAD'), `total attendu 32 MAD à table (sans frais de livraison), obtenu: ${checkoutTotal}`);
    await page.getByText('Commande à la table 7', { exact: false }).waitFor();
    assert.equal(await page.locator('#tuc-order-mode').isVisible(), false, 'le mode table est fixé par le QR et ne peut pas être remplacé');
    await page.locator('#tuc-customer-phone').fill('+212600000010');
    await page.locator('#tuc-submit-order').click();
    await page.locator('#tuc-order-success').waitFor({ state: 'visible' });
    const submittedReference = await page.locator('#tuc-order-success strong').innerText();

    // Le restaurateur revient à sa caisse : le rafraîchissement déclenche le signal sonore.
    await cashierPage.bringToFront();
    await cashierPage.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    const tableOrder = cashierPage.locator('#liste .carte').filter({ hasText: 'Table 7' });
    await tableOrder.waitFor({ timeout: 30000 });
    const tableOrderText = await tableOrder.innerText();
    assert(tableOrderText.includes('Plat initial E2E'), 'la caisse reçoit le plat commandé à la table 7');
    assert(tableOrderText.includes('32'), 'la caisse affiche le montant de la commande à table');
    assert(!tableOrderText.includes('+212600000010'), 'la caisse n’expose pas le numéro de téléphone du client');
    assert.equal(await tableOrder.getAttribute('data-id'), submittedReference.replace('#', ''), 'la commande de caisse correspond à celle du client');
    await cashierPage.locator('#son-status').getByText('Alerte sonore jouée', { exact: false }).waitFor();
    assert((await cashierPage.evaluate(() => window.__tucToneStarts || 0)) >= 6, 'la nouvelle commande déclenche réellement les oscillateurs du signal sonore');
    await screenshot(cashierPage, 'caisse-serveur-nouvelle-commande-table-7.png');

    checkpoint = 'notification silencieuse du caissier et accès au même restaurant';
    const backupContext = await browser.newContext({
      viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true,
    });
    const backupPage = await backupContext.newPage();
    backupPage.on('pageerror', (error) => pageErrors.push(`caisse caissier: ${error.stack || error.message}`));
    await backupPage.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await backupPage.locator('#user_login').fill(cashierUsername);
    await backupPage.locator('#user_pass').fill(password);
    await backupPage.locator('#wp-submit').click();
    await backupPage.waitForLoadState('domcontentloaded');
    assert(!backupPage.url().includes('wp-login.php'), 'connexion au compte synthétique du caissier');
    const backupResponse = await backupPage.goto(`${baseUrl}/caisse/`, { waitUntil: 'networkidle' });
    assert(backupResponse && backupResponse.status() === 200, 'le caissier accède à la caisse du même restaurant');
    const cashierOrder = backupPage.locator('#liste .carte').filter({ hasText: 'Table 7' });
    await cashierOrder.waitFor();
    assert.equal(await backupPage.locator('#btn-son').isVisible(), false, 'le caissier voit la commande sans commande de bip/vibreur');
    assert((await cashierOrder.innerText()).includes('Plat initial E2E'), 'le caissier reçoit aussi la commande Table 7');
    await screenshot(backupPage, 'caisse-caissier-commande-table-7.png');
    await cashierPage.bringToFront();

    await page.locator('#tuc-order-success').getByRole('button', { name: 'Suivre ma commande' }).click();
    const tracking = page.locator('#tuc-tracking-dialog');
    await tracking.waitFor({ state: 'visible' });
    await tracking.locator('.tuc-tracking-state').getByText('Reçue').waitFor();
    assert((await tracking.innerText()).includes('Plat initial E2E') === false, 'le suivi client ne révèle pas le contenu de la commande');

    checkpoint = 'traitement complet de la commande Table 7 dans la caisse';
    const etapesCaisse = [
      ['nouvelle', 'acceptee'],
      ['acceptee', 'preparation'],
      ['preparation', 'prete'],
      ['prete', 'servie'],
    ];
    for (const [onglet, statutSuivant] of etapesCaisse) {
      await cashierPage.locator(`.ong[data-statut="${onglet}"]`).click();
      const carteCommande = cashierPage.locator('#liste .carte').filter({ hasText: 'Table 7' });
      await carteCommande.waitFor();
      const action = carteCommande.locator(`[data-avance][data-vers="${statutSuivant}"]`);
      const statusResponse = cashierPage.waitForResponse((response) =>
        response.url().includes('/caisse/statut') && response.request().method() === 'POST');
      await action.click();
      assert.equal((await statusResponse).status(), 200, `la caisse enregistre le statut ${statutSuivant}`);
      await action.waitFor({ state: 'detached' });
    }
    await cashierPage.locator('.ong[data-statut="toutes"]').click();
    const servedOrder = cashierPage.locator('#liste .carte').filter({ hasText: 'Table 7' });
    await servedOrder.waitFor();
    await servedOrder.getByText('Terminée · à encaisser').waitFor();
    await screenshot(cashierPage, 'caisse-commande-table-7-servie.png');
    await tracking.locator('[data-refresh-tracking]').click();
    await tracking.locator('.tuc-tracking-state').getByText('Servie').waitFor();
    await screenshot(page, 'suivi-client-table-7-mobile.png');

    // Le lien de suivi reste enregistré sur l'appareil après rechargement.
    await tracking.locator('[data-close-tracking]').click();
    await page.reload({ waitUntil: 'networkidle' });
    const savedTracking = page.locator('#tuc-open-saved-tracking');
    await savedTracking.waitFor({ state: 'visible' });
    await savedTracking.click();
    await page.locator('#tuc-tracking-dialog .tuc-tracking-state').getByText('Servie').waitFor();

    assert.deepEqual(pageErrors, [], `erreurs JavaScript navigateur: ${pageErrors.join('; ')}`);
    console.log('PASS: QR réel de la table 7 → menu → options → panier → commande → suivi client');
    console.log('PASS: caisse restaurateur → nouvelle commande Table 7 → signal sonore généré → acceptée → cuisine → prête → servie');
  } catch (error) {
    const diagnostic = {
      checkpoint,
      error: error.message,
      pagePath: (() => { try { return new URL(page.url()).pathname; } catch { return ''; } })(),
      pageTitle: await page.title().catch(() => ''),
      pageErrors,
    };
    console.error(`MOBILE_E2E_FAILURE ${JSON.stringify(diagnostic)}`);
    await screenshot(page, 'mobile-e2e-failure.png').catch(() => {});
    throw error;
  } finally {
    await context.close();
    await browser.close();
  }
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
