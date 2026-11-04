# Roadmap TUC — plugins, thème, images

> 4 novembre 2026. Tous les chiffres de ce document sont **mesurés**, pas estimés.
> Page de référence : l'accueil de l'UAT, mesuré dans Chromium en 390×844.

---

## 1. Le thème est-il léger ? Oui. Verdict chiffré.

| | Poids | Détail |
|---|---|---|
| `tuc.css` | **8 Ko** | 1 fichier, non minifié, pas de framework |
| `tuc.js` | **4,5 Ko** | 1 fichier, zéro dépendance, **pas de jQuery** |
| `fonts.css` + 3 `.woff2` | 24 Ko | Manrope + Plex Arabic, auto-hébergées |
| **Total hors images** | **≈ 37 Ko** | |

**Aucune bibliothèque. Aucun constructeur de page. Aucun jQuery.**
Le PHP ne fait que rendre du HTML ; la logique métier est dans le plugin.

### Poids réel de la page d'accueil

| Élément | Avant | Après | Gain |
|---|---|---|---|
| CSS | 127 Ko (5 fichiers) | **9 Ko** (2 fichiers) | **−93 %** |
| JS | 41 Ko (8 fichiers) | **4 Ko** (1 fichier) | **−90 %** |
| Images | 1 221 Ko (JPEG) | **388 Ko** (AVIF) | **−68 %** |
| Polices | 24 Ko | 24 Ko | — |
| **Total page** | **1 413 Ko** | **523 Ko** | **−63 %** |
| Chargement complet | 3,1 s | **1,42 s** | **−54 %** |

> Le CSS et le JS ont fondu parce que **WooCommerce et jQuery étaient chargés sur
> l'accueil**. Ils sont maintenant déchargés hors boutique.

---

## 2. Les plugins : garder le minimum

### ✅ Gardés (3 sur le front + 2 hors front)

| Plugin | Charge front | Rôle | Pourquoi |
|---|---|---|---|
| **LiteSpeed Cache** | 0 | cache page + purge CDN | le serveur est derrière **hcdn** ; aucun autre cache ne sait purger ce CDN |
| **WP Mail SMTP** | 0 | emails de commande | sans lui `wp_mail()` part en spam |
| **Two Factor** | 0 | 2FA admin | **35 sites** sur ton compte partagent la même clé SSH |
| **UpdraftPlus** | 0 | sauvegardes | le rsync écrase le thème : une mauvaise version est irréversible |
| **WooCommerce** | **0 hors boutique** | catalogue + commandes côté admin | imposé ; **déchargé du front** (mesuré −118 Ko) |

### ❌ Retirés / à retirer

| Plugin | Raison |
|---|---|
| `hostinger-ai-assistant` | **88 Mo** de fichiers, aucune utilité sur TUC |
| `hostinger-easy-onboarding` | 25 Mo, wizard à usage unique |
| `hostinger-reach` | 21 Mo, marketing — les messages TUC ne sont jamais initiés |
| Akismet / Hello Dolly | inactifs, à supprimer |

### 🚫 Jamais installés

| Plugin | Remplacé par |
|---|---|
| Yoast / RankMath | `schema.org` écrit dans le thème : Restaurant, LocalBusiness, Review |
| WPML / Polylang | FR/AR natif dans le thème (2 langues, pas 10) |
| Elementor / WPBakery | tout est en PHP, 8 Ko de CSS |
| WP Rocket | **payant**, et LiteSpeed fait mieux sur ce serveur |
| Wordfence / Sucuri | doublon du WAF Hostinger + 40 Mo de charge |
| Jetpack | 30+ modules, ralentit l'admin |
| Smush / Imagify / ShortPixel | la conversion se fait **dans le dépôt**, pas à chaque upload |
| WP-Chat / plugins WhatsApp | interdit : TUC n'initie jamais un message |
| Dokan / WCFM / MultiVendorX | pas de vendeurs, seulement des restaurants |

---

## 3. Ce qu'on écrit nous-mêmes : `tuc-market-core`

**Un plugin. Pas dix.** C'est lui qui portera le métier — comme `partikulier-core` chez pep.

| # | Module | Débloque | Priorité |
|---|---|---|---|
| 1 | CPT `tuc_resto` + slug SEO | **`tuc.ma/kfc`** | **P0** |
| 2 | Fiche restaurant + menu | la page qu'ouvre le QR | **P0** |
| 3 | Panier + options payantes | la commande, sans Woo | P1 |
| 4 | REST `POST /commande` | notification caisse | P1 |
| 5 | Générateur QR PNG/PDF | **le restaurant imprime** | P1 |
| 6 | PWA caisse + Web Push | le téléphone **sonne** | P2 |
| 7 | Import OCR → CSV | 30 restos/jour | P2 |
| 8 | Géoloc quartiers | tri par distance, `getCurrentPosition` une fois | P2 |

