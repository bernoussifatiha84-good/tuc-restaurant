const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { chromium } = require('playwright');
const axeCorePath = require.resolve('axe-core');

const baseUrl = (process.env.TUC_MOBILE_E2E_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const n8nUrl = (process.env.TUC_N8N_URL || 'http://127.0.0.1:5678').replace(/\/$/, '');
const inboundSecret = process.env.TUC_N8N_INBOUND_SECRET || '';
const waSecret = process.env.TUC_WA_SECRET || '';
const adminUser = process.env.TUC_SMOKE_ADMIN_USER || 'tuc-smoke-admin';
const adminPassword = process.env.TUC_SMOKE_ADMIN_PASSWORD || '';
const restaurantName = 'مطعم الاختبار من الهاتف';

async function sendApplicationMessageThroughN8n(code, phone) {
  const timestamp = String(Math.floor(Date.now() / 1000));
  const eventId = `restaurant-onboarding-${crypto.randomUUID()}`;
  const normalizedPhone = phone.replace(/\D/g, '');
  const canonical = [timestamp, eventId, code.toUpperCase(), normalizedPhone].join('\n');
  const signature = crypto.createHmac('sha256', inboundSecret).update(canonical).digest('hex');
  const body = JSON.stringify({ event_id: eventId, code, tel: phone });
  const headers = { 'Content-Type': 'application/json', 'X-TUC-Timestamp': timestamp, 'X-TUC-Signature': signature };
  const paths = [
    '/webhook/7b75d76f-f3a6-4579-bfc0-8a6e9561fd74/tuc-whatsapp-inbound-smoke',
    '/webhook/tuc-whatsapp-inbound-smoke',
  ];
  let response;
  for (const path of paths) {
    response = await fetch(`${n8nUrl}${path}`, { method: 'POST', headers, body });
    if (response.status !== 404) break;
  }
  const data = await response.json().catch(() => ({}));
  assert.equal(response.status, 200, `n8n traite la demande WhatsApp simulée : ${JSON.stringify(data)}`);
  assert.equal(data.ok, true);
  assert.equal(data.type, 'restaurant_application');
  assert.match(data.reponse || '', /Notre équipe TUC va vous accompagner/);
  assert.equal(Object.hasOwn(data, 'magie'), false, 'le message d’entrée ne crée pas encore de compte ni de lien magique');
  assert.equal(Object.hasOwn(data, 'login'), false, 'le message d’entrée ne crée pas encore de compte utilisateur');
  return data;
}

async function assertAccessibleComponent(page, state) {
  await page.addScriptTag({ path: axeCorePath });
  const result = await page.evaluate(async () => {
    const root = document.querySelector('.tuc-onboarding-card');
    return window.axe.run(root, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
    });
  });
  const violations = result.violations.map((item) => `${item.id}: ${item.nodes.map((node) => node.target.join(' ')).join(', ')}`);
  assert.deepEqual(violations, [], `axe-core WCAG checks pass (${state}): ${violations.join('; ')}`);
}

