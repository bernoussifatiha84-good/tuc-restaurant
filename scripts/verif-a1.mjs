#!/usr/bin/env node
/**
 * Preuve executable de A1 : l'intention sonore survit au rechargement.
 *
 * On extrait du VRAI `includes/vues/caisse.php` les fonctions qui decident
 * (sonVoulu, memoriserSonVoulu, majBandeauRearm) et on les fait tourner contre
 * un faux localStorage et un faux DOM. Aucun navigateur, aucun reseau.
 *
 * La regle prouvee : le bandeau n'apparait QUE si l'intention existe et que le
 * son n'est pas arme. Persister `son.actif` serait un mensonge — un AudioContext
 * exige un geste ; on persiste l'intention, pas l'etat.
 */
'use strict';

import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const VUE = path.join(__dirname, '..', 'wordpress', 'tuc-market-core', 'includes', 'vues', 'caisse.php');
const html = fs.readFileSync(VUE, 'utf8');

function extraire(depuis, jusqua) {
  const debut = html.indexOf(depuis);
  if (debut === -1) throw new Error(`${depuis} introuvable dans la vue caisse`);
  if (!jusqua) return html.slice(debut, debut + 400);
  const fin = html.indexOf(jusqua, debut);
  return html.slice(debut, fin);
}

const source = extraire('const CLE_SON', '/** Arme le son pour de vrai');

// basculerSon : c'est lui qui decide d'oublier l'intention quand on coupe
const iBasculer = html.indexOf('async function basculerSon');
const sourceBasculer = html.slice(iBasculer, html.indexOf('async function api(', iBasculer));

// le faux DOM : juste ce que la vue utilise ($ et son.actif)
let memoire = {};
const localStorage = {
  getItem: (k) => (k in memoire ? memoire[k] : null),
  setItem: (k, v) => { memoire[k] = String(v); },
};
const bandeau = { hidden: true };
const boutonSon = { disabled: false };
const $ = (sel) => ({ '#bandeau-rearm': bandeau, '#btn-son': boutonSon }[sel] ?? null);
// un seul objet, jamais remplace : la vue le capture par reference
const son = { actif: false };
const reinitSon = (actif, context) => {
  for (const k of Object.keys(son)) delete son[k];
  Object.assign(son, { actif }, context ? { context } : {});
};

const majBoutonSon = () => {};
const toast = () => {};
const fabrique = (ls, $f, s) => new Function('localStorage', '$', 'son', 'majBoutonSon', 'toast',
  `${source}\n${sourceBasculer}\n return { sonVoulu, memoriserSonVoulu, majBandeauRearm, basculerSon };`)(ls, $f, s, majBoutonSon, toast);
const { sonVoulu, memoriserSonVoulu, majBandeauRearm, basculerSon } =
  fabrique(localStorage, $, son, majBoutonSon, toast);

let echecs = 0;
const enAttente = [];
function cas(nom, fn) {
  const r = (() => { try { return fn(); } catch (e) { return e; } })();
  if (r && typeof r.then === 'function') {
    enAttente.push(r.then(
      () => console.log(`  OK   ${nom}`),
      (e) => { console.log(`  ECHEC ${nom} — ${e.message}`); echecs++; }));
    return;
  }
  if (r instanceof Error) { console.log(`  ECHEC ${nom} — ${r.message}`); echecs++; return; }
  console.log(`  OK   ${nom}`);
}

console.log('A1 — le son ne se perd plus au rechargement');

cas('sans rien de memorise, le bandeau reste cache', () => {
  memoire = {}; son.actif = false; bandeau.hidden = true;
  majBandeauRearm();
  assert.equal(sonVoulu(), false);
  assert.equal(bandeau.hidden, true);
});

cas('intention memorisee + son non arme => bandeau visible', () => {
  memoire = {}; son.actif = false; bandeau.hidden = true;
  memoriserSonVoulu(true);
  assert.equal(localStorage.getItem('tuc_caisse_son_voulu'), '1');
  majBandeauRearm();
  assert.equal(bandeau.hidden, false, 'le bandeau doit inviter a rearmer');
});

cas('intention memorisee + son arme => bandeau cache', () => {
  memoire = {}; son.actif = true; bandeau.hidden = false;
  memoriserSonVoulu(true);
  majBandeauRearm();
  assert.equal(bandeau.hidden, true);
});

cas('couper le son oublie l\'intention (sinon le bandeau reviendrait apres F5)', () => {
  memoire = {}; son.actif = false; bandeau.hidden = true;
  memoriserSonVoulu(true);
  memoriserSonVoulu(false);
  assert.equal(sonVoulu(), false);
  majBandeauRearm();
  assert.equal(bandeau.hidden, true);
});

cas('couper le son depuis le bouton oublie l\'intention', async () => {
  memoire = {}; reinitSon(true, { suspend: async () => {} });
  memoriserSonVoulu(true);
  await basculerSon();
  assert.equal(son.actif, false);
  assert.equal(sonVoulu(), false, 'couper = oublier l\'intention, sinon le bandeau revient apres F5');
});

cas('un localStorage qui jette (navigation privee) ne casse pas la caisse', () => {
  const quiJette = { getItem() { throw new Error('QuotaExceeded'); }, setItem() { throw new Error('QuotaExceeded'); } };
  const f = new Function('localStorage', '$', 'son', `${source}\n return { sonVoulu, memoriserSonVoulu, majBandeauRearm };`);
  const z = f(quiJette, $, { actif: false });
  assert.equal(z.sonVoulu(), false, 'par defaut on ne pretend pas que le son est voulu');
  z.memoriserSonVoulu(true); // ne doit pas lever
});

cas('la vue ne persiste PAS l\'etat du son, seulement l\'intention', () => {
  assert.ok(!/localStorage\.setItem\([^)]*son\.actif/.test(html), 'son.actif ne doit pas etre serialise');
  assert.ok(/CLE_SON\s*=\s*'tuc_caisse_son_voulu'/.test(html));
});

cas('le rearmement se declenche au premier geste, une seule fois', () => {
  const i = html.indexOf('const rearmementAuto');
  const bloc = html.slice(i, html.indexOf('if (!etat.alerteSonore)', i));
  assert.ok(/armerSon\(true\)/.test(bloc), 'le geste doit armer le son');
  assert.ok((bloc.match(/once:\s*true/g) || []).length === 2,
    'les deux gestes (pointerdown ET keydown) doivent s\'auto-retirer');
  // et les deux doivent etre cables : un seul des deux laisserait la caisse sourde au clavier
  assert.ok(/addEventListener\('pointerdown'/.test(bloc) && /addEventListener\('keydown'/.test(bloc));
});

await Promise.all(enAttente);
console.log();
if (echecs) { console.log(`ECHEC — ${echecs} cas rouge(s)`); process.exit(1); }
console.log(`OK — ${8} cas verts`);
