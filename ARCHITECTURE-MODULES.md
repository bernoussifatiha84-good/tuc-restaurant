# Où vivre chaque module — thème ou plugin ?

> Question posée : *« tous ces plugins, est-ce qu'on peut les intégrer directement dans le thème
> ou les fusionner en 1 ou plusieurs plugins ? Quel plugin va remplacer Dokan ? »*
>
> 4 novembre 2026 · réponse appliquée et déployée sur l'UAT.

---

## 1. La réponse courte

**Un seul plugin : `tuc-market-core`. Pas dix.**

Mais **pas tout dans le thème**. La règle qui tranche n'est pas le goût, c'est
**ce qui survit à quoi** :

| Ce qui doit survivre… | Vit dans | Pourquoi |
|---|---|---|
| à un **changement de thème** | **plugin** | données, types de contenu, routes REST, commandes |
| à un **changement de design** | **plugin** | pas de logique dans les gabarits |
| au **déploiement** (`rsync --delete`) | **plugin** | le thème est effacé/remplacé, pas le plugin |
| à rien — c'est du rendu pur | **thème** | HTML, CSS, `<picture>`, gabarits |

---

## 2. Ce qui ne PEUT PAS vivre dans le thème

Trois raisons techniques, vérifiables :

### ① Un CPT dans un thème disparaît de l'admin

Si `tuc_resto` est enregistré par `functions.php` du thème et que le thème change,
**les fiches restent en base mais deviennent invisibles** : plus de menu dans l'admin,
plus de permalien, **404 sur `tuc.ma/kfc`**. Les QR déjà imprimés dans les restaurants
pointent dans le vide.

Et notre déploiement fait `rsync --delete` : un thème remplacé = CPT effacé du code.

### ② Une route REST dans un thème cesse d'exister

`POST /tuc/v1/commande` appelée par la PWA de caisse. Si le thème change, la route
n'existe plus → **la caisse ne reçoit plus aucune commande**, sans erreur visible côté client.

### ③ Les données de commande appartiennent au métier

Une commande, c'est un chiffre d'affaires. Ça ne peut pas dépendre du fichier
`single-tuc_resto.php` du moment.

> **Conclusion : 6 modules sur 8 vont dans le plugin.** Les deux autres (la fiche et
> le menu) sont **mixtes** : les données dans le plugin, le rendu dans le thème.

---

## 3. Le tableau de répartition, module par module

| # | Module | Données | Rendu | Où | État |
|---|---|---|---|---|---|
| 1 | CPT `tuc_resto` + slug `/kfc` | **plugin** | — | `class-tuc-cpt.php` + `class-tuc-rewrite.php` | ✅ **fait** |
| 2 | Fiche restaurant + menu | plugin (CPT item) | **thème** | `single-tuc_resto.php` | ✅ **fait** |
| 3 | Panier + options payantes | — | **thème** (JS 4 Ko) + plugin (validation prix) | à faire |
| 4 | REST `POST /commande` | **plugin** | — | `class-tuc-commande.php` | ✅ **fait** |
| 5 | Générateur QR | **plugin** | — | `class-tuc-qr.php` | ✅ **fait** |
| 6 | PWA caisse + Web Push | **plugin** | PWA = fichier séparé | `class-tuc-push.php` | à faire |
| 7 | Import OCR → CSV | **plugin** (outil admin) | — | `class-tuc-ocr.php` | à faire |
| 8 | Géoloc quartiers | **plugin** | JS minimal dans le thème | `class-tuc-geo.php` | ✅ **fait** |

### Vérifié sur l'UAT

```
/kfc/        → 200   (titre « KFC Anfa », h1, meta 4,6 · 15–30 min · 15 MAD)
/kfc/?t=12   → 200   (« Vous êtes à la table 12 »)
/            → 200   (accueil intact)
/inexistant/ → 404
```

```
GET  /wp-json/tuc/v1/sante      → modules.cpt_resto: true
GET  /wp-json/tuc/v1/quartiers  → 10 quartiers Casablanca
GET  /wp-json/tuc/v1/qr/kfc?table=12 → encode l'URL, pas un numéro
POST /wp-json/tuc/v1/commande   → {"ok":true,"commande":12}
```

---

## 4. Le slug `/kfc` : pourquoi ce n'était pas trivial

Trois tentatives, deux échecs utiles :

| Tentative | Résultat | Pourquoi ça a échoué |
|---|---|---|
| `'rewrite' => ['slug' => '']` | `/tuc_resto/kfc/` | WordPress retombe sur le nom du type |
| règle `^([^/]+)/?$` en `top` | **avale toutes les pages** | `/contact`, `/cgv` deviennent des restos |
| `request` filter, garde-fou strict | 404 sur `/kfc` | la règle des **pages** matche d'abord (`pagename=kfc`) |
| **`request` filter + arbitrage** ✅ | **200** | une page réelle gagne ; sinon le resto prend la main |

La version retenue : on laisse WordPress résoudre, puis on **arbitre**.
`get_page_by_path()` vérifie qu'aucune page/article/produit ne porte le slug.
Les slugs sensibles (`admin`, `api`, `qr`, `panier`…) sont réservés.

---

## 5. Le plugin qui remplace Dokan : **aucun**

Dokan, WCFM, MultiVendorX résolvent un problème que **TUC n'a pas** : plusieurs
vendeurs indépendants qui gèrent chacun leur boutique, avec commissions, versements
et sous-commandes.

TUC a des **restaurants**. Un restaurant n'est pas un vendeur :

| Dokan apporte | TUC a besoin de |
|---|---|
| multi-vendeur, commissions, payout | **1 resto = 1 fiche**, pas de commission |
| tableau de bord vendeur complet | un écran mobile : menu, commandes, QR |
| sous-commandes éclatées par vendeur | une commande = un resto |
| 350 fichiers, 30–60 requêtes SQL/page | budget : 0 requête ajoutée sur l'accueil |

**Donc : c'est `tuc-market-core` qui remplace Dokan** — module 1 (CPT) + module 4
(commandes). Environ **600 lignes** au lieu de 40 000.

Ce qu'on garde de Dokan : **l'idée** `vendeur → produits → commandes`, transposée en
`resto → menu → commandes`. Des idées, pas du code.

---

## 6. Où sont les 5 plugins tiers, alors ?

Ils restent des plugins **séparés**, on ne les fusionne pas :

| Plugin | Pourquoi on ne le fusionne pas |
|---|---|
| LiteSpeed Cache | s'interface avec le serveur (hcdn). Recoder = réécrire un cache de page |
| WP Mail SMTP | gère les protocoles SMTP/API mail. Risque sécurité si on l'écrit nous-mêmes |
| Two Factor | sécurité auditée. **Ne jamais écrire son propre 2FA** |
| UpdraftPlus | sauvegardes, restauration, destinations. Critique et complexe |
| WooCommerce | imposé ; **déchargé du front** (mesuré −118 Ko) |

**Règle** : on écrit notre métier, jamais notre sécurité ni nos sauvegardes.

---

## 7. Bilan de poids

| | Aujourd'hui |
|---|---|
| `tuc-market-core` | 7 fichiers PHP, **~28 Ko** |
| `tuc-theme` (hors images) | **37 Ko** (8 Ko CSS + 4 Ko JS + 24 Ko polices) |
| Plugins tiers sur le front | **0 Ko** (Woo déchargé, les 4 autres n'affichent rien) |
| **Total code servi** | **≈ 13 Ko** (CSS + JS seuls) |

Un plugin unique, deux fichiers sur le front, aucune dépendance.
C'est ça qui tient 1 million de visites sur du mutualisé.
