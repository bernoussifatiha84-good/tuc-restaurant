'use strict';

const { chromium } = require('playwright');
const axe = require('axe-core');
const fs = require('node:fs');
const { pathToFileURL } = require('node:url');

const baseUrl = (process.env.TUC_MOBILE_E2E_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const pagesToAudit = [
  { name: 'accueil', url: `${baseUrl}/` },
  { name: 'fiche-restaurant', url: `${baseUrl}/tuc-mobile-e2e/` },
];
const widths = [320, 360, 390, 768];

async function inspectPage(page, target) {
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') pageErrors.push(`console: ${message.text()}`);
  });

  const measurements = [];
  let lastResponse = null;
  for (const width of widths) {
    await page.setViewportSize({ width, height: 844 });
    lastResponse = await page.goto(target.url, { waitUntil: 'networkidle', timeout: 30000 });
    const sample = await page.evaluate((requestedWidth) => {
      const visible = (element) => !element.closest('[hidden],dialog:not([open])');
      const nav = performance.getEntriesByType('navigation')[0];
      const visualWidth = window.visualViewport?.width || innerWidth;
      const lcpEntries = performance.getEntriesByType('largest-contentful-paint');
      const shifts = performance.getEntriesByType('layout-shift').filter((entry) => !entry.hadRecentInput);
      return {
        width: requestedWidth,
        layoutViewportWidth: innerWidth,
        visualViewportWidth: Number(visualWidth.toFixed(1)),
        documentWidth: document.documentElement.scrollWidth,
        horizontalOverflow: document.documentElement.scrollWidth > visualWidth + 1,
        title: document.title.trim(),
        description: document.querySelector('meta[name="description"]')?.content?.trim() || '',
        canonical: document.querySelector('link[rel="canonical"]')?.href || '',
        viewportMeta: document.querySelector('meta[name="viewport"]')?.content || '',
        h1Count: document.querySelectorAll('h1').length,
        h1: [...document.querySelectorAll('h1')].map((node) => node.innerText.trim()).filter(Boolean),
        imagesMissingAlt: [...document.images].filter((image) => !image.hasAttribute('alt')).map((image) => image.currentSrc || image.src).slice(0, 10),
        placeholderLinks: [...document.querySelectorAll('a[href="#"]')].filter(visible).map((link) => link.innerText.trim() || '(sans texte)').slice(0, 20),
        unnamedButtons: [...document.querySelectorAll('button')].filter((button) => visible(button) && !button.innerText.trim() && !button.getAttribute('aria-label') && !button.getAttribute('title')).length,
        ttfbMs: nav ? Math.round(nav.responseStart - nav.requestStart) : null,
        domContentLoadedMs: nav ? Math.round(nav.domContentLoadedEventEnd) : null,
        loadMs: nav ? Math.round(nav.loadEventEnd) : null,
        lcpMs: lcpEntries.length ? Math.round(lcpEntries[lcpEntries.length - 1].startTime) : null,
        cls: Number(shifts.reduce((total, entry) => total + entry.value, 0).toFixed(4)),
      };
    }, width);
    sample.securityHeaders = Object.fromEntries(
      ['content-security-policy', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy', 'strict-transport-security']
        .map((name) => [name, lastResponse?.headers()[name] || 'not set'])
    );
    measurements.push(sample);
  }

  // Axe WCAG scan at the common mobile width; retain full violation summaries in the log.
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(target.url, { waitUntil: 'networkidle', timeout: 30000 });
  await page.addScriptTag({ content: axe.source });
  const accessibility = await page.evaluate(async () => {
    const result = await window.axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] },
    });
    return {
      passes: result.passes.length,
      incomplete: result.incomplete.length,
      violations: result.violations.map((violation) => ({
        id: violation.id,
        impact: violation.impact,
        help: violation.help,
        count: violation.nodes.length,
        examples: violation.nodes.slice(0, 3).map((node) => ({ target: node.target, summary: node.failureSummary })),
      })),
    };
  });

  const unauthenticatedApiProbe = [];
  for (const route of ['/wp-json/tuc/v1/pro/restaurant', '/wp-json/tuc/v1/caisse/commandes?statut=toutes']) {
    const response = await page.context().request.get(`${baseUrl}${route}`);
    unauthenticatedApiProbe.push({ method: 'GET', route, status: response.status(), protected: [401, 403].includes(response.status()) });
  }
  const locationRoute = '/wp-json/tuc/v1/pro/restaurant/location';
  const locationResponse = await page.context().request.post(`${baseUrl}${locationRoute}`, {
    data: { lat: 33.5900, lng: -7.6280, source: 'google_maps_link', confirmed: true },
  });
  unauthenticatedApiProbe.push({ method: 'POST', route: locationRoute, status: locationResponse.status(), protected: [401, 403].includes(locationResponse.status()) });

  return {
    name: target.name,
    requestedUrl: target.url,
    finalUrl: page.url(),
    httpStatus: lastResponse?.status() ?? null,
    viewportChecks: measurements,
    accessibility,
    unauthenticatedApiProbe,
    browserErrors: [...new Set(pageErrors)].slice(0, 20),
  };
}

