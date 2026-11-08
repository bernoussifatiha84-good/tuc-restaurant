const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const fixtureDir = process.env.TUC_NVDA_FIXTURE_DIR;
const captureDir = process.env.TUC_NVDA_CAPTURE_DIR;
const reportPath = process.env.TUC_NVDA_REPORT;
assert(fixtureDir && captureDir && reportPath, 'TUC_NVDA_FIXTURE_DIR, TUC_NVDA_CAPTURE_DIR and TUC_NVDA_REPORT are required');
const port = Number(process.env.TUC_NVDA_TEST_PORT || 4173);
const arabicName = 'مطعم الاختبار';
const mimeTypes = { '.css': 'text/css', '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8' };
const report = { focusTrail: [], phases: [], assertions: [] };

function nativeKeys(...keys) {
  execFileSync(process.env.PYTHON || 'python', [path.join(__dirname, 'send-keys.py'), JSON.stringify(keys)], { encoding: 'utf8', timeout: 30000 });
}

function utterances() {
  return fs.readdirSync(captureDir).filter((name) => /^\d+\.json$/.test(name)).sort()
    .map((name) => JSON.parse(fs.readFileSync(path.join(captureDir, name), 'utf8')));
}

let activePage;
async function waitForSpeech(needle, timeout = 20000) {
  const start = Date.now();
  while (Date.now() - start < timeout) {
    const match = utterances().find((item) => item.text.toLocaleLowerCase().includes(needle.toLocaleLowerCase()));
    if (match) return match;
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  const state = activePage ? await activePage.evaluate(() => ({ active: document.activeElement?.outerHTML?.slice(0, 500), value: document.querySelector('#tuc-onboarding-name')?.value, lang: document.querySelector('#tuc-onboarding-name')?.lang, selection: [document.activeElement?.selectionStart, document.activeElement?.selectionEnd], hasFocus: document.hasFocus() })) : null;
  throw new Error(`NVDA did not send ${JSON.stringify(needle)} to its built-in eSpeak driver. DOM: ${JSON.stringify(state)}. Captured text:\n${utterances().map((item) => item.text).join('\n')}`);
}

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
    response.writeHead(200, { 'Content-Type': mimeTypes[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
    fs.createReadStream(file).pipe(response);
  });
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, '127.0.0.1', resolve);
  });
  return server;
}

async function main() {
  assert(fs.existsSync(path.join(fixtureDir, 'index.html')), `fixture missing: ${fixtureDir}`);
  const server = await startFixtureServer();
  const browser = await chromium.launch({ headless: false, args: ['--window-size=1100,900', '--window-position=0,0'] });
  try {
    const context = await browser.newContext({ viewport: { width: 1000, height: 760 } });
    const page = await context.newPage();
    activePage = page;
    await page.route('**/wp-json/tuc/v1/restaurant-applications', (route) => route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        reference: 'NVDA123456', code: 'NVDA1234', poll: 'nvda-test-poll-token',
        wa_url: 'https://wa.me/212600000095?text=NVDA%20test',
        message: 'Demande enregistrée. Envoyez le message WhatsApp prérempli pour que notre équipe puisse vous retrouver.',
      }),
    }));
    await page.goto(`http://127.0.0.1:${port}/`, { waitUntil: 'load' });
    await page.bringToFront();
    await page.evaluate(() => {
      window.__tucFocusTrail = [];
      document.addEventListener('focusin', (event) => {
        const target = event.target;
        window.__tucFocusTrail.push(target.id || (typeof target.className === 'string' ? target.className : '') || target.tagName);
      });
    });
    await page.locator('#tuc-onboarding-menu-photo').setInputFiles({
      name: 'nvda-menu.png', mimeType: 'image/png',
      buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5Z8AAAAASUVORK5CYII=', 'base64'),
    });

    // Programmatic focus only sets the starting point. Every traversal and the
    // submission below use Windows key events, which NVDA can intercept.
    await page.locator('#tuc-onboarding-name').focus();
    await page.bringToFront();
    await waitForSpeech('Nom du restaurant');
    nativeKeys('{TAB}');
    await page.waitForFunction(() => document.activeElement?.id === 'tuc-onboarding-menu-photo');
    await waitForSpeech('Photo du menu');
    nativeKeys('+{TAB}');
    await page.waitForFunction(() => document.activeElement?.id === 'tuc-onboarding-name');
    await waitForSpeech('Nom du restaurant');
    report.phases.push('native Tab and Shift+Tab between name and photo');

    // CDP text insertion is deterministic for Arabic on an English runner.
    // NVDA may echo individual characters rather than the whole value; require
    // every distinct character, then select all so NVDA reads the full edit.
    await page.locator('#tuc-onboarding-name').fill(arabicName);
    await page.waitForFunction(() => document.querySelector('#tuc-onboarding-name').lang === 'ar');
    await page.locator('#tuc-onboarding-name').focus();
    await page.bringToFront();
    nativeKeys('^a', '{HOME}', '{RIGHT 12}');
    for (const character of new Set([...arabicName.replace(/\s/gu, '')])) {
      await waitForSpeech(character);
    }
    nativeKeys('^a');
    await waitForSpeech(arabicName);
    report.phases.push('Arabic character echo and native select-all reading with lang=ar');

    await page.locator('#tuc-onboarding-name').focus();
    nativeKeys('{TAB 2}');
    await page.waitForFunction(() => document.activeElement?.matches('.tuc-onboarding-submit[type="submit"]'));
    await waitForSpeech('Demander l’accompagnement');
    const responsePromise = page.waitForResponse((response) => response.url().includes('/wp-json/tuc/v1/restaurant-applications'));
    nativeKeys('{ENTER}');
    assert.equal((await responsePromise).status(), 201);
    await page.waitForFunction(() => document.activeElement?.id === 'tuc-onboarding-wa-link');
    await waitForSpeech('Demande enregistrée');
    await waitForSpeech('Continuer dans WhatsApp');
    report.focusTrail = await page.evaluate(() => window.__tucFocusTrail);
    const nameIndex = report.focusTrail.indexOf('tuc-onboarding-name');
    const photoIndex = report.focusTrail.indexOf('tuc-onboarding-menu-photo');
    const submitIndex = report.focusTrail.findIndex((entry) => entry.includes('tuc-onboarding-submit'));
    assert(nameIndex >= 0 && photoIndex > nameIndex && submitIndex > photoIndex, `Unexpected native focus order: ${JSON.stringify(report.focusTrail)}`);
    report.phases.push('native Enter submission, live status and focus on WhatsApp link');
    report.assertions.push('focus order name -> photo -> submit', 'Arabic input lang=ar', 'NVDA received required French announcements', 'HTTP 201 mocked application response', 'focus moved to WhatsApp link');
    await page.waitForTimeout(1000);
    await context.close();
  } finally {
    report.capturedText = utterances().map((item) => item.text);
    fs.writeFileSync(reportPath, JSON.stringify(report, null, 2), 'utf8');
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }
}

main().then(() => console.log('PASS: native Windows keyboard traversal with NVDA built-in eSpeak PCM capture')).catch((error) => {
  report.error = error.stack || String(error);
  fs.writeFileSync(reportPath, JSON.stringify(report, null, 2), 'utf8');
  console.error(report.error);
  process.exitCode = 1;
});
