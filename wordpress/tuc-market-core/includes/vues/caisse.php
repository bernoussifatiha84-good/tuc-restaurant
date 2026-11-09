<?php
/**
 * Vue /caisse/ — dashboard de la caisse (PWA).
 *
 * Ce fichier est inclus par TUC_Caisse::servir_page(), qui a déjà envoyé les
 * en-têtes. Variables disponibles : $connecte (bool).
 *
 * Parti pris d'affichage, dicté par l'usage réel : la caisse ne lit pas une
 * page, elle JETTE un œil entre deux clients. Donc :
 *   - une commande = une carte pleine largeur, total en très gros ;
 *   - les boutons d'action ne sont jamais plus petits que 48 px de haut ;
 *   - ce qui est urgent passe en haut et clignote une seule fois, pas en boucle ;
 *   - aucune couleur décorative : le rouge ne sert qu'à « refuser », le teal
 *     qu'à « passer à l'étape suivante ».
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$tuc_base  = TUC_Caisse::base();
$tuc_asset = $tuc_base . '/caisse';
$tuc_resto = $connecte ? TUC_Caisse::mon_resto() : null;
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0E9E7E">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>">
<title>Caisse<?php echo $tuc_resto ? ' · ' . esc_html($tuc_resto->post_title) : '' ; ?> — TUC</title>
<link rel="manifest" href="<?php echo esc_url($tuc_asset . '/manifest.webmanifest'); ?>">
<link rel="icon" href="<?php echo esc_url($tuc_asset . '/icone-192.png'); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url($tuc_asset . '/icone-192.png'); ?>">
<style>
/* ---------- polices, servies par le plugin (aucun CDN) ---------- */
@font-face{font-family:Manrope;src:url(<?php echo esc_url($tuc_asset); ?>/fonts/manrope.woff2) format('woff2');
  font-weight:200 800;font-display:swap}
@font-face{font-family:PlexAr;src:url(<?php echo esc_url($tuc_asset); ?>/fonts/plexar-400.woff2) format('woff2');
  font-weight:400;font-display:swap}
@font-face{font-family:PlexAr;src:url(<?php echo esc_url($tuc_asset); ?>/fonts/plexar-700.woff2) format('woff2');
  font-weight:700 800;font-display:swap}

/* ---------- design system V2, mêmes jetons que le thème ---------- */
:root{
  --teal:#0E9E7E; --teal-d:#0A8166; --teal-l:#E8F7F3;
  --amber:#FFC244; --amber-d:#F0A81B;
  --ink:#1A1A1A; --g800:#3D3D3D; --g600:#767676; --g500:#9A9A9A;
  --g300:#DCDCDC; --g200:#EDEDED; --g100:#F7F7F7; --red:#E8003F;
  --blanc:#FFFFFF;
  --ff:Manrope,PlexAr,system-ui,-apple-system,sans-serif;
  --r:16px; --r-s:10px;
  --ombre:0 1px 2px rgba(0,0,0,.05),0 4px 16px rgba(0,0,0,.05);
  --safe-b:env(safe-area-inset-bottom,0px);
}
html[dir=rtl]{--ff:PlexAr,Manrope,system-ui,sans-serif}
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:var(--blanc);color:var(--ink);font-family:var(--ff);font-size:14px;
  font-weight:500;line-height:1.45;-webkit-font-smoothing:antialiased;
  overscroll-behavior-y:contain}
button{font:inherit;color:inherit;border:0;background:none;cursor:pointer;padding:0;
  text-align:start;-webkit-tap-highlight-color:transparent}
a{color:inherit;text-decoration:none}
svg{display:block;flex:none}
[hidden]{display:none!important}

/* ---------- structure ---------- */
.app{min-height:100dvh;display:flex;flex-direction:column;max-width:720px;margin:0 auto;
  background:var(--blanc)}

/* ---------- entête ---------- */
.tete{position:sticky;top:0;z-index:20;background:var(--blanc);
  border-bottom:1px solid var(--g200);padding:calc(10px + env(safe-area-inset-top,0px)) 16px 0}
.tete-l{display:flex;align-items:center;gap:10px;min-height:44px;min-width:0}
.marque{display:flex;align-items:flex-start;gap:2px;font-size:20px;font-weight:800;
  letter-spacing:-.8px;color:var(--ink);line-height:1}
.marque b{color:var(--teal)}
.marque .p{width:5px;height:5px;border-radius:50%;background:var(--amber);margin-top:3px}
.tete-nom{font-size:16px;font-weight:800;letter-spacing:-.3px;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap;min-width:0}
.tete-sp{margin-inline-start:auto;display:flex;align-items:center;gap:8px}

/* état de connexion : un point, c'est tout */
.etat{display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 10px;
  border-radius:99px;background:var(--g100);font-size:11px;font-weight:800;
  text-transform:uppercase;letter-spacing:.4px;color:var(--g600)}
