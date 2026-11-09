#!/usr/bin/env node
/**
 * Preuve executable de A3 : la decision d'alerte de la caisse.
 *
 * On n'imite pas le DOM. On extrait la fonction PURE `commandesASignaler` du
 * vrai fichier `includes/vues/caisse.php`, puis on la confronte aux cas qui ont
 * produit le defaut. Si la fonction disparait ou change de signature, la preuve
 * casse — c'est voulu.
 *
 * Aucun acces reseau, aucun navigateur.
 */
'use strict';

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const VUE = path.join(__dirname, '..', 'wordpress', 'tuc-market-core', 'includes', 'vues', 'caisse.php');

function extraireFonction() {
  const html = fs.readFileSync(VUE, 'utf8');
  const debut = html.indexOf('function commandesASignaler');
  if (debut === -1) throw new Error('commandesASignaler introuvable dans la vue caisse');
  // on prend jusqu'a la fermeture de la fonction (accolade equilibree)
  let i = html.indexOf('{', debut);
  let profondeur = 0;
  for (let j = i; j < html.length; j++) {
    if (html[j] === '{') profondeur++;
    else if (html[j] === '}') {
      profondeur--;
      if (profondeur === 0) return html.slice(html.indexOf('function commandesASignaler'), j + 1);
    }
  }
  throw new Error('accolade de fermeture introuvable');
}

const source = extraireFonction();
// eslint-disable-next-line no-new-func
const commandesASignaler = new Function(source + '\nreturn commandesASignaler;')();

const T0 = 1_762_730_000; // instant serveur du premier releve
const FENETRE = 120;

let echecs = 0;
function cas(label, commandes, ids, attendu) {
  const obtenu = commandesASignaler(commandes, new Set(ids), T0, FENETRE).map((c) => c.id);
  const ok = JSON.stringify(obtenu) === JSON.stringify(attendu);
  console.log((ok ? 'PASS ' : 'FAIL ') + label + ' : [' + obtenu.join(',') + ']' +
    (ok ? '' : ` (attendu [${attendu.join(',')}])`));
  if (!ok) echecs++;
}

// ---- le defaut d'origine ----
// Une commande tombee pendant le rechargement, 30 s avant le premier releve.
// Avant A3 : baseline=false => aucune alerte, identifiant absorbe. Jamais sonnee.
cas('LE DEFAUT : commande recente au tout premier releve',
  [{ id: 1, statut: 'nouvelle', cree: T0 - 30 }], [], [1]);

// Une commande de la fenetre et une de la journee : seule la recente sonne.
cas('l\'existant ne sonne pas, la recente oui',
  [{ id: 1, statut: 'nouvelle', cree: T0 - 3600 },
   { id: 2, statut: 'nouvelle', cree: T0 - 10 }], [], [2]);

// Deja presentee : plus de son, meme recente (deduplication intra-session).
cas('deja presentee ne resonne pas',
  [{ id: 3, statut: 'nouvelle', cree: T0 - 5 }], [3], []);

// Deja acceptee : pas de son, meme jamais vue par cette page.
cas('deja acceptee ne sonne pas',
  [{ id: 4, statut: 'acceptee', cree: T0 - 5 }], [], []);

// Limite exacte de la fenetre.
cas('exactement a la limite de la fenetre',
  [{ id: 5, statut: 'nouvelle', cree: T0 - FENETRE }], [], [5]);
cas('une seconde hors fenetre',
  [{ id: 6, statut: 'nouvelle', cree: T0 - FENETRE - 1 }], [], []);

// Plusieurs commandes en attente : toutes signalees, dans l'ordre du serveur.
cas('trois commandes recentes',
  [{ id: 7, statut: 'nouvelle', cree: T0 - 3 },
   { id: 8, statut: 'nouvelle', cree: T0 - 2 },
   { id: 9, statut: 'nouvelle', cree: T0 - 1 }], [], [7, 8, 9]);

// Fail-closed : serveur qui n'expose pas 'cree' (deploiement mixte).
cas('sans champ cree : rien ne sonne (fail-closed)',
  [{ id: 10, statut: 'nouvelle' }], [], []);

// Liste vide / nulle : pas d'exception.
cas('liste vide', [], [], []);
cas('liste nulle', null, [], []);

console.log(`\n${echecs === 0 ? 'OK' : 'ECHEC'} — ${echecs} echec(s)`);
process.exit(echecs === 0 ? 0 : 1);
