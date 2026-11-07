const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const fixtureDir = process.env.TUC_NVDA_FIXTURE_DIR || path.join(process.cwd(), 'nvda-fixture');
const transcriptPath = process.env.TUC_NVDA_TRANSCRIPT || path.join(process.cwd(), 'nvda-speech-viewer.txt');
const port = Number(process.env.TUC_NVDA_TEST_PORT || 4173);
const mimeTypes = { '.css': 'text/css', '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8' };

async function startFixtureServer() {
  const server = http.createServer((request, response) => {
    const pathname = new URL(request.url, `http://127.0.0.1:${port}`).pathname;
    const name = pathname === '/' ? 'index.html' : path.basename(pathname);
    if (!['index.html', 'restaurant-onboarding.css', 'restaurant-onboarding.js'].includes(name)) {
      response.writeHead(404).end();
      return;
    }
    const file = path.join(fixtureDir, name);
    if (!fs.existsSync(file)) {
      response.writeHead(404).end();
      return;
    }
    response.writeHead(200, { 'Content-Type': mimeTypes[path.extname(file)] || 'application/octet-stream' });
    fs.createReadStream(file).pipe(response);
  });
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, '127.0.0.1', resolve);
  });
  return server;
}

function readSpeechViewer() {
  const python = process.env.PYTHON || 'python';
  const script = path.join(__dirname, 'read-nvda-speech-viewer.py');
  const output = execFileSync(python, [script, '25'], { encoding: 'utf8', timeout: 30000 });
  const parsed = JSON.parse(output.trim());
  return parsed;
}

function assertSpoken(transcript, text, context) {
  assert(transcript.toLocaleLowerCase().includes(text.toLocaleLowerCase()), `NVDA Speech Viewer did not contain ${JSON.stringify(text)} (${context}). Transcript:\n${transcript}`);
}

async function main() {
  assert(fs.existsSync(path.join(fixtureDir, 'index.html')), `fixture missing: ${fixtureDir}`);
  const server = await startFixtureServer();
  const browser = await chromium.launch({ headless: false });
  try {
    const context = await browser.newContext({ viewport: { width: 1000, height: 800 } });
    const page = await context.newPage();
    await page.route('**/wp-json/tuc/v1/restaurant-applications', (route) => route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        reference: 'NVDA123456',
        code: 'NVDA1234',
        poll: 'nvda-test-poll-token',
        wa_url: 'https://wa.me/212600000095?text=NVDA%20test',
        message: 'Demande enregistrée. Envoyez le message WhatsApp prérempli pour que notre équipe puisse vous retrouver.',
      }),
    }));
    await page.goto(`http://127.0.0.1:${port}/`, { waitUntil: 'load' });
    await page.bringToFront();
    await page.waitForTimeout(1200);

    // Use actual Tab/Shift+Tab/Enter so NVDA observes real browser focus transitions.
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'tuc-onboarding-name');
    await page.waitForTimeout(500);
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'tuc-onboarding-menu-photo');
    await page.waitForTimeout(500);
    await page.keyboard.press('Tab');
    assert(await page.locator('.tuc-onboarding-submit[type="submit"]').evaluate((button) => button === document.activeElement));
    await page.waitForTimeout(500);
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Shift+Tab');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'tuc-onboarding-name');
    await page.keyboard.type('Test Restaurant', { delay: 50 });
    const arabicName = 'مطعم الاختبار';
    await page.keyboard.press('Control+A');
    await page.keyboard.type(arabicName, { delay: 80 });
    assert.equal(await page.locator('#tuc-onboarding-name').getAttribute('lang'), 'ar');
    await page.locator('#tuc-onboarding-menu-photo').setInputFiles({
      name: 'nvda-menu.png', mimeType: 'image/png',
      buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5Z8AAAAASUVORK5CYII=', 'base64'),
    });
    await page.locator('#tuc-onboarding-name').focus();
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    const responsePromise = page.waitForResponse((response) => response.url().includes('/wp-json/tuc/v1/restaurant-applications'));
    await page.keyboard.press('Enter');
    const response = await responsePromise;
    assert.equal(response.status(), 201);
    await page.waitForFunction(() => document.activeElement?.id === 'tuc-onboarding-wa-link');
    await page.waitForTimeout(1200);

    const speechViewer = readSpeechViewer();
    const transcript = speechViewer.text || '';
    fs.writeFileSync(transcriptPath, transcript || JSON.stringify(speechViewer, null, 2), 'utf8');
    for (const phrase of [
      'Nom du restaurant',
      'En français ou en arabe',
      'Photo du menu',
      'JPEG, PNG ou WebP',
      'Demander l’accompagnement',
      'Demande enregistrée',
      'Continuer dans WhatsApp',
      'nouvel onglet',
    ]) {
      assertSpoken(transcript, phrase, `NVDA reading the live onboarding form; UIA diagnostics: ${JSON.stringify(speechViewer.diagnostics)}`);
    }
    console.log(`PASS NVDA Speech Viewer: focus sequence, form labels/help, live status and WhatsApp link announcement. Transcript saved to ${transcriptPath}`);
    await context.close();
  } finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }
}

main().catch((error) => {
  const detail = error.stack || error.message || String(error);
  if (process.env.TUC_NVDA_FAILURE) fs.writeFileSync(process.env.TUC_NVDA_FAILURE, detail, 'utf8');
  console.error(detail);
  process.exitCode = 1;
});