Déjà livré : **`/wp-json/tuc/v1/sante`** (module de santé, dans le plugin).

---

## 4. Les images : AVIF, pas WebP

### Les mesures, sur les 28 visuels TUC (680×440)

| Format | Poids total | Gain |
|---|---|---|
| JPEG (actuel) | 1 218 Ko | — |
| **WebP q78** | 1 188 Ko | **−2 %** ← inutile |
| **AVIF q50** | 690 Ko | −43 % |
| **AVIF q42** ← retenu | **509 Ko** | **−58 %** |
| AVIF q35 | 382 Ko | −69 % (artefacts visibles sur les zones lisses) |

### Recommandation : **AVIF q42, WebP en repli**

**Pourquoi pas WebP seul** : sur ces photos, à qualité équivalente, il ne gagne que
2 %. Encoder en WebP, c'est ajouter un format sans rien résoudre.

**Pourquoi garder WebP quand même** : Safari 14–15 ne lit pas l'AVIF, Chrome < 85 non plus.
Le `<picture>` sert l'AVIF en premier, le WebP sinon, le JPEG en dernier recours.
**Coût : zéro requête, zéro JS.**

```html
<picture>
  <source type="image/avif" srcset="…/avif/tagine.avif 440w">
  <source type="image/webp" srcset="…/webp/tagine.webp 440w">
  <img src="…/tagine.jpg" alt="…" width="440" height="330" loading="lazy">
</picture>
```

### Le convertisseur n'est pas un plugin

```bash
php tools/convert-images.php          # convertit les nouveaux .jpg
php tools/convert-images.php --force  # reconvertit tout
```

Sortie réelle :

```
28 fichiers JPEG · encodeur : gd
  tagine     68K -> avif   24K - webp   61K
  …
Total : JPEG 1218K - AVIF 509K (-58%) - WebP 1188K (-2%)
```

**Un plugin de conversion tourne *dans* WordPress** : il ajoute une requête à chaque
upload, pèse sur l'admin et consomme du CPU à la volée. Ici la conversion se fait
**une fois, dans le dépôt, et le résultat est versionné.**

### Ajouter une photo de restaurant

```bash
# 1. déposer le fichier
cp photo.jpg wordpress/tuc-theme/assets/img/nouveau-resto.jpg
# 2. convertir
php wordpress/tuc-theme/tools/convert-images.php
# 3. commit des 3 formats
git add wordpress/tuc-theme/assets/img/
git commit -m "img: nouveau-resto"
```

---

## 5. Budget de performance (à ne pas dépasser)

| Métrique | Budget | Aujourd'hui |
|---|---|---|
| Poids total page | < 600 Ko | **523 Ko** ✅ |
| CSS | < 15 Ko | 9 Ko ✅ |
| JS | < 15 Ko | 4 Ko ✅ |
| Images au premier écran | < 450 Ko | 388 Ko ✅ |
| Requêtes bloquantes | 0 | 0 ✅ |
| jQuery | interdit | absent ✅ |
| Chargement complet (4G) | < 2 s | 1,42 s ✅ |

**Règle d'arbitrage** : un plugin qui ajoute un `.css` ou un `.js` sur l'accueil
doit être justifié, sinon il est **déchargé** comme WooCommerce — ou remplacé par
du code dans `tuc-market-core`.

---

## 6. Ordre d'exécution

| Étape | Action | État |
|---|---|---|
| 1 | AVIF + WebP + `<picture>` | ✅ **fait, déployé** |
| 2 | WooCommerce hors du front | ✅ **fait, déployé** |
| 3 | Désactiver les 3 plugins Hostinger (134 Mo) | à faire |
| 4 | CPT `tuc_resto` + fiche `tuc.ma/{slug}` | **P0, prochaine** |
| 5 | Générateur QR | après 4 |
| 6 | Panier + REST commande | après 4 |
| 7 | PWA caisse + Web Push | après 6 |
| 8 | OCR | après 10 restos réels |

---

## 7. Une réserve à poser noir sur blanc

Les 523 Ko mesurés sont **l'accueil de l'UAT**, avec 62 images dont 24 chargées
au premier écran. Une page de **fiche restaurant** aura une grande photo + un menu :
le budget images montera. Il faudra alors :

- une image de couverture **1400×440 en AVIF ~60 Ko**
- des vignettes de plat **en 200×200 AVIF ~8 Ko** chacune
- **jamais** la photo d'origine en pleine résolution

Le convertisseur devra produire **3 tailles** (hero / carte / vignette), pas une seule.
C'est un travail sur `tools/convert-images.php` quand le CPT existera.
