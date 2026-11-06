const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');
const { chromium } = require('playwright');

const baseUrl = process.env.TUC_MOBILE_E2E_URL || 'http://127.0.0.1:8080';
const username = process.env.TUC_MOBILE_E2E_USER || 'tuc-mobile-e2e';
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
  page.on('pageerror', (error) => pageErrors.push(error.message));

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
    await page.locator('#tuc-item-available').uncheck();
    await page.locator('#tuc-menu-editor [data-save-status="publish"]').click();
    await page.locator('#tuc-menu-list .tuc-menu-row').filter({ hasText: 'Nouveau plat mobile E2E' }).getByText(/Indisponible/).waitFor();

    checkpoint = 'modification de disponibilité';
    // Réglages de boutique: mise en pause et reprise contrôlées côté serveur.
    await page.getByRole('button', { name: 'Boutique', exact: true }).click();
    await page.locator('#tuc-store-settings').waitFor();
    await page.locator('#tuc-store-open').uncheck();
    await page.locator('#tuc-store-settings button[type="submit"]').click();
    await page.locator('#tuc-open-dot.is-closed').waitFor();
    await page.locator('#tuc-store-open').check();
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

    checkpoint = 'traitement de la commande dans Espace Pro';
    // Menu public -> option conditionnelle -> panier -> commande -> suivi tokenisé.
    const clientResponse = await page.goto(`${baseUrl}/tuc-mobile-e2e/`, { waitUntil: 'networkidle' });
    assert(clientResponse && clientResponse.status() === 200, 'fiche restaurant publique servie par WordPress Docker');
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
    assert(checkoutTotal.includes('37,00MAD') || checkoutTotal.includes('37.00MAD'), `total attendu 37 MAD (options et frais), obtenu: ${checkoutTotal}`);
    await page.locator('#tuc-customer-phone').fill('+212600000010');
    await page.locator('#tuc-submit-order').click();
    await page.locator('#tuc-order-success').waitFor({ state: 'visible' });
    await page.locator('#tuc-order-success').getByRole('button', { name: 'Suivre ma commande' }).click();
    const tracking = page.locator('#tuc-tracking-dialog');
    await tracking.waitFor({ state: 'visible' });
    await tracking.locator('.tuc-tracking-state').getByText('Reçue').waitFor();
    assert((await tracking.innerText()).includes('Plat initial E2E') === false, 'le suivi client ne révèle pas le contenu de la commande');
    await screenshot(page, 'suivi-client-mobile.png');

    checkpoint = 'commande client, panier et suivi';
    // Le jeton client est conservé uniquement sur cet appareil et reste disponible après rechargement.
    await tracking.locator('[data-close-tracking]').click();
    await page.reload({ waitUntil: 'networkidle' });
    const savedTracking = page.locator('#tuc-open-saved-tracking');
    await savedTracking.waitFor({ state: 'visible' });
    await savedTracking.click();
    await page.locator('#tuc-tracking-dialog .tuc-tracking-state').getByText('Reçue').waitFor();

    assert.deepEqual(pageErrors, [], `erreurs JavaScript navigateur: ${pageErrors.join('; ')}`);
    console.log('PASS: WordPress Docker + navigateur Chromium mobile 390×844');
    console.log('PASS: Espace Pro Menu/Boutique/Commandes, option conditionnelle, photo, publication, disponibilité, panier, commande et suivi');
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