async function auditProDashboard(browser) {
  const username = process.env.TUC_MOBILE_E2E_USER;
  const password = process.env.TUC_MOBILE_E2E_PASSWORD;
  if (!username || !password) return { skipped: true, reason: 'Identifiants E2E non fournis.' };

  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const page = await context.newPage();
  const browserErrors = [];
  page.on('pageerror', (error) => browserErrors.push(error.stack || error.message));
  try {
    await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await page.locator('#user_login').fill(username);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
    await page.waitForLoadState('domcontentloaded');
    const proResponse = await page.goto(`${baseUrl}/espace-pro/`, { waitUntil: 'networkidle' });
    if (!proResponse || proResponse.status() >= 400) throw new Error(`Espace Pro répond HTTP ${proResponse?.status() ?? 'inconnu'}.`);
    if (!await page.evaluate(() => Boolean(window.TUCPro?.canManageRestaurant))) {
      throw new Error('Le compte synthétique n’a pas accès au panneau boutique de l’Espace Pro.');
    }
    await page.locator('[data-tab="settings"]').click();
    await page.locator('#tuc-store-location').waitFor({ state: 'visible' });

    const widthsChecked = [];
    for (const width of widths) {
      await page.setViewportSize({ width, height: 844 });
      const sample = await page.evaluate((requestedWidth) => ({
        width: requestedWidth,
        documentWidth: document.documentElement.scrollWidth,
        horizontalOverflow: document.documentElement.scrollWidth > (window.visualViewport?.width || innerWidth) + 1,
        locationPanelVisible: Boolean(document.querySelector('#tuc-store-location')?.getClientRects().length),
      }), width);
      widthsChecked.push(sample);
    }

    // Keyboard-only path through URL parsing, confirmation, and save control.
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('#tuc-store-google-link').fill('https://www.google.com/maps/place/Test/@33.59000,-7.62800,17z/data=!3m1!4b1!3d33.59123!4d-7.62789');
    await page.locator('#tuc-capture-location').focus();
    await page.keyboard.press('Tab');
    const focusAfterGps = await page.evaluate(() => document.activeElement?.id);
    await page.keyboard.press('Tab');
    const focusAfterUrl = await page.evaluate(() => document.activeElement?.id);
    await page.keyboard.press('Enter');
    await page.locator('#tuc-location-confirm').waitFor({ state: 'visible' });
    await page.keyboard.press('Tab');
    const focusAfterParse = await page.evaluate(() => document.activeElement?.id);
    await page.keyboard.press('Space');
    await page.keyboard.press('Tab');
    const focusAfterConfirmation = await page.evaluate(() => document.activeElement?.id);

    await page.addScriptTag({ content: axe.source });
    const accessibility = await page.evaluate(async () => {
      const result = await window.axe.run(document, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] },
      });
      return {
        passes: result.passes.length,
        incomplete: result.incomplete.length,
        violations: result.violations.map((violation) => ({
          id: violation.id, impact: violation.impact, help: violation.help,
          count: violation.nodes.length,
          examples: violation.nodes.slice(0, 3).map((node) => ({ target: node.target, summary: node.failureSummary })),
        })),
      };
    });
    return { skipped: false, httpStatus: proResponse.status(), widths: widthsChecked, keyboardFocus: { focusAfterGps, focusAfterUrl, focusAfterParse, focusAfterConfirmation }, accessibility, browserErrors };
  } finally {
    await context.close();
  }
}