.etat i{width:7px;height:7px;border-radius:50%;background:var(--g500);display:block}
.etat.on{background:var(--teal-l);color:var(--teal-d)}
.etat.on i{background:var(--teal)}
.etat.off{background:#FFF0F3;color:var(--red)}
.etat.off i{background:var(--red)}

/* interrupteur service */
.serv{display:flex;align-items:center;gap:8px;height:34px;padding:0 12px 0 14px;
  border-radius:99px;background:var(--g100);font-size:12.5px;font-weight:800}
.serv i{width:30px;height:18px;border-radius:99px;background:var(--g300);position:relative;
  display:block;transition:background .18s}
.serv i::after{content:"";position:absolute;top:2px;left:2px;width:14px;height:14px;
  border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:left .18s}
.serv.ouvert{background:var(--teal-l);color:var(--teal-d)}
.serv.ouvert i{background:var(--teal)}
.serv.ouvert i::after{left:14px}
.serv.ferme{background:#FFF0F3;color:var(--red)}
.serv.ferme i{background:var(--red)}

/* onglets — une seule ligne, jamais de retour à la ligne */
.onglets{display:flex;gap:8px;overflow-x:auto;scrollbar-width:none;
  padding:12px 0 10px;margin:0 -16px;padding-inline:16px}
.onglets::-webkit-scrollbar{display:none}
.ong{flex:none;height:36px;padding:0 14px;border-radius:99px;background:var(--g100);
  font-size:13px;font-weight:800;display:flex;align-items:center;gap:7px;color:var(--g800)}
.ong b{font-size:11.5px;font-weight:800;min-width:18px;height:18px;padding:0 5px;
  border-radius:99px;background:var(--blanc);color:var(--ink);display:flex;
  align-items:center;justify-content:center}
.ong[aria-selected=true]{background:var(--ink);color:var(--blanc)}
.ong[aria-selected=true] b{background:var(--amber);color:var(--ink)}

/* ---------- liste ---------- */
/* min-width:0 est indispensable : sans lui, un flex-item refuse de rétrécir
   sous la largeur de son contenu et pousse toute la page en débordement
   horizontal. C'est ce qui cassait la carte sur un écran de 390 px. */
.liste{flex:1;min-width:0;padding:14px 16px calc(24px + var(--safe-b));display:flex;
  flex-direction:column;gap:12px}

.carte{border:1px solid var(--g200);border-radius:var(--r);background:var(--blanc);
  overflow:hidden;box-shadow:var(--ombre);animation:entre .22s ease-out;min-width:0}
@keyframes entre{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.carte.neuve{border-color:var(--teal);box-shadow:0 0 0 3px var(--teal-l),var(--ombre)}
.carte.refusee{opacity:.62}

.c-tete{display:flex;align-items:center;gap:10px;padding:12px 14px 10px;
  border-bottom:1px solid var(--g100)}
.c-ou{font-size:16px;font-weight:800;letter-spacing:-.3px}
.c-heure{font-size:11.5px;font-weight:800;color:var(--g500);margin-inline-start:auto;
  font-variant-numeric:tabular-nums}
.c-age{font-size:11px;font-weight:800;padding:3px 8px;border-radius:99px;
  background:var(--g100);color:var(--g600);font-variant-numeric:tabular-nums}
.c-age.vif{background:#FFF6E3;color:#8A5A00}
.c-age.tard{background:#FFF0F3;color:var(--red)}

.c-lignes{padding:10px 14px 4px;display:flex;flex-direction:column;gap:7px}
.c-contact{display:grid;gap:8px;margin:9px 14px 2px;padding:10px 11px;border-radius:12px;background:#f1f6f1;border:1px solid var(--g200);font-size:12px}
.c-contact strong{color:var(--g800)}
.c-contact-actions{display:flex;flex-wrap:wrap;gap:7px}
.c-contact-actions a,.c-contact-actions button{min-height:36px;display:inline-flex;align-items:center;justify-content:center;padding:0 10px;border:1px solid var(--g200);border-radius:9px;background:#fff;color:var(--g800);font:700 11px system-ui,sans-serif;text-decoration:none}
.c-contact-actions button{background:var(--teal-d);color:#fff;border-color:var(--teal-d)}
.lig{display:flex;align-items:baseline;gap:9px;font-size:13.5px}
.lig .q{font-weight:800;color:var(--teal-d);font-variant-numeric:tabular-nums;
  min-width:24px}
.lig .n{flex:1;font-weight:600;min-width:0;overflow-wrap:anywhere}
.lig .p{font-weight:800;font-variant-numeric:tabular-nums;color:var(--g800);
  white-space:nowrap;flex:none}
.lig .opt{display:block;font-size:11.5px;font-weight:700;color:var(--g600);
  margin-top:1px}
.c-plus{font-size:11.5px;font-weight:700;color:var(--g500);padding:2px 14px 0;
  padding-inline-start:47px}

.c-tot{display:flex;align-items:flex-end;gap:8px;padding:10px 14px 12px}
.c-tot .t{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;
  color:var(--g500);padding-bottom:6px}
.c-tot .v{margin-inline-start:auto;font-size:26px;font-weight:800;letter-spacing:-1px;
  font-variant-numeric:tabular-nums;line-height:1;white-space:nowrap}
.c-tot .v span{font-size:12.5px;font-weight:800;color:var(--g600);letter-spacing:0;
  margin-inline-start:3px}

/* actions — 48 px mini, jamais collées */
.c-act{display:flex;gap:8px;padding:0 14px 14px}
.btn{flex:1;min-height:48px;border-radius:12px;display:flex;align-items:center;
  justify-content:center;gap:8px;font-size:14.5px;font-weight:800;
  transition:transform .1s,background .15s}
.btn:active{transform:scale(.985)}
.btn-prim{background:var(--teal);color:#fff;box-shadow:0 2px 10px rgba(14,158,126,.28)}
.btn-prim:hover{background:var(--teal-d)}
.btn-sec{background:var(--g100);color:var(--ink)}
.btn-sec:hover{background:var(--g200)}
.ref{align-self:center;flex:none;padding:0 0 0 10px;min-height:48px;display:flex;
  align-items:center;font-size:13px;font-weight:800;color:var(--g500);
  text-decoration:underline;text-underline-offset:3px}
.ref:hover{color:var(--red)}
.ref.confirme{color:var(--red);font-size:12.5px;text-align:end;text-decoration:none;
  background:#FFF0F3;border-radius:10px;padding:0 12px}
.btn:disabled{opacity:.45;pointer-events:none}

/* ---------- vide ---------- */
.vide{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  padding:48px 24px;text-align:center;gap:14px;color:var(--g600)}
.vide .ic{width:76px;height:76px;border-radius:50%;background:var(--teal-l);
  display:flex;align-items:center;justify-content:center;color:var(--teal-d)}
.vide h2{margin:0;font-size:17px;font-weight:800;color:var(--ink)}
.vide p{margin:0;font-size:13.5px;max-width:280px;line-height:1.5}

/* ---------- bandeau push ---------- */
.push{margin:12px 16px 0;padding:13px 14px;border-radius:var(--r);background:var(--teal-l);
  display:flex;gap:11px;align-items:flex-start}
.push .ic{color:var(--teal-d);margin-top:1px}
.push .tx{flex:1;min-width:0}
.push h3{margin:0 0 2px;font-size:13.5px;font-weight:800;color:var(--ink)}
.push p{margin:0;font-size:12.5px;color:var(--g800);line-height:1.45}
.push .go{margin-top:9px;height:40px;padding:0 16px;border-radius:10px;background:var(--teal);
  color:#fff;font-size:13.5px;font-weight:800;display:inline-flex;align-items:center;gap:7px}
.push .go[disabled]{opacity:.5}

.extrait{padding:9px 16px 0;font-size:11.5px;font-weight:700;color:var(--g500);
  display:flex;align-items:center;gap:7px}

/* ---------- connexion (le gérant n'est pas connecté) ---------- */
.connex{flex:1;display:flex;flex-direction:column;justify-content:center;padding:32px 24px}
.connex h1{font-size:24px;font-weight:800;letter-spacing:-.6px;margin:0 0 6px}
.connex p{margin:0 0 22px;color:var(--g600);font-size:14px}
.champ{display:block;margin-bottom:12px}
.champ span{display:block;font-size:12px;font-weight:800;color:var(--g600);
  margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px}
.champ input{width:100%;height:50px;padding:0 14px;border:1px solid var(--g300);
  border-radius:12px;background:var(--blanc);font-size:16px;font-weight:600}
.champ input:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px var(--teal-l)}
.connex .btn{width:100%}
.faux{background:var(--g100);color:var(--g800);border-radius:12px;padding:12px 14px;
  font-size:13px;line-height:1.5;margin-top:16px}

/* ---------- toast ---------- */
.toast{position:fixed;left:50%;bottom:calc(20px + var(--safe-b));transform:translateX(-50%) translateY(20px);
  background:var(--ink);color:#fff;font-size:13.5px;font-weight:700;padding:12px 18px;
  border-radius:99px;box-shadow:0 8px 24px rgba(0,0,0,.22);opacity:0;pointer-events:none;
  transition:opacity .18s,transform .18s;z-index:60;max-width:calc(100vw - 32px)}
.toast.on{opacity:1;transform:translateX(-50%) translateY(0)}

/* ---------- grand écran : deux colonnes, la caisse voit plus de commandes ---------- */
@media (min-width:900px){
  .app{max-width:1100px}
  .liste{flex-direction:row;flex-wrap:wrap;align-items:flex-start;gap:14px}
  .liste>.carte{flex:1 1 calc(50% - 14px);min-width:min(380px,100%)}
  .liste>.vide{flex:1 1 100%}
}
@media (prefers-reduced-motion:reduce){
  *{animation:none!important;transition:none!important}
}
</style>
</head>
<body>
<div class="app" id="app">

<?php if (!$connecte) : ?>
  <!-- ===================== PAS CONNECTÉ ===================== -->
  <div class="tete">
    <div class="tete-l">
      <span class="marque"><b>T</b>UC<span class="p"></span></span>
      <span class="tete-nom" style="color:var(--g600);font-weight:700">Caisse</span>
    </div>
  </div>

  <form class="connex" id="form-connexion" autocomplete="on"
        method="post" action="<?php echo esc_url(wp_login_url()); ?>">
    <input type="hidden" name="redirect_to" value="<?php echo esc_url(TUC_Caisse::url()); ?>">
    <input type="hidden" name="testcookie" value="1">
    <h1>Votre caisse</h1>
    <p>Connectez-vous une seule fois : ensuite, les commandes arrivent ici, même écran éteint.</p>

    <label class="champ"><span>Identifiant</span>
      <input type="text" name="log" id="log" autocomplete="username" required
             inputmode="text" autocapitalize="none" spellcheck="false"></label>
    <label class="champ"><span>Mot de passe</span>
      <input type="password" name="pwd" id="pwd" autocomplete="current-password" required></label>

    <button class="btn btn-prim" type="submit" id="btn-connexion" style="width:100%">
      Ouvrir la caisse
    </button>

    <div class="faux" id="connex-note">
      Pas encore de mot de passe ? <a href="<?php echo esc_url(wp_lostpassword_url($tuc_asset . '/')); ?>"
        style="color:var(--teal-d);font-weight:800">Réinitialiser</a>.
    </div>
  </form>

<?php else : ?>
  <!-- ===================== LA CAISSE ===================== -->
  <header class="tete">
    <div class="tete-l">
      <span class="marque"><b>T</b>UC<span class="p"></span></span>
      <span class="tete-nom" id="nom-resto"><?php echo $tuc_resto ? esc_html($tuc_resto->post_title) : 'Caisse'; ?></span>
      <span class="tete-sp">
        <span class="etat" id="etat"><i></i><span id="etat-txt">…</span></span>
      </span>
    </div>

    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:10px 0 12px">
      <button class="serv" id="serv" type="button" aria-pressed="true">
        <i></i><span id="serv-txt">Service ouvert</span>
      </button>
      <button class="etat" id="btn-son" type="button" aria-pressed="false" title="Activer le bip et la vibration des commandes à table" style="height:34px;padding:0 10px" <?php echo !$tuc_resto || !TUC_Caisse::profil_alerte(get_current_user_id(), 'table', $tuc_resto->ID)['audible'] ? 'hidden' : ''; ?>>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H3v6h3l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/></svg>
        <span id="btn-son-txt">Bip + vibreur</span>
      </button>
      <span id="son-status" role="status" aria-live="polite" style="position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)">Son désactivé</span>
      <button class="etat" id="btn-push" type="button" style="margin-inline-start:auto;height:34px;padding:0 12px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
        <span id="btn-push-txt">Alertes</span>
      </button>
    </div>

    <nav class="onglets" id="onglets" role="tablist">
      <button class="ong" role="tab" data-statut="nouvelle" aria-selected="true">
        Nouvelles <b id="n-nouvelle">0</b>
      </button>
      <button class="ong" role="tab" data-statut="acceptee" aria-selected="false">
        Acceptées <b id="n-acceptee">0</b>
      </button>
      <button class="ong" role="tab" data-statut="preparation" aria-selected="false">
        En cuisine <b id="n-preparation">0</b>
      </button>
      <button class="ong" role="tab" data-statut="prete" aria-selected="false">
        Prêtes <b id="n-prete">0</b>
      </button>
      <button class="ong" role="tab" data-statut="toutes" aria-selected="false">
        Tout <b id="n-toutes">0</b>
      </button>
    </nav>
  </header>

  <!-- bandeau push, masqué dès que l'abonnement est actif -->
  <div class="push" id="bandeau-push" hidden>
    <span class="ic">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
    </span>
    <div class="tx">
      <h3>Activez les alertes</h3>
      <p id="push-txt">Sans ça, vous ne verrez les commandes que si cet écran est ouvert.</p>
      <small style="display:block;margin-top:6px;color:var(--g600)">En arrière-plan, le son dépend aussi du volume, du mode silencieux et des réglages de notifications de ce téléphone. Activez et testez le bouton « Son » en caisse.</small>
      <button class="go" id="btn-activer" type="button">
        Activer les alertes
      </button>
    </div>
  </div>

  <div class="extrait" id="extrait" hidden>
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M20 6 9 17l-5-5"/></svg>
    <span id="extrait-txt">Alertes actives sur cet appareil</span>
  </div>

  <main class="liste" id="liste" aria-live="polite"></main>

  <div class="vide" id="vide" hidden>
    <div class="ic">
      <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11h18"/><path d="M5 11V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v4"/><path d="M4 11v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/></svg>
    </div>
    <h2 id="vide-titre">Aucune commande pour l'instant</h2>
    <p id="vide-txt">Laissez cet écran ouvert. Dès qu'un client commande à sa table, la commande apparaît ici avec une alerte.</p>
  </div>
<?php endif; ?>

</div>
<div class="toast" id="toast" role="status"></div>

<?php if ($connecte) : ?>
<script>
(() => {
  'use strict';

  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const BASE = <?php echo wp_json_encode($tuc_asset); ?>;
  const API  = <?php echo wp_json_encode(rest_url('tuc/v1/')); ?>;
  const NONCE = $('meta[name=csrf-token]').content;
  const A_JOUR = {};

  const etat = {
    statut: 'nouvelle',
    commandes: [],
    vapid: null,
    abonne: false,
    minuteur: null,
    minuteurAge: null,
    baseline: false,
    idsConnus: new Set(),
    // instant SERVEUR du premier releve abouti, et fenetre couverte par la
    // reprise. Une commande 'nouvelle' creee apres (t0 - FENETRE_DE_REPRISE)
    // doit sonner meme si ce premier releve est le tout premier.
    t0: 0,
    alerteSonore: false,
    envoi: false,
  };
  const FENETRE_DE_REPRISE = 120; // secondes
  const son = { context: null, actif: false };

  /* ---------------- outils ---------------- */
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const mad = (v) => Number(v || 0).toLocaleString('fr-MA', { maximumFractionDigits: 0 }) + ' MAD';

  function toast(txt) {
    const t = $('#toast');
    t.textContent = txt;
    t.classList.add('on');
    clearTimeout(t._h);
    t._h = setTimeout(() => t.classList.remove('on'), 2600);
  }

  function majBoutonSon(actif, message) {
    const bouton = $('#btn-son');
    if (bouton) {
      bouton.setAttribute('aria-pressed', String(actif));
      $('#btn-son-txt').textContent = actif ? 'Alertes actives' : 'Bip + vibreur';
    }
    $('#son-status').textContent = message;
  }

  function jouerSignalSonore() {
    if (!etat.alerteSonore || !son.actif || !son.context || son.context.state !== 'running') return false;
    const debut = son.context.currentTime;
    [0, 0.2, 0.4].forEach((retard, index) => {
      const oscillateur = son.context.createOscillator();
      const volume = son.context.createGain();
      oscillateur.type = 'sine';
      oscillateur.frequency.setValueAtTime(index === 1 ? 880 : 660, debut + retard);
      volume.gain.setValueAtTime(0.0001, debut + retard);
      volume.gain.exponentialRampToValueAtTime(0.18, debut + retard + 0.015);
      volume.gain.exponentialRampToValueAtTime(0.0001, debut + retard + 0.16);
      oscillateur.connect(volume);
      volume.connect(son.context.destination);
      oscillateur.start(debut + retard);
      oscillateur.stop(debut + retard + 0.17);
    });
    majBoutonSon(true, 'Alerte sonore jouée pour une nouvelle commande.');
    return true;
  }

  async function basculerSon() {
    const bouton = $('#btn-son');
    bouton.disabled = true;
    try {
      if (son.actif) {
        await son.context.suspend();
        son.actif = false;
        majBoutonSon(false, 'Son désactivé.');
      } else {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) throw new Error('Audio non pris en charge par ce navigateur.');
        son.context = son.context || new AudioContextClass();
        await son.context.resume();
        son.actif = son.context.state === 'running';
        if (!son.actif) throw new Error('Autorisez le son sur cet appareil puis réessayez.');
        majBoutonSon(true, 'Bip et vibration activés; signal de test envoyé.');
        if (navigator.vibrate) navigator.vibrate([90, 50, 90]);
        jouerSignalSonore();
        toast('Bip + vibration activés sur cet appareil');
      }
    } catch (e) {
      son.actif = false;
      majBoutonSon(false, e.message || 'Impossible d’activer le son.');
      toast(e.message || 'Impossible d’activer le son');
    } finally {
      bouton.disabled = false;
    }
  }

  async function api(chemin, options = {}) {
    const r = await fetch(API + chemin, {
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
      ...options,
    });
    let d = null;
    try { d = await r.json(); } catch (_) {}
    if (r.status === 401 || r.status === 403) throw Object.assign(new Error('session'), { code: 401 });
    if (!r.ok) throw Object.assign(new Error((d && d.erreur) || 'Erreur ' + r.status), { code: r.status, d });
    return d;
  }

  /* ---------------- rendu ---------------- */
  function ageClasse(s) {
    if (s > 8 * 60) return 'tard';
    if (s > 3 * 60) return 'vif';
    return '';
  }

  function ageTexte(s) {
    if (s < 60) return 'à l\'instant';
    const m = Math.floor(s / 60);
    return m + ' min';
  }

  async function partagerCommande(button) {
    const text = button.dataset.shareText || '';
    if (!text) return;
    try {
      if (navigator.share) {
        await navigator.share({ title: 'Livraison TUC', text });
      } else {
        window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener');
      }
    } catch (error) {
      if (error.name !== 'AbortError') toast('Le partage n’a pas pu être ouvert.');
    }
  }

  function carte(c) {
    const lignes = (c.items || []).slice(0, 4).map((l) => {
      const opts = (l.opts || []).filter(Boolean);
      return `<div class="lig">
        <span class="q">${esc(l.q)}×</span>
        <span class="n">${esc(l.nom)}${opts.length
          ? `<span class="opt">${opts.map((o) => esc(o)).join(' · ')}</span>`
          : ''}</span>
        <span class="p">${Math.round((l.pu || 0) * (l.q || 1))}</span>
      </div>`;
    }).join('');

    const reste = (c.items || []).length - 4;
    const plus = reste > 0 ? `<div class="c-plus">+ ${reste} autre${reste > 1 ? 's' : ''}</div>` : '';

    const ou = c.mode === 'table' ? 'Table ' + c.table
      : c.mode === 'livraison' ? 'Livraison' : 'À emporter';
    const phoneDigits = String(c.tel || '').replace(/[^0-9]/g, '');
    const hasLocation = c.mode === 'livraison' && c.lat !== null && c.lng !== null && Number.isFinite(Number(c.lat)) && Number.isFinite(Number(c.lng));
    const mapUrl = hasLocation ? (c.location_url || `https://maps.google.com/?q=${c.lat},${c.lng}`) : '';
    const shareText = [`Livraison TUC #${c.id}`, `Téléphone : +${phoneDigits}`, `Localisation : ${mapUrl}`].join(String.fromCharCode(10));
    const contact = c.mode === 'livraison' && phoneDigits ? `<div class="c-contact">
      <strong>Contact client · +${esc(phoneDigits)}</strong>
      <div class="c-contact-actions">
        <a href="tel:+${esc(phoneDigits)}">Appeler</a>
        <a href="https://wa.me/${esc(phoneDigits)}?text=${encodeURIComponent(`Bonjour, votre commande #${c.id} est en cours de livraison.`)}" target="_blank" rel="noopener noreferrer">WhatsApp</a>
        ${hasLocation ? `<a href="${esc(mapUrl)}" target="_blank" rel="noopener noreferrer">Voir la position</a><button type="button" data-share-text="${esc(shareText)}" aria-label="Partager le téléphone et la position avec le livreur">Partager au livreur</button>` : '<span>Position non fournie</span>'}
      </div>
    </div>` : '';

    // une seule action principale, selon l'étape — pas de menu à 6 boutons
    const suite = {
      nouvelle:    { statut: 'acceptee',    txt: 'Accepter' },
      acceptee:    { statut: 'preparation', txt: 'En cuisine' },
      preparation: { statut: 'prete',       txt: 'C\'est prêt' },
      prete:       { statut: 'servie',      txt: 'Servie' },
    }[c.statut];

    // Refuser n'est PAS une action principale : une croix seule à côté d'un
    // gros bouton vert se tape par erreur en plein service. C'est donc un
    // lien discret, à l'écart, et qui demande confirmation.
    const refus = (c.statut === 'nouvelle' || c.statut === 'acceptee')
      ? `<button class="ref" data-ref="${c.id}">Refuser</button>` : '';

    const age = c.age + 's';

    return `<article class="carte ${c.statut === 'nouvelle' ? 'neuve' : ''} ${c.statut === 'servie' || c.statut === 'refusee' || c.statut === 'annulee' ? 'refusee' : ''}"
        data-id="${c.id}" data-age="${c.age}">
      <div class="c-tete">
        <span class="c-ou">${esc(ou)}</span>
        <span class="c-age ${ageClasse(c.age)}" data-age-txt>${esc(ageTexte(c.age))}</span>
        <span class="c-heure">${esc(c.heure)}</span>
      </div>
      ${contact}
      <div class="c-lignes">${lignes}</div>
      ${plus}
      <div class="c-tot">
        <span class="t">Total</span>
        <span class="v">${Number(c.total).toFixed(0)}<span>MAD</span></span>
      </div>
      ${suite ? `<div class="c-act">
        <button class="btn btn-prim" data-avance="${c.id}" data-vers="${suite.statut}">
          ${suite.txt}
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
        ${refus}
      </div>` : `<div class="c-act">
        <span class="btn btn-sec" style="pointer-events:none;color:var(--g500)">
          Terminée · à encaisser
        </span>
      </div>`}
    </article>`;
  }

  function rendre() {
    const liste = $('#liste');
    const vide = $('#vide');
    const cs = etat.commandes;

    if (!cs.length) {
      liste.innerHTML = '';
      liste.hidden = true;
      vide.hidden = false;
      const fini = ['acceptee', 'preparation', 'prete'];
      $('#vide-titre').textContent = etat.statut === 'nouvelle'
        ? 'Aucune nouvelle commande'
        : 'Rien dans « ' + ($$('.ong').find((o) => o.dataset.statut === etat.statut)?.textContent.trim().split(' ')[0] || etat.statut) + ' »';
      $('#vide-txt').textContent = etat.statut === 'nouvelle'
        ? 'Laissez cet écran ouvert. Dès qu\'un client commande, elle apparaît ici avec une alerte.'
        : 'Les commandes passent ici au fil du service.';
      return;
    }

    vide.hidden = true;
    liste.hidden = false;
    liste.innerHTML = cs.map(carte).join('');
  }

  /* ---------------- chargement ---------------- */
  async function charger(silencieux = false) {
    if (etat.envoi) return;
    try {
      const d = await api('caisse/commandes?statut=' + encodeURIComponent(etat.statut));
      etat.commandes = d.commandes || [];

      // total par onglet : une seule requête suffit si on compte côté serveur,
      // mais on reste simple : les compteurs viennent de « toutes »
      if (etat.statut === 'toutes') {
        const par = { nouvelle: 0, acceptee: 0, preparation: 0, prete: 0, toutes: etat.commandes.length };
        etat.commandes.forEach((c) => { if (par[c.statut] !== undefined) par[c.statut]++; });
        Object.keys(par).forEach((k) => {
          const el = $('#n-' + k);
          if (el) el.textContent = par[k] || 0;
        });
      }

      rendre();
      direEtat(true);
    } catch (e) {
      if (e.code === 401) { location.reload(); return; }
      direEtat(false);
      if (!silencieux) toast(e.message);
    }
  }

  async function chargerCompteurs() {
    try {
      const d = await api('caisse/commandes?statut=toutes');
      const par = { nouvelle: 0, acceptee: 0, preparation: 0, prete: 0, toutes: d.nb || 0, servie: 0 };
      (d.commandes || []).forEach((c) => { if (par[c.statut] !== undefined) par[c.statut]++; });
      Object.keys(par).forEach((k) => {
        const el = $('#n-' + k);
        if (el) el.textContent = par[k] || 0;
      });

      // Alerte d'arrivée calculée sur la liste COMPLÈTE, donc quel que soit
      // l'onglet affiché et quel que soit le mode. Décision propriétaire : la
      // cuisine et le patron entendent toute nouvelle commande. La baseline est
      // posée au premier relevé complet pour ne pas sonner sur l'existant.
      const toutes = d.commandes || [];

      // Instant serveur : l'horloge d'une tablette de comptoir peut deriver de
      // plusieurs minutes. On ne compare qu'avec la reference du serveur.
      const maintenant = Number(d.maintenant) || Math.floor(Date.now() / 1000);
      if (!etat.t0) etat.t0 = maintenant;

      // Une commande "arrivante" = jamais presentee ET creee dans la fenetre
      // que couvre cette session. C'est ce qui rattrape les commandes tombees
      // pendant un rechargement : au tout premier releve, la baseline n'est
      // pas encore posee, mais la fenetre, si.
      const fenetreDebut = etat.t0 - FENETRE_DE_REPRISE;
      const dansFenetre = (c) => {
        const cree = Number(c.cree);
        if (!cree) return false;                 // serveur ancien : pas de 'cree'
        return cree >= fenetreDebut;
      };
      const arrivantes = toutes.filter(
        (c) => !etat.idsConnus.has(Number(c.id)) && dansFenetre(c)
      );
      // On n'alerte que sur des commandes encore a prendre : une commande deja
      // acceptee ne doit pas sonner, meme si la page ne l'a jamais vue.
      const aSignaler = arrivantes.filter((c) => c.statut === 'nouvelle');

      if (aSignaler.length) {
        const fraiche = aSignaler[0];
        const ou = fraiche.mode === 'table'
          ? 'Table ' + fraiche.table
          : (fraiche.mode === 'livraison' ? 'Livraison' : 'Emporter');
        if (etat.alerteSonore && son.actif) {
          if (navigator.vibrate) navigator.vibrate([120, 60, 120, 60, 220]);
          jouerSignalSonore();
          toast('Nouvelle commande · ' + ou);
        } else {
          toast('Nouvelle commande · ' + ou + ' — copie silencieuse');
        }
        if (aSignaler.length > 1) {
          toast(aSignaler.length + ' nouvelles commandes · ' + ou);
        }
      }
      toutes.forEach((c) => etat.idsConnus.add(Number(c.id)));
      etat.baseline = true;

      if (etat.statut !== 'toutes') {
        const courantes = (d.commandes || []).filter((c) => c.statut === etat.statut);
        etat.commandes = courantes;
        rendre();
      }
      return par;
    } catch (e) {
      if (e.code === 401) location.reload();
      return null;
    }
  }

  /* ---------------- état de connexion ---------------- */
  function direEtat(on) {
    const e = $('#etat');
    const t = $('#etat-txt');
    if (!e) return;
    e.classList.toggle('on', !!on);
    e.classList.toggle('off', !on);
    t.textContent = on ? 'En ligne' : 'Hors ligne';
  }

  /* ---------------- actions ---------------- */
  /* Un refus ne se rattrape pas : on demande une deuxième fois. Une
     confirmation bloquante serait trop lourde en plein service, donc on
     transforme le lien en « Confirmer ? » pendant 4 secondes. */
  let refusEnAttente = null;

  async function demanderRefus(id, el) {
    if (refusEnAttente && refusEnAttente.id === id) {
      clearTimeout(refusEnAttente.minuteur);
      refusEnAttente = null;
      return changer(id, 'annulee', el);
    }
    if (refusEnAttente) {
      clearTimeout(refusEnAttente.minuteur);
      refusEnAttente.el.textContent = 'Refuser';
      refusEnAttente.el.classList.remove('confirme');
    }
    el.textContent = 'Confirmer le refus ?';
    el.classList.add('confirme');
    refusEnAttente = {
      id,
      el,
      minuteur: setTimeout(() => {
        el.textContent = 'Refuser';
        el.classList.remove('confirme');
        refusEnAttente = null;
      }, 4000),
    };
  }

  async function changer(id, vers, sourceEl) {
    const c = etat.commandes.find((x) => x.id === id);
    if (!c || c._envoi) return;
    c._envoi = true;
    if (sourceEl) sourceEl.disabled = true;

    // optimiste : la caisse a déjà bougé le papier, l'écran doit suivre
    const avant = c.statut;
    c.statut = vers;
    rendre();

    try {
      await api('caisse/statut', {
        method: 'POST',
        body: JSON.stringify({ id, statut: vers }),
      });
      if (vers === 'servie' || vers === 'refusee' || vers === 'annulee') {
        etat.commandes = etat.commandes.filter((x) => x.id !== id);
        rendre();
      }
      chargerCompteurs();
    } catch (e) {
      c.statut = avant;
      rendre();
      toast(e.message || 'Modification refusée');
    } finally {
      c._envoi = false;
    }
  }

  document.addEventListener('click', (e) => {
    const partage = e.target.closest('[data-share-text]');
    if (partage) { partagerCommande(partage); return; }

    const av = e.target.closest('[data-avance]');
    if (av) { changer(Number(av.dataset.avance), av.dataset.vers, av); return; }

    const rf = e.target.closest('[data-ref]');
    if (rf) { demanderRefus(Number(rf.dataset.ref), rf); return; }

    const ong = e.target.closest('.ong');
    if (ong) {
      $$('.ong').forEach((o) => o.setAttribute('aria-selected', String(o === ong)));
      etat.statut = ong.dataset.statut;
      charger();
      return;
    }

    const serv = e.target.closest('#serv');
    if (serv) { basculerService(serv); }
  });

  async function basculerService(el) {
    const ouvert = el.getAttribute('aria-pressed') === 'true';
    const versFerme = ouvert; // on inverse
    el.disabled = true;
    try {
      await api('caisse/ouverture', { method: 'POST', body: JSON.stringify({ ferme: versFerme }) });
      majService(!versFerme);
      toast(versFerme ? 'Service en pause — les commandes sont bloquées.' : 'Service ouvert.');
    } catch (e) {
      toast(e.message || 'Impossible de changer le service');
    }
    el.disabled = false;
  }

  function majService(ouvert) {
    const el = $('#serv');
    if (!el) return;
    el.classList.toggle('ouvert', ouvert);
    el.classList.toggle('ferme', !ouvert);
    el.setAttribute('aria-pressed', String(ouvert));
    $('#serv-txt').textContent = ouvert ? 'Service ouvert' : 'Service en pause';
  }

  /* ---------------- Web Push ---------------- */
  function b64urlVersUint8(s) {
    const pad = '='.repeat((4 - (s.length % 4)) % 4);
    const b = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(b, (c) => c.charCodeAt(0));
  }

  function supportePush() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  }

  async function initPush(vapid) {
    const bandeau = $('#bandeau-push');
    const extrait = $('#extrait');
    if (!bandeau) return;

    if (!supportePush()) {
      bandeau.hidden = false;
      $('#push-txt').textContent = 'Ce navigateur ne gère pas les alertes. Ouvrez la caisse dans Chrome ou Safari à jour.';
      $('#btn-activer').disabled = true;
      return;
    }

    if (!vapid) {
      // clés VAPID pas générées (pas de module openssl côté serveur ?)
      bandeau.hidden = false;
      $('#push-txt').textContent = 'Les alertes ne sont pas configurées sur ce site.';
      $('#btn-activer').disabled = true;
      return;
    }

    const reg = await navigator.serviceWorker.register(BASE + '/sw.js', { scope: BASE + '/' });
    await navigator.serviceWorker.ready;
    etat.reg = reg;

    const abo = await reg.pushManager.getSubscription();
    if (abo) {
      etat.abonne = true;
      await envoyerAbo(abo);
      bandeau.hidden = true;
      extrait.hidden = false;
      $('#extrait-txt').textContent = 'Alertes actives sur cet appareil';
      return;
    }

    if (Notification.permission === 'denied') {
      bandeau.hidden = false;
      $('#push-txt').textContent = 'Les alertes sont bloquées pour ce site. Autorisez-les dans les réglages du navigateur (icône cadenas).';
      $('#btn-activer').textContent = 'Comment faire ?';
      $('#btn-activer').onclick = () => toast('Réglages du navigateur → Notifications → Autoriser');
      return;
    }

    bandeau.hidden = false;
  }

  async function envoyerAbo(abo) {
    const j = abo.toJSON();
    try {
      await api('caisse/push', {
        method: 'POST',
        body: JSON.stringify({
          endpoint: j.endpoint,
          p256dh: j.keys.p256dh,
          auth: j.keys.auth,
        }),
      });
    } catch (e) {
      // l'abonnement local existe mais le serveur l'ignore : on ne bloque pas la caisse
      console.warn('push non enregistré', e);
    }
  }

  async function activerPush() {
    const btn = $('#btn-activer');
    if (!etat.reg) { await initPush(etat.vapid); return; }
    btn.disabled = true;
    btn.textContent = 'Activation…';
    try {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') {
        $('#push-txt').textContent = 'Sans autorisation, la caisse ne peut pas être prévenue. Vous verrez les commandes seulement si cet écran est ouvert.';
        btn.textContent = 'Réessayer';
        btn.disabled = false;
        return;
      }

      const abo = await etat.reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: b64urlVersUint8(etat.vapid),
      });
      await envoyerAbo(abo);

      etat.abonne = true;
      $('#bandeau-push').hidden = true;
      $('#extrait').hidden = false;
      toast('Alertes activées sur cet appareil');
    } catch (e) {
      toast('Activation impossible : ' + (e.message || 'erreur inconnue'));
      btn.textContent = 'Réessayer';
      btn.disabled = false;
    }
  }

  /* ---------------- âges qui défilent ---------------- */
  function rafraichirAges() {
    $$('[data-age]').forEach((el) => {
      const s = Number(el.dataset.age) + 1;
      el.dataset.age = s;
      const t = el.querySelector('[data-age-txt]');
      if (t) {
        t.textContent = ageTexte(s);
        t.className = 'c-age ' + ageClasse(s);
      }
    });
  }

  /* ---------------- démarrage ---------------- */
  async function demarrer() {
    try {
      const moi = await api('caisse/moi');
      etat.vapid = moi.vapid;
      etat.alerteSonore = moi.alerte_sonore === true;
      $('#btn-son').hidden = !etat.alerteSonore;
      if (!etat.alerteSonore) $('#son-status').textContent = 'Votre compte n’est pas rattaché à ce restaurant : alertes silencieuses.';
      majService(!(moi.resto && moi.resto.ferme));
      if (moi.resto && moi.resto.nom) $('#nom-resto').textContent = moi.resto.nom;

      $('#btn-son').addEventListener('click', basculerSon);
      await initPush(moi.vapid);
      const btnPush = $('#btn-push');
      if (btnPush) {
        btnPush.addEventListener('click', () => {
          if (etat.abonne) {
            toast('Alertes actives. Test envoyé…');
            api('caisse/push-test', { method: 'POST' }).then((r) => {
              if (!r.ok) toast('Le test n\'a pas abouti.');
            }).catch(() => {});
          } else {
            activerPush();
          }
        });
      }
      const ba = $('#btn-activer');
      if (ba) ba.addEventListener('click', activerPush);
    } catch (e) {
      if (e.code === 401) { location.reload(); return; }
      direEtat(false);
    }

    await chargerCompteurs();
    if (etat.statut !== 'toutes') await charger();

    // rafraîchissement : 20 s quand l'écran est visible, rien quand il est caché
    // (le push prend le relais, on ne gaspille pas la batterie pour rien)
    const boucle = () => {
      clearInterval(etat.minuteur);
      clearInterval(etat.minuteurAge);
      if (document.hidden) return;
      etat.minuteur = setInterval(() => {
        chargerCompteurs();
        if (etat.statut !== 'toutes') charger(true);
      }, 20000);
      etat.minuteurAge = setInterval(rafraichirAges, 1000);
    };
    boucle();
    document.addEventListener('visibilitychange', () => {
      boucle();
      if (!document.hidden) {
        chargerCompteurs();
        charger(true);
      }
    });

    // message du service worker : une commande vient d'arriver
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.addEventListener('message', (e) => {
        if (e.data && e.data.type === 'tuc-rafraichir') {
          chargerCompteurs();
          charger(true);
        }
      });
    }
  }

  demarrer();
})();
</script>
<?php endif; ?>


<?php if (!$connecte) : ?>
<script>
/* Formulaire de connexion. Volontairement autonome : si ce script ne se
   charge pas, le <form> retombe sur un POST vers wp-login.php. Un GET aurait
   mis le mot de passe dans la barre d'adresse et dans l'historique. */
(() => {
  'use strict';
  const form = document.getElementById('form-connexion');
  if (!form) return;
  const log = document.getElementById('log');
  const pwd = document.getElementById('pwd');
  const btn = document.getElementById('btn-connexion');
  const note = document.getElementById('connex-note');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    btn.disabled = true;
    btn.textContent = 'Connexion…';
    try {
      // WordPress refuse une connexion si son cookie de test n'existe pas
      // encore : « les cookies sont bloqués ». À la première visite, il
      // n'existe pas. Un GET préalable le pose, et la connexion passe.
      if (!document.cookie.includes('wordpress_test_cookie')) {
        await fetch(form.action, { credentials: 'same-origin' });
      }

      const r = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        redirect: 'follow',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          log: log.value.trim(),
          pwd: pwd.value,
          'wp-submit': 'Connexion',
          redirect_to: <?php echo wp_json_encode(TUC_Caisse::url()); ?>,
          testcookie: '1',
        }),
      });
      const html = await r.text();
      // wp-login.php répond 200 avec le formulaire en cas d'échec
      if (html.includes('login_error') || html.includes('incorrect')) {
        // on reprend le motif exact renvoyé par WordPress plutôt qu'un
        // message générique : « cookies bloqués » n'est pas « mauvais mot de
        // passe », et confondre les deux fait perdre du temps au gérant
        const m = html.match(/id="login_error"[^>]*>([\s\S]*?)<\/p>/);
        const motif = m ? m[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() : '';
        note.textContent = motif || 'Identifiant ou mot de passe incorrect.';
        note.style.background = '#FFF0F3';
        btn.disabled = false;
        btn.textContent = 'Ouvrir la caisse';
        return;
      }
      location.replace(<?php echo wp_json_encode(TUC_Caisse::url()); ?>);
    } catch (_) {
      note.innerHTML = 'Connexion impossible : vérifiez le réseau.';
      note.style.background = '#FFF0F3';
      btn.disabled = false;
      btn.textContent = 'Ouvrir la caisse';
    }
  });
})();
</script>
<?php endif; ?>

</body>
</html>
