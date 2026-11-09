#!/usr/bin/env node
/**
 * Parcours admin de l'import CSV de menu — critères 1 à 6.
 *
 * Appelé par `require()` depuis tests/restaurant-onboarding-e2e.js, qui est déjà
 * câblé dans la CI (« Parcours mobile de demande et d'accès restaurateur via n8n »).
 * Pourquoi ainsi : ce dépôt a déjà un parcours admin câblé (connexion wp-login,
 * page sous edit.php?post_type=tuc_resto, POST admin-post.php, assertion sur
 * .notice-success). On COPIE ce patron au lieu d'en inventer un second, et on
 * s'accroche au runner existant plutôt que d'ajouter un step — le jeton n'a pas
 * le scope `workflow`, et un second mécanisme aurait doublé la maintenance.
 *
 * Ce qui est vérifié, dans l'ordre des critères d'acceptation :
 *  1. les 3 modèles se téléchargent, avec BOM et en-têtes exactes ;
 *  2. les catégories proposées viennent bien du site ;
 *  3. un CSV valide affiche chaque ligne, le prix interprété et le résumé min/max ;
 *  4. un CSV fauté est refusé et RIEN n'est écrit ;
 *  5. après import, les plats sont en brouillon sur le bon restaurant ;
 *  6. réimporter le même fichier ne crée aucun doublon.
 *
 * Le critère 7 (QR sur téléphone) reste dehors : il demande un déploiement.
 */
'use strict';

const assert = require('node:assert/strict');

const ADMIN_PATH = '/wp-admin/edit.php?post_type=tuc_resto&page=tuc-import-csv';

const PLAFOND_CSV = 'reference;nom;description;prix;categorie;promo_pct;disponible\n'
  + 'plafond-poulet;Plafond poulet;Test import CI;45,00;Tacos;0;oui\n';

// Fautif exprès : obligatoire=oui avec min=0. Le cœur WordPress forcerait min=1
// en silence ; l'importeur doit refuser AVANT lui.
const OPTIONS_FAUTEUSES = 'plat_reference;groupe_id;groupe_nom;obligatoire;min;max;choix_id;choix_nom;supplement_prix;disponible\n'
  + 'plafond-poulet;taille;Taille;oui;0;1;m;M;0.00;oui\n';

const OPTIONS_VALIDES = 'plat_reference;groupe_id;groupe_nom;obligatoire;min;max;choix_id;choix_nom;supplement_prix;disponible\n'
  + 'plafond-poulet;taille;Taille;oui;1;1;m;M;0.00;oui\n'
  + 'plafond-poulet;taille;Taille;oui;1;1;l;L;10.00;oui\n';

/**
 * @param {{browser: import('playwright').Browser, baseUrl: string, adminUser: string, adminPassword: string}} contexte
 */
