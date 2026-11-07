const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const baseUrl = (process.env.TUC_MOBILE_E2E_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const outputDir = process.env.TUC_NVDA_FIXTURE_DIR || path.join(process.cwd(), 'nvda-fixture');

async function main() {
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    const response = await page.goto(`${baseUrl}/devenir-restaurateur/`, { waitUntil: 'networkidle' });
    if (!response?.ok()) throw new Error(`Page onboarding HTTP ${response?.status() ?? 'unknown'}`);
    const component = await page.locator('.tuc-onboarding').evaluate((element) => {
      element.querySelector('form').dataset.rest = '/wp-json/tuc/v1/';
      return element.outerHTML;
    });
    fs.mkdirSync(outputDir, { recursive: true });
    fs.writeFileSync(path.join(outputDir, 'index.html'), `<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Demande restaurateur TUC — test NVDA</title><link rel="stylesheet" href="/restaurant-onboarding.css"><script src="/restaurant-onboarding.js" defer></script></head><body><main>${component}</main></body></html>\n`);
    for (const file of ['restaurant-onboarding.css', 'restaurant-onboarding.js']) {
      fs.copyFileSync(path.join(__dirname, '..', 'wordpress/tuc-market-core/assets/public', file), path.join(outputDir, file));
    }
    console.log(`Exported the live WordPress form markup and assets to ${outputDir}`);
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});
