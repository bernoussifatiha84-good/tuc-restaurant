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
    const sample = await page.evaluate(() => {
      const visible = (element) => !element.closest('[hidden],dialog:not([open])');
      const nav = performance.getEntriesByType('navigation')[0];
      const lcpEntries = performance.getEntriesByType('largest-contentful-paint');
      const shifts = performance.getEntriesByType('layout-shift').filter((entry) => !entry.hadRecentInput);
      return {
        width: innerWidth,
        documentWidth: document.documentElement.scrollWidth,
        horizontalOverflow: document.documentElement.scrollWidth > innerWidth + 1,
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
    });
    sample.securityHeaders = Object.fromEntries(
      ['content-security-policy', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'strict-transport-security']
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
    unauthenticatedApiProbe.push({ route, status: response.status(), protected: [401, 403].includes(response.status()) });
  }

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
  try {
    for (const target of pagesToAudit) {
      const page = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
      manual.push(await inspectPage(page, target));
      await page.close();
    }
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
    for (const probe of page.unauthenticatedApiProbe) {
      if (!probe.protected) issues.push({ page: page.name, severity: 'blocker', finding: `${probe.route} répond ${probe.status} sans session` });
    }
  }
  for (const page of lighthouse) {
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
    lighthouse,
  };
  const brief = {
    verdict: result.verdict,
    lighthouse: lighthouse.map(({ name, scores, vitals }) => ({ name, scores, vitals })),
    pages: manual.map((page) => {
      const sample = page.viewportChecks.find((entry) => entry.width === 390);
      return {
        name: page.name,
        httpStatus: page.httpStatus,
        widths: page.viewportChecks.map(({ width, documentWidth, horizontalOverflow }) => ({ width, documentWidth, horizontalOverflow })),
        seo: sample && { title: sample.title, descriptionLength: sample.description.length, canonical: sample.canonical, h1Count: sample.h1Count, placeholderLinks: sample.placeholderLinks.length, imagesMissingAlt: sample.imagesMissingAlt.length },
        axeViolations: page.accessibility.violations.map(({ id, impact, count }) => ({ id, impact, count })),
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

  const blocked = manual.some((entry) => entry.httpStatus < 200 || entry.httpStatus >= 400 || entry.viewportChecks.some((sample) => sample.horizontalOverflow));
  if (blocked) process.exitCode = 2;
}

main().catch((error) => {
  console.error('PROFESSIONAL_AUDIT_FATAL:', error.stack || error.message);
  process.exitCode = 1;
});
