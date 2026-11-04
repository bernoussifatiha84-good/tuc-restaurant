# Plugins TUC — décision et état

> Dernière mise à jour : 4 novembre 2026 · site `chocolate-gazelle-457691.hostingersite.com`

---

## ✅ Installés (5)

| Plugin | Version | Rôle chez TUC | Pourquoi celui-là |
|---|---|---|---|
| **WooCommerce** | 11.2.1 | catalogue produits + commandes | imposé par toi. Sert d'**admin seul** : les clients ne voient jamais l'interface Woo |
| **LiteSpeed Cache** | 7.9.3 | cache page, CSS/JS minifiés | le serveur est derrière **hcdn**. Aucun autre cache ne sait purger le CDN Hostinger |
| **WP Mail SMTP** | 4.10.3 | emails transactionnels | sans SMTP, `wp_mail()` part en spam ou n'arrive pas. Obligatoire pour les reçus |
| **Two Factor** | 0.17.0 | 2FA sur les comptes admin | 35 sites sur ton compte, une seule session volée = tout tombe |
| **UpdraftPlus** | 1.26.14 | sauvegardes quotidiennes | le rsync écrase `wp-content/themes/` : une mauvaise version et c'est perdu |

### Ce que j'ai délibérément **écarté**

| Plugin | Raison |
|---|---|
| Wordfence / Sucuri | doublon avec le WAF de Hostinger + 40 Mo de charge. À revoir si attaque réelle |
| Yoast / RankMath | le SEO des fiches resto sera fait dans le thème (schema.org Restaurant) — un plugin de plus = 12 requêtes |
| WP Rocket | **payant**, et LiteSpeed fait déjà mieux sur ce serveur |
| Elementor | tue la vitesse. Tout est déjà codé en PHP |
| Jetpack | 30+ modules inutiles, ralentit l'admin |
| WPML / Polylang | la bascule FR/AR sera native dans le thème (2 langues, pas 10) |

---

## 🔧 À créer par nous — **1 seul plugin**

### `tuc-market-core`

Un plugin unique, pas dix. C'est lui qui porte toute la logique métier :

| Module | Contenu |
|---|---|
| **CPT `tuc_resto`** | nom, cuisine, note, temps, distance, frais, modes (`livraison`/`emporter`/`sur place`), quartier, géoloc, slug SEO (`tuc.ma/kfc`) |
| **CPT `tuc_menu_item`** | produit, prix, options payantes, sauces, promos du jour |
| **Import OCR** | photo du menu → CSV → création en masse. 30 restos/jour |
| **Générateur QR** | PNG/PDF par table, encode `tuc.ma/{slug}?t={table}`. **Le restaurant imprime** |
| **REST commandes** | `POST /tuc/v1/commande` → notification caisse → confirmation |
| **PWA caisse** | manifest + service worker + Web Push pour que le téléphone de la caisse sonne |
| **Cron** | recalcul des notes, purge des promos expirées |

> ⚠️ Le dossier `wordpress/tuc-market-core/` est déjà prévu dans le workflow de déploiement. La ligne rsync existe, elle ne s'exécute pas encore parce que le dossier est vide.

**Suggestion d'ordre** : ① CPT + fiche resto (débloque les QR) → ② menu + panier → ③ REST commandes → ④ PWA caisse → ⑤ OCR.

---

## 🚫 Reverse engineering de Dokan / WCFM / MultiVendorX : **non**

Tu l'avais déjà écarté, et je confirme — pour trois raisons concrètes :

1. **Technique** — ce sont des usines à gaz. WCFM fait 350 fichiers pour du multi-vendeur. TUC n'a pas de vendeurs : il a des **restaurants**. Le modèle de données n'a rien à voir.
2. **Charge** — sur mutualisé, un de ces plugins ajoute 30 à 60 requêtes SQL par page. Ton objectif est **1 million de visites** : c'est incompatible.
3. **Légal** — le code est GPL donc réutilisable, mais copier/coller une architecture qui ne correspond pas à ton métier coûte plus cher que l'écrire.

**Ce que je garde d'eux** : des *idées* de structure (le couple `vendor` → `produits` → `commandes` devient `resto` → `menu` → `commandes`). Des idées, pas du code.

---

## 📦 Plugins envisagés plus tard (v2)

| Besoin | Plugin | Quand |
|---|---|---|
| OCR des menus | à écrire nous-mêmes (API Vision) | quand 50 restos sont prêts |
| Factures PDF | `WooCommerce PDF Invoices` | si les restos le demandent |
| SMS de secours | aucun (WhatsApp interdit en initié) | — |
| KDS / écran cuisine | **fait maison** | v2, comme décidé |

---

## ⚠️ Deux points à trancher

**① WooCommerce pèse lourd.** 11.2.1 ajoute ~40 requêtes et 200 Ko sur chaque page s'il est chargé. Sur ta page d'accueil il **n'est pas** chargé (le thème ne l'appelle pas), mais dès qu'on branche le panier il faudra le cantonner aux pages de commande.

**② Sans paiement en ligne, WooCommerce sert à quoi ?** Honnêtement : à gérer le catalogue et les commandes côté admin. Si tu préfères, `tuc-market-core` peut faire ça seul et Woo devient inutile. **Dis-moi ce que tu veux** — mais tant que ce n'est pas tranché, je le garde installé (désactivé serait plus propre).
