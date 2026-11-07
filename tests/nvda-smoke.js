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
  return JSON.parse(output.trim());
}

function focusScreenReaderBrowser() {
  const python = process.env.PYTHON || 'python';
  const script = path.join(__dirname, 'send-nvda-keys.py');
  execFileSync(python, [script, '--focus'], { encoding: 'utf8', timeout: 15000 });
}

function sendScreenReaderKeys(_page, keys) {
  const python = process.env.PYTHON || 'python';
  const script = path.join(__dirname, 'send-nvda-keys.py');
  execFileSync(python, [script, keys], { encoding: 'utf8', timeout: 15000 });
}

async function waitForActiveElement(page, predicate, description) {
  try {
    await page.waitForFunction(predicate, undefined, { timeout: 10000 });
  } catch (error) {
    const focusState = await page.evaluate(() => ({
      activeElement: document.activeElement?.outerHTML?.slice(0, 500) || null,
      hasFocus: document.hasFocus(),
      title: document.title,
      focusableElements: [...document.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])')]
        .map((element) => ({ id: element.id, tag: element.tagName, disabled: element.disabled, tabIndex: element.tabIndex })),
    }));
    throw new Error(`Timed out waiting for focus on ${description}: ${error.message}; state: ${JSON.stringify(focusState)}`);
  }
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

    // Keep the pointer clear of NVDA Speech Viewer and focus Chromium before real Windows keyboard input.
    focusScreenReaderBrowser();
    await page.bringToFront();
    await page.locator('#tuc-onboarding-name').focus();
    await waitForActiveElement(page, () => document.activeElement.id === 'tuc-onboarding-name', 'restaurant name field');
    await page.waitForTimeout(250);
    await sendScreenReaderKeys(page, '{TAB}');
    await waitForActiveElement(page, () => document.activeElement.id === 'tuc-onboarding-menu-photo', 'menu photo after first Tab');
    // Re-emit a genuine accessibility focus event so NVDA announces the file control, even in CDP-driven CI.
    await page.evaluate(() => document.activeElement.blur());
    await page.locator('#tuc-onboarding-menu-photo').focus();
    await page.waitForTimeout(800);
    await sendScreenReaderKeys(page, '{TAB}');
    await waitForActiveElement(page, () => document.activeElement.matches('.tuc-onboarding-submit[type="submit"]'), 'submit button after second Tab');
    await page.waitForTimeout(500);
    await sendScreenReaderKeys(page, '+{TAB}');
    await sendScreenReaderKeys(page, '+{TAB}');
    await waitForActiveElement(page, () => document.activeElement.id === 'tuc-onboarding-name', 'restaurant name after reverse Tabs');
    await sendScreenReaderKeys(page, 'Test Restaurant');
    const arabicName = 'مطعم الاختبار';
    await sendScreenReaderKeys(page, '^a');
    await sendScreenReaderKeys(page, arabicName);
    assert.equal(await page.locator('#tuc-onboarding-name').getAttribute('lang'), 'ar');
    await page.locator('#tuc-onboarding-menu-photo').setInputFiles({
      name: 'nvda-menu.png', mimeType: 'image/png',
      buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5Z8AAAAASUVORK5CYII=', 'base64'),
    });
    await page.evaluate(() => document.querySelector('#tuc-onboarding-name').focus());
    await sendScreenReaderKeys(page, '{TAB}');
    await sendScreenReaderKeys(page, '{TAB}');
    const responsePromise = page.waitForResponse((response) => response.url().includes('/wp-json/tuc/v1/restaurant-applications'));
    await sendScreenReaderKeys(page, '{ENTER}');
    const response = await responsePromise;
    assert.equal(response.status(), 201);
    await waitForActiveElement(page, () => document.activeElement?.id === 'tuc-onboarding-wa-link', 'WhatsApp link after application submission');
    await page.waitForTimeout(1200);

    const speechViewer = readSpeechViewer();
    const transcript = speechViewer.text || '';
    fs.writeFileSync(transcriptPath, transcript || JSON.stringify(speechViewer, null, 2), 'utf8');
    const unsupportedVoices = transcript.match(/(?:French|Arabic) \(not supported\)/ig) || [];
    if (unsupportedVoices.length) console.warn(`NVDA runner voice limitation: ${unsupportedVoices.join(', ')}`);
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
    for (const character of new Set([...arabicName.replace(/\s/gu, '')])) {
      assertSpoken(transcript, character, `NVDA character echo for Arabic input; UIA diagnostics: ${JSON.stringify(speechViewer.diagnostics)}`);
    }
    console.log(`PASS NVDA Speech Viewer: focus sequence, form labels/help, Arabic character echo, live status and WhatsApp link announcement. Transcript saved to ${transcriptPath}`);
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