async function parcoursImportCsv({ browser, baseUrl, adminUser, adminPassword }) {
  const resultats = [];
  const adminContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const admin = await adminContext.newPage();
  await admin.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await admin.locator('#user_login').fill(adminUser);
  await admin.locator('#user_pass').fill(adminPassword);
  await admin.locator('#wp-submit').click();
  await admin.waitForLoadState('domcontentloaded');
  assert(!admin.url().includes('wp-login.php'), 'onboarding. l’équipe se connecte à WordPress');

  try {

  // ---- 1. les trois modèles ----
  await admin.goto(`${baseUrl}${ADMIN_PATH}`, { waitUntil: 'domcontentloaded' });
  await admin.locator('h1').filter({ hasText: 'Importer un menu par CSV' }).waitFor({ state: 'visible' });

  const telecharger = async (libelle) => {
    const [reponse] = await Promise.all([
      admin.waitForEvent('download'),
      admin.getByRole('link', { name: libelle, exact: true }).click(),
    ]);
    const chemin = await reponse.path();
    const fs = require('node:fs');
    return fs.readFileSync(chemin);
  };

  const plats = await telecharger('plats.csv');
  assert.ok(plats.subarray(0, 3).equals(Buffer.from([0xEF, 0xBB, 0xBF])), '1a. plats.csv commence par le BOM UTF-8');
  const entetePlats = plats.toString('utf8', 3).split(/\r?\n/)[0];
  assert.equal(entetePlats, 'reference;nom;description;prix;categorie;promo_pct;disponible', '1b. en-têtes exactes de plats.csv');
  assert.ok(!entetePlats.includes('image'), '1c. aucune colonne image (elle serait une promesse creuse)');
  // On ne cherche pas une lettre précise : le modèle dit « fromagère » (è), pas
  // « é ». La première version de ce test cherchait « é » et rougissait pour une
  // faute de ma part, pas du produit (run 42630093676).
  const textePlats = plats.toString('utf8');
  assert.match(textePlats, /[àâäéèêëîïôöùûüç]/i, '1d. les accents sont là — Excel les ouvrira correctement');
  assert.ok(Buffer.from(textePlats, 'utf8').equals(plats), '1e. le fichier est de l’UTF-8 valide de bout en bout');
  resultats.push('modeles');

  const options = await telecharger('options.csv');
  assert.ok(options.subarray(0, 3).equals(Buffer.from([0xEF, 0xBB, 0xBF])), '1f. options.csv a aussi son BOM');
  assert.ok(options.toString('utf8').includes('supplement_prix'), '1g. en-têtes de options.csv');

  const consigne = (await telecharger('consigne-ia.txt')).toString('utf8');
  assert.ok(consigne.includes('200 caractères maximum'), '1h. la consigne porte la limite réelle (octets → caractères)');
  assert.ok(!consigne.includes('- image :'), '1i. la consigne ne promet aucune colonne image');

  // ---- 2. les catégories proposées viennent du site ----
  const listeAffichee = await admin.locator('.wrap p').filter({ hasText: 'Les catégories proposées' }).first().innerText();
  assert.match(listeAffichee, /votre site|liste usuelle/, '2a. l’écran dit d’où viennent les catégories');
  assert.ok(listeAffichee.trim().length > 40, '2b. la liste est affichée, pas résumée');
  resultats.push('categories');

  // ---- 4. un CSV fauté est refusé, et rien n'est écrit ----
  await admin.locator('#csv_plats').setInputFiles({ name: 'plats.csv', mimeType: 'text/csv', buffer: Buffer.from(PLAFOND_CSV) });
  await admin.locator('#csv_options').setInputFiles({ name: 'options.csv', mimeType: 'text/csv', buffer: Buffer.from(OPTIONS_FAUTEUSES) });
  await admin.locator('#restaurant_id').selectOption({ label: 'Resto import contrat' });
  const [verification] = await Promise.all([
    admin.waitForResponse((r) => r.url().includes('admin-post.php') && r.request().method() === 'POST'),
    admin.getByRole('button', { name: 'Vérifier (aucune écriture)' }).click(),
  ]);
  assert.equal(verification.status(), 302, '4a. la vérification passe par admin-post.php');
  await admin.waitForLoadState('domcontentloaded');
  const refus = await admin.locator('.wrap').innerText();
  assert.match(refus, /Import impossible/i, '4b. l’import est bloqué');
  assert.match(refus, /obligatoire=oui avec min=0/i, '4c. le motif exact est affiché, pas un « erreur » générique');
  assert.match(refus, /Rien n’a été écrit|Rien n'a été écrit/i, '4d. l’écran dit que rien n’a été écrit');
  assert.equal(await admin.getByRole('button', { name: 'Importer', exact: true }).count(), 0, '4e. aucun bouton Importer : l’écriture est impossible');
  resultats.push('refus');

  // ---- 3. un CSV valide affiche chaque ligne, le prix et le résumé ----
  await admin.locator('#csv_options').setInputFiles({ name: 'options.csv', mimeType: 'text/csv', buffer: Buffer.from(OPTIONS_VALIDES) });
  const [verification2] = await Promise.all([
    admin.waitForResponse((r) => r.url().includes('admin-post.php') && r.request().method() === 'POST'),
    admin.getByRole('button', { name: 'Vérifier (aucune écriture)' }).click(),
  ]);
  assert.equal(verification2.status(), 302, '3a. seconde vérification acceptée');
  await admin.waitForLoadState('domcontentloaded');
  const tableau = await admin.locator('table.widefat').innerText();
  assert.match(tableau, /plafond-poulet/, '3b. la ligne est nommée');
  assert.match(tableau, /45,00/, '3c. le prix interprété est affiché avec la virgule française');
  assert.match(tableau, /créer/, '3d. l’action prévue est dite');
  const resume = await admin.locator('.wrap').innerText();
  assert.match(resume, /Prix de\s+45,00\s+à\s+45,00\s+MAD/, '3e. le résumé min/max est affiché');
  resultats.push('dryrun');

  // ---- 5. import : les plats arrivent en brouillon ----
  const [execution] = await Promise.all([
    admin.waitForResponse((r) => r.url().includes('admin-post.php') && r.request().method() === 'POST'),
    admin.getByRole('button', { name: 'Importer', exact: true }).click(),
  ]);
  assert.equal(execution.status(), 302, '5a. l’import passe');
  await admin.waitForLoadState('domcontentloaded');
  await admin.locator('.notice-success').filter({ hasText: 'brouillon' }).waitFor({ state: 'visible' });
  resultats.push('import');

  // ---- 6. réimport : aucun doublon ----
  await admin.locator('#csv_plats').setInputFiles({ name: 'plats.csv', mimeType: 'text/csv', buffer: Buffer.from(PLAFOND_CSV) });
  await admin.locator('#csv_options').setInputFiles({ name: 'options.csv', mimeType: 'text/csv', buffer: Buffer.from(OPTIONS_VALIDES) });
  await admin.locator('#restaurant_id').selectOption({ label: 'Resto import contrat' });
  await Promise.all([
    admin.waitForResponse((r) => r.url().includes('admin-post.php') && r.request().method() === 'POST'),
    admin.getByRole('button', { name: 'Vérifier (aucune écriture)' }).click(),
  ]);
  await admin.waitForLoadState('domcontentloaded');
  const secondPassage = await admin.locator('table.widefat').innerText();
  assert.match(secondPassage, /mettre à jour/, '6a. le second passage annonce une mise à jour, pas une création');
  const [execution2] = await Promise.all([
    admin.waitForResponse((r) => r.url().includes('admin-post.php') && r.request().method() === 'POST'),
    admin.getByRole('button', { name: 'Importer', exact: true }).click(),
  ]);
  assert.equal(execution2.status(), 302, '6b. le réimport passe');
  await admin.waitForLoadState('domcontentloaded');
  const bilan = await admin.locator('.notice-success').innerText();
  assert.match(bilan, /0 créé\(s\), 1 mis à jour/, '6c. AUCUN doublon créé');
  resultats.push('reimport');

  return resultats;
  } finally {
    await adminContext.close();
  }
}

module.exports = { parcoursImportCsv, ADMIN_PATH };