async function main() {
  assert(inboundSecret.length >= 32 && waSecret.length >= 32, 'secrets HMAC éphémères configurés pour le test n8n');
  assert(adminPassword.length >= 24, 'identifiants éphémères de l’administration configurés');
  const browser = await chromium.launch({ headless: true });
  const errors = [];
  try {
    const mobileViewport = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 3 };
    const applicantContext = await browser.newContext(mobileViewport);
    const applicant = await applicantContext.newPage();
    applicant.on('pageerror', (error) => errors.push(error.stack || error.message));
    await applicant.goto(`${baseUrl}/devenir-restaurateur/`, { waitUntil: 'networkidle' });
    const form = applicant.locator('#tuc-restaurant-onboarding-form');
    await form.waitFor({ state: 'visible' });
    assert.equal(await form.locator('input').count(), 2, 'le formulaire public ne demande que le nom et la photo du menu');
    assert.equal(await applicant.locator('#tuc-onboarding-name').getAttribute('dir'), 'auto', 'le nom s’affiche correctement en français ou en arabe');
    assert.equal(await applicant.locator('#tuc-onboarding-name').getAttribute('lang'), 'fr');
    await assertAccessibleComponent(applicant, 'formulaire initial');
    await applicant.locator('#tuc-onboarding-name').fill(restaurantName);
    assert.equal(await applicant.locator('#tuc-onboarding-name').getAttribute('lang'), 'ar', 'le champ nom marque la langue arabe pour les technologies d’assistance');
    await applicant.locator('#tuc-onboarding-menu-photo').setInputFiles({
      name: 'menu-test.png',
      mimeType: 'image/png',
      buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5Z8AAAAASUVORK5CYII=', 'base64'),
    });
    await applicant.locator('#tuc-onboarding-name').focus();
    await applicant.keyboard.press('Tab');
    assert.equal(await applicant.evaluate(() => document.activeElement.id), 'tuc-onboarding-menu-photo', 'le champ photo suit le nom dans l’ordre clavier');
    await applicant.keyboard.press('Tab');
    assert(await applicant.locator('.tuc-onboarding-submit[type="submit"]').evaluate((button) => button === document.activeElement), 'le bouton envoyer est accessible au clavier');
    const submitResponse = applicant.waitForResponse((response) => response.url().includes('/wp-json/tuc/v1/restaurant-applications'));
    await applicant.keyboard.press('Enter');
    const applicationResponse = await submitResponse;
    const application = await applicationResponse.json();
    assert.equal(applicationResponse.status(), 201, `la demande est créée : ${JSON.stringify(application)}`);
    await applicant.waitForFunction(() => document.activeElement?.id === 'tuc-onboarding-wa-link');
    assert.equal(await applicant.evaluate(() => document.activeElement.id), 'tuc-onboarding-wa-link', 'après envoi, le focus est placé sur l’étape suivante');
    assert.match(await applicant.locator('#tuc-onboarding-wa-link').getAttribute('aria-label'), /nouvel onglet/);
    await assertAccessibleComponent(applicant, 'confirmation et lien WhatsApp');
    assert.match(application.reference, /^[A-Z0-9]{10}$/);
    assert.equal(new URL(application.wa_url).hostname, 'wa.me');
    const waMessage = new URL(application.wa_url).searchParams.get('text') || '';
    assert.match(waMessage, /DEMANDE RESTAURANT ref:[A-Z0-9]{8}/);
    assert(!waMessage.includes(restaurantName), 'les informations du restaurant ne sont pas exposées dans le message de contact');

    const code = waMessage.match(/ref:([A-Z0-9]{8})/)?.[1];
    assert(code, 'le message WhatsApp contient la référence temporaire');
    await applicantContext.route('https://wa.me/**', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<title>WhatsApp simulé</title>' }));
    const popupPromise = applicant.waitForEvent('popup');
    await applicant.locator('#tuc-onboarding-wa-link').click();
    const whatsappTab = await popupPromise;
    await whatsappTab.waitForLoadState('domcontentloaded').catch(() => {});
    assert.equal(new URL(whatsappTab.url()).hostname, 'wa.me', 'le CTA ouvre WhatsApp sans envoyer le message automatiquement');
    await whatsappTab.close();

    const inboundPhone = '+212600000095';
    const inbound = await sendApplicationMessageThroughN8n(code, inboundPhone);
    assert(inbound.reponse.includes('accompagner'), 'la réponse n8n confirme l’accompagnement, sans créer un compte trop tôt');
    await applicant.locator('#tuc-onboarding-wa-confirmed').waitFor({ state: 'visible', timeout: 20000 });

    const adminContext = await browser.newContext(mobileViewport);
    const admin = await adminContext.newPage();
    admin.on('pageerror', (error) => errors.push(error.stack || error.message));
    await admin.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await admin.locator('#user_login').fill(adminUser);
    await admin.locator('#user_pass').fill(adminPassword);
    await admin.locator('#wp-submit').click();
    await admin.waitForLoadState('domcontentloaded');
    assert(!admin.url().includes('wp-login.php'), 'l’équipe se connecte à WordPress depuis un viewport mobile');
    await admin.goto(`${baseUrl}/wp-admin/edit.php?post_type=tuc_resto&page=tuc-onboarding-restaurants`, { waitUntil: 'domcontentloaded' });
    const applicationCard = admin.locator('.tuc-onboarding-admin-card').filter({ hasText: restaurantName });
    await applicationCard.waitFor({ state: 'visible' });
    assert.equal(await applicationCard.locator('img').count(), 1, 'l’équipe voit la photo du menu reçue');
    assert.equal(await applicationCard.locator('input[name="phone"]').inputValue(), '212600000095', 'le numéro WhatsApp vérifié est rattaché à la demande');
    const provisionResponse = admin.waitForResponse((response) => response.url().includes('admin-post.php') && response.request().method() === 'POST');
    await applicationCard.locator('button[type="submit"]').click();
    await provisionResponse;
    await admin.waitForLoadState('domcontentloaded');
    await admin.locator('.notice-success').filter({ hasText: 'Compte restaurateur créé' }).waitFor({ state: 'visible' });
    const accessLink = admin.locator('.notice-success a[href^="https://wa.me/"]');
    const outgoingWa = new URL(await accessLink.getAttribute('href'));
    assert.equal(outgoingWa.hostname, 'wa.me');
    assert.equal(outgoingWa.pathname, '/212600000095', 'l’accès est préparé pour le numéro WhatsApp vérifié');
    const accessMessage = outgoingWa.searchParams.get('text') || '';
    assert(accessMessage.includes(restaurantName));
    const firstMagicUrl = accessMessage.match(/https?:\/\/[^\s]+/)?.[0];
    assert(firstMagicUrl, 'le message prérempli contient le lien magique personnel');
    const parsedMagic = new URL(firstMagicUrl);
    assert(parsedMagic.searchParams.has('tuc_magie'));
    assert(parsedMagic.pathname.endsWith('/connexion/'));
    assert.match(accessMessage, /15 minutes/);

    const resendResponse = admin.waitForResponse((response) => response.url().includes('admin-post.php') && response.request().method() === 'POST');
    await applicationCard.getByRole('button', { name: 'Préparer un nouveau lien WhatsApp' }).click();
    await resendResponse;
    await admin.waitForLoadState('domcontentloaded');
    await admin.locator('.notice-success').filter({ hasText: 'Nouveau lien préparé' }).waitFor({ state: 'visible' });
    const resentWa = new URL(await admin.locator('.notice-success a[href^="https://wa.me/"]').getAttribute('href'));
    const resentMessage = resentWa.searchParams.get('text') || '';
    const magicUrl = resentMessage.match(/https?:\/\/[^\s]+/)?.[0];
    assert(magicUrl && magicUrl !== firstMagicUrl, 'le renvoi prépare un nouveau jeton personnel');

    const invalidatedPage = await applicantContext.newPage();
    const invalidatedResponse = await invalidatedPage.goto(firstMagicUrl, { waitUntil: 'domcontentloaded' });
    assert.equal(invalidatedResponse.status(), 410, 'le lien remplacé est révoqué avant son expiration');
    await invalidatedPage.close();

    await applicant.goto(magicUrl, { waitUntil: 'domcontentloaded' });
    await applicant.waitForURL(/\/espace-pro\/?$/);
    await applicant.waitForFunction(() => Boolean(window.TUCPro));
    assert((await applicant.locator('#tuc-restaurant-name').innerText()).includes(restaurantName), 'le lien magique connecte le restaurateur à son propre espace depuis mobile');
    const draftVisibility = await applicant.evaluate(async () => {
      const response = await fetch(`${TUCPro.rest}restaurant`, { headers: { 'X-WP-Nonce': TUCPro.nonce } });
      const restaurant = await response.json();
      const publicMenuBase = TUCPro.rest.replace(/pro\/?$/, '');
      const publicMenu = await fetch(`${publicMenuBase}menu/${encodeURIComponent(restaurant.slug)}`);
      return { status: response.status, name: restaurant.name, publicMenuStatus: publicMenu.status };
    });
    assert.equal(draftVisibility.status, 200);
    assert.equal(draftVisibility.name, restaurantName);
    assert.equal(draftVisibility.publicMenuStatus, 404, 'la fiche brouillon est accessible au propriétaire mais reste masquée au public');
    const replayPage = await applicantContext.newPage();
    const replayResponse = await replayPage.goto(magicUrl, { waitUntil: 'domcontentloaded' });
    assert.equal(replayResponse.status(), 410, 'le lien magique consommé ne peut pas servir deux fois');
    await replayPage.close();
    assert(errors.length === 0, `aucune erreur JavaScript navigateur : ${errors.join('\n')}`);
    await adminContext.close();
    await applicantContext.close();
    console.log('PASS onboarding restaurateur mobile: formulaire 2 champs (nom arabe + photo), wa.me, webhook n8n, provisionnement équipe, accès WhatsApp par lien magique à usage unique et redirection Espace Pro.');
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});