async function lighthouseAudit(target, chromePath) {
  const { default: lighthouse } = await import(pathToFileURL(require.resolve('lighthouse')).href);
  const { launch } = await import(pathToFileURL(require.resolve('chrome-launcher')).href);
  const chrome = await launch({
    chromePath,
    chromeFlags: ['--headless', '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
  });
  try {
    const result = await lighthouse(target.url, {
      port: chrome.port,
      logLevel: 'error',
      output: 'json',
      onlyCategories: ['performance', 'accessibility', 'best-practices', 'seo'],
      formFactor: 'mobile',
      screenEmulation: { mobile: true, width: 390, height: 844, deviceScaleFactor: 1, disabled: false },
      throttlingMethod: 'simulate',
    });
    if (!result?.lhr) throw new Error(`Lighthouse n’a pas retourné de rapport pour ${target.name}.`);
    const lhr = result.lhr;
    const scores = Object.fromEntries(Object.entries(lhr.categories).map(([key, value]) => [key, value.score === null ? null : Math.round(value.score * 100)]));
    const keyAudits = ['document-title', 'meta-description', 'canonical', 'is-crawlable', 'viewport', 'image-alt', 'link-text'];
    const findings = keyAudits.map((key) => {
      const audit = lhr.audits[key];
      return audit ? { id: key, score: audit.score, display: audit.displayValue || '', title: audit.title } : null;
    }).filter(Boolean);
    const vitals = Object.fromEntries(['first-contentful-paint', 'largest-contentful-paint', 'speed-index', 'total-blocking-time', 'cumulative-layout-shift', 'interactive'].map((key) => [key, lhr.audits[key]?.displayValue || null]));
    return { name: target.name, scores, vitals, findings };
  } finally {
    await chrome.kill();
  }
}

async function main() {
  const browser = await chromium.launch({ headless: true });
  const manual = [];
  let proDashboard;
  try {
    for (const target of pagesToAudit) {
      const page = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
      manual.push(await inspectPage(page, target));
      await page.close();
    }
    proDashboard = await auditProDashboard(browser);
  } finally {
    await browser.close();
  }

  const { chromium: pwChromium } = require('playwright');
  const lighthouse = [];
  for (const target of pagesToAudit) {
    lighthouse.push(await lighthouseAudit(target, pwChromium.executablePath()));
  }

  // Diagnostic thresholds follow common release expectations; findings are reported without hiding the raw evidence.
  const issues = [];
  for (const page of manual) {
    const at390 = page.viewportChecks.find((sample) => sample.width === 390);
    if (!page.httpStatus || page.httpStatus >= 400) issues.push({ page: page.name, severity: 'blocker', finding: `HTTP ${page.httpStatus}` });
    if (page.viewportChecks.some((sample) => sample.horizontalOverflow)) issues.push({ page: page.name, severity: 'high', finding: 'débordement horizontal sur un viewport mobile' });
    if (at390 && !at390.description) issues.push({ page: page.name, severity: 'high', finding: 'meta description absente' });
    if (at390 && at390.h1Count !== 1) issues.push({ page: page.name, severity: 'high', finding: `la page contient ${at390.h1Count} balise(s) H1, attendu : 1` });
    if (at390?.placeholderLinks.length) issues.push({ page: page.name, severity: 'medium', finding: `${at390.placeholderLinks.length} lien(s) visibles pointent vers #` });
    if (at390?.imagesMissingAlt.length) issues.push({ page: page.name, severity: 'high', finding: `${at390.imagesMissingAlt.length} image(s) sans attribut alt` });
    if (at390?.unnamedButtons) issues.push({ page: page.name, severity: 'medium', finding: `${at390.unnamedButtons} bouton(s) sans nom accessible détecté(s)` });
    if (page.accessibility.violations.some((violation) => ['critical', 'serious'].includes(violation.impact))) issues.push({ page: page.name, severity: 'high', finding: 'violations Axe de sévérité serious/critical' });
    if (page.browserErrors.length) issues.push({ page: page.name, severity: 'medium', finding: `${page.browserErrors.length} erreur(s) JavaScript navigateur` });
    for (const header of ['x-content-type-options', 'x-frame-options', 'referrer-policy', 'permissions-policy']) {
      if (at390?.securityHeaders[header] === 'not set') issues.push({ page: page.name, severity: 'high', finding: `en-tête de sécurité ${header} absent` });
    }
    for (const probe of page.unauthenticatedApiProbe) {
      if (!probe.protected) issues.push({ page: page.name, severity: 'blocker', finding: `${probe.method} ${probe.route} répond ${probe.status} sans session` });
    }
  }
  if (proDashboard && !proDashboard.skipped) {
    if (proDashboard.httpStatus >= 400) issues.push({ page: 'espace-pro', severity: 'blocker', finding: `HTTP ${proDashboard.httpStatus}` });
    if (proDashboard.widths.some((sample) => sample.horizontalOverflow || !sample.locationPanelVisible)) {
      issues.push({ page: 'espace-pro', severity: 'high', finding: 'localisation masquée ou débordement horizontal sur un viewport mobile' });
    }
    const expectedFocus = ['tuc-store-google-link', 'tuc-parse-google-link', 'tuc-location-confirm', 'tuc-save-location'];
    const observedFocus = Object.values(proDashboard.keyboardFocus);
    if (observedFocus.some((id, index) => id !== expectedFocus[index])) {
      issues.push({ page: 'espace-pro', severity: 'high', finding: `parcours clavier inattendu: ${observedFocus.join(' → ')}` });
    }
    if (proDashboard.accessibility.violations.some((violation) => ['critical', 'serious'].includes(violation.impact))) {
      issues.push({ page: 'espace-pro', severity: 'high', finding: 'violations Axe de sévérité serious/critical' });
    }
    if (proDashboard.browserErrors.length) issues.push({ page: 'espace-pro', severity: 'medium', finding: `${proDashboard.browserErrors.length} erreur(s) JavaScript navigateur` });
  }
  for (const page of lighthouse) {
    const cls = Number.parseFloat(page.vitals['cumulative-layout-shift'] || '');
    const lcpSeconds = Number.parseFloat(page.vitals['largest-contentful-paint'] || '');
    if (Number.isFinite(cls) && cls > 0.1) issues.push({ page: page.name, severity: 'high', finding: `CLS=${cls}, au-dessus du seuil recommandé de 0.1` });
    if (Number.isFinite(lcpSeconds) && lcpSeconds > 2.5) issues.push({ page: page.name, severity: 'high', finding: `LCP=${lcpSeconds}s, au-dessus du seuil recommandé de 2.5s` });
    for (const [category, minimum] of [['performance', 90], ['accessibility', 95], ['best-practices', 95], ['seo', 95]]) {
      if (page.scores[category] !== null && page.scores[category] < minimum) {
        issues.push({ page: page.name, severity: 'medium', finding: `Lighthouse ${category}=${page.scores[category]}, seuil audit=${minimum}` });
      }
    }
  }
  const result = {
    audit: 'TUC professional web audit (GitHub Actions WordPress/Docker runner)',
    timestamp: new Date().toISOString(),
    environment: { baseUrl, mobileWidths: widths, lighthouseFormFactor: 'mobile / simulated throttling' },
    verdict: issues.length ? 'À CORRIGER AVANT DE REVENDIQUER UNE QUALITÉ PROFESSIONNELLE' : 'SEUILS AUTOMATISÉS ATTEINTS',
    issues,
    manual,
    proDashboard,
    lighthouse,
  };
  const brief = {
    verdict: result.verdict,
    proDashboard: proDashboard && (proDashboard.skipped ? proDashboard : {
      httpStatus: proDashboard.httpStatus,
      widths: proDashboard.widths,
      keyboardFocus: proDashboard.keyboardFocus,
      axeViolations: proDashboard.accessibility.violations.map(({ id, impact, count, examples }) => ({ id, impact, count, targets: examples.slice(0, 2).map((example) => example.target) })),
    }),
    lighthouse: lighthouse.map(({ name, scores, vitals }) => ({ name, scores, vitals })),
    pages: manual.map((page) => {
      const sample = page.viewportChecks.find((entry) => entry.width === 390);
      return {
        name: page.name,
        httpStatus: page.httpStatus,
        widths: page.viewportChecks.map(({ width, layoutViewportWidth, visualViewportWidth, documentWidth, horizontalOverflow }) => ({ width, layoutViewportWidth, visualViewportWidth, documentWidth, horizontalOverflow })),
        seo: sample && { title: sample.title, descriptionLength: sample.description.length, canonical: sample.canonical, h1Count: sample.h1Count, placeholderLinks: sample.placeholderLinks.length, imagesMissingAlt: sample.imagesMissingAlt.length },
        axeViolations: page.accessibility.violations.map(({ id, impact, count, examples }) => ({ id, impact, count, ...(impact === 'serious' || impact === 'critical' ? { targets: examples.slice(0, 3).map((example) => example.target) } : {}) })),
        unauthenticatedApiProbe: page.unauthenticatedApiProbe,
        securityHeaders: sample?.securityHeaders,
      };
    }),
    issues: issues.slice(0, 20),
    additionalIssueCount: Math.max(0, issues.length - 20),
  };
  const markdown = [
    '## Audit web TUC — WordPress + Docker + navigateur Chromium',
    '',
    `**Verdict automatisé : ${result.verdict}**`,
    '',
    '| Page | Performance | Accessibilité | Bonnes pratiques | SEO |',
    '|---|---:|---:|---:|---:|',
    ...lighthouse.map((page) => `| ${page.name} | ${page.scores.performance ?? '—'} | ${page.scores.accessibility ?? '—'} | ${page.scores['best-practices'] ?? '—'} | ${page.scores.seo ?? '—'} |`),
    '',
    `Espace Pro : ${proDashboard?.skipped ? 'audit ignoré (' + proDashboard.reason + ')' : 'HTTP ' + proDashboard?.httpStatus + ', contrôles clavier, Axe et responsive à 320/360/390/768 px.'}`,
    '',
    '### Principaux constats',
    ...(issues.length ? issues.slice(0, 30).map((issue) => `- **${issue.severity} · ${issue.page}** — ${issue.finding}`) : ['- Aucun écart aux seuils automatisés configurés.']),
    '',
    `Détails de chaque viewport, Axe, en-têtes HTTP, API protégées et métriques Lighthouse : artefact JSON du run. Nombre total de constats : ${issues.length}.`,
  ].join('\n');
  if (process.env.GITHUB_STEP_SUMMARY) fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${markdown}\n`);
  if (process.env.TUC_AUDIT_REPORT_PATH) fs.writeFileSync(process.env.TUC_AUDIT_REPORT_PATH, `${JSON.stringify(result, null, 2)}\n`);

  const annotation = JSON.stringify(brief).replace(/%/g, '%25').replace(/\r/g, '%0D').replace(/\n/g, '%0A');
  console.log(`::warning title=TUC professional audit::${annotation.slice(0, 5500)}`);
  console.log('PROFESSIONAL_AUDIT_JSON_BEGIN');
  console.log(JSON.stringify(result, null, 2));
  console.log('PROFESSIONAL_AUDIT_JSON_END');

  const blocked = issues.some((issue) => ['blocker', 'high'].includes(issue.severity));
  if (blocked) process.exitCode = 2;
}

main().catch((error) => {
  console.error('PROFESSIONAL_AUDIT_FATAL:', error.stack || error.message);
  process.exitCode = 1;
});
