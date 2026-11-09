#!/usr/bin/env node
/**
 * Preuve executable de A3 : la decision d'alerte de la caisse.
 *
 * On n'imite pas le DOM. On extrait la fonction PURE `commandesASignaler` du
 * vrai fichier `includes/vues/caisse.php`, puis on la confronte aux cas reels.
 * Si la fonction disparait ou change de signature, la preuve casse — c'est voulu.
 *
 * Aucun acces reseau, aucun navigateur.
 */
'use strict';

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const VUE = path.join(__dirname, '..', 'wordpress', 'tuc-market-core', 'includes', 'vues', 'caisse.php');

function blocSource() {
  const html = fs.readFileSync(VUE, 'utf8');
  const debut = html.indexOf('function commandesASignaler');
  if (debut === -1) throw new Error('commandesASignaler introuvable dans la vue caisse');

  // la marge est declaree juste avant : on la remonte avec la fonction
  const marque = html.lastIndexOf('const MARGE_DE_REPRISE', debut);
  const debutBloc = marque === -1 ? debut : marque;

  let i = html.indexOf('{', debut);
  let profondeur = 0;
  for (let j = i; j < html.length; j++) {
    if (html[j] === '{') profondeur++;
    else if (html[j] === '}') {
      profondeur--;
      if (profondeur === 0) return html.slice(debutBloc, j + 1);
    }
  }
  throw new Error('accolade de fermeture introuvable');
}

const source = blocSource();
// eslint-disable-next-line no-new-func
const charge = new Function(source + '\nreturn commandesASignaler;');
const commandesASignaler = charge();

if (typeof commandesASignaler !== 'function') {
  console.error('FAIL commandesASignaler non extraite');
  process.exit(1);
}

const T0 = 1_762_730_000; // peu importe : A3 ne compare plus d'horloge
let echecs = 0;
function cas(label, commandes, vues, attendu) {
  const obtenu = commandesASignaler(commandes, new Set(vues)).map((c) => c.id);
  const ok = JSON.stringify(obtenu) === JSON.stringify(attendu);
  console.log((ok ? 'PASS ' : 'FAIL ') + label + ' : [' + obtenu.join(',') + ']' +
    (ok ? '' : ` (attendu [${attendu.join(',')}])`));
  if (!ok) echecs++;
}

// ---- le defaut d'origine : 1er relevé, commande recente, jamais sonnee ----
cas('LE DEFAUT : commande recente au tout premier relevé',
  [{ id: 1, statut: 'nouvelle', cree: T0 - 5 }], [], [1]);

// ---- LE POINT SIGNALE PAR LA REVUE : aucun trou residuel ----
// Une commande encore "nouvelle" 3 h apres : personne ne l'a prise.
// Avec une fenetre de 120 s elle ne sonnait plus jamais. Ici, elle sonne.
cas('commande non prise depuis 3 h : elle sonne encore',
  [{ id: 40, statut: 'nouvelle', cree: T0 - 3 * 3600 }], [], [40]);

// et une commande d'hier, non prise : elle sonne aussi. Le temps n'est pas
// l'acquittement — le statut l'est.
cas('commande non prise depuis hier : elle sonne',
  [{ id: 42, statut: 'nouvelle', cree: T0 - 86400 }], [], [42]);

// ---- l'acquittement reel coupe le son ----
cas('deja acceptee : plus de son, meme tres recente',
  [{ id: 41, statut: 'acceptee', cree: T0 - 3 }], [], []);
cas('deja prete : plus de son',
  [{ id: 43, statut: 'prete', cree: T0 - 3 }], [], []);

// ---- deduplication par appareil (sessionStorage) ----
cas('deja signalee sur cet appareil : ne resonne pas',
  [{ id: 3, statut: 'nouvelle', cree: T0 - 5 }], ['3'], []);

// un AUTRE appareil de caisse n'a rien vu : il doit sonner. C'est le
// comportement voulu (patron + caisse), pas un doublon.
cas('autre appareil : il sonne sur la meme commande',
  [{ id: 3, statut: 'nouvelle', cree: T0 - 5 }], [], [3]);

// ---- plusieurs commandes ----
cas('trois commandes en attente',
  [{ id: 7, statut: 'nouvelle', cree: T0 - 3 },
   { id: 8, statut: 'nouvelle', cree: T0 - 2 },
   { id: 9, statut: 'nouvelle', cree: T0 - 1 }], [], [7, 8, 9]);

cas('melange : seules les non prises sont signalees',
  [{ id: 50, statut: 'nouvelle', cree: T0 - 900 },
   { id: 51, statut: 'acceptee', cree: T0 - 800 },
   { id: 52, statut: 'nouvelle', cree: T0 - 700 }], [], [50, 52]);

// les identifiants sont compares en chaine (sessionStorage serialise en JSON)
cas('identifiant numerique vs chaine : deduplication quand meme',
  [{ id: 60, statut: 'nouvelle' }], ['60'], []);

cas('liste vide', [], [], []);
cas('liste nulle', null, [], []);

console.log(`\n${echecs === 0 ? 'OK' : 'ECHEC'} — ${echecs} echec(s)`);
process.exit(echecs === 0 ? 0 : 1);
