# Audit transaction & panier — Glovo vs TUC

> 4 novembre 2026 · audit senior avant de valider le module panier.
> Sources : **tes 29 captures** (Glovo Maroc, mobile + desktop), analysées une par une.
> Aucune supposition : chaque point ci-dessous vient d'un écran que j'ai lu.

---

## 0. Ce que l'audit a trouvé dans NOTRE code — et qui est corrigé

🔴 **Faille critique : la commande faisait confiance au total envoyé par le client.**

```json
POST /tuc/v1/commande
{"resto":"kfc","items":[{"id":13,"q":1}],"total":1}
```

La première version créait une commande **à 1 MAD**. N'importe qui avec `curl`
commandait gratuitement. C'est corrigé et **vérifié sur l'UAT** :

| Test | Résultat |
|---|---|
| client annonce `total:1` (vrai prix 94) | **409** `total_serveur: 94` |
| client annonce `p:5` sur un plat à 79 | **409** — le prix client est ignoré |
| plat d'un autre restaurant | **422** |
| restaurant inconnu | **404** |
| panier vide | **400** |
| même `cle` renvoyée deux fois | **200 `doublon:true`** — une seule commande |
| option payante (`sauce-bbq` +4 MAD) | **201**, total 98 = 79+4+15 |
| commande à table (t=12) | **201**, frais **0** |

**Règle désormais non négociable : le navigateur propose, le serveur décide.**
Le panier côté client n'est qu'un affichage. Le prix, les frais, le minimum et
l'appartenance du plat au restaurant sont **recalculés en PHP**.

---

## 1. La transaction Glovo, décomposée en 6 étapes

D'après tes captures, voici le parcours réel :

### Étape 1 — Concentration par adresse
`Pill "Rue Boulmane, 18"` en haut, **avant** tout le reste.
Modale desktop : *« Où devons-nous livrer ? »* + *« Chercher l'adresse »* + *« Utiliser ma position »*.
Modale mobile : carte + pin teal + *« Recommandé / Partagez votre position actuelle »* + *« Partager »*.
**L'adresse détermine tout** : quels restos, quels frais, quels délais.

### Étape 2 — Fiche restaurant
Quatre indicateurs, toujours dans le même ordre :

```
👍 97 %      ⏱ 25–40′      🛵 15,00 MAD [Gratuit]      👑 Prime
satisfaction  délai         frais de livraison            abonnement
```

Puis un bandeau promo (`-44 % sur produits choisis`) et des onglets de catégories
(`Top des ventes · Cakes · Individuels · Tartes`).

### Étape 3 — Fiche produit : options en **groupes contraints**
C'est l'écran le plus structurant, et c'est là que 90 % des clones se trompent :

```
Benna Épicé — 52,00 MAD
Tacos à 3 strips original, double fromage, jalapeños, dynamite sauce, frites

Extra Sides      « Choisissez un maximum de 10 produits »
  3 Hot Wings        +20,00 MAD   (+)
  Coleslaw           +10,00 MAD   (+)
  Pain                +2,00 MAD   (+)
  …

Extra Sauce      « Choisissez un maximum de 4 produits »
  BBQ                 +4,00 MAD   (+)
  Mayo                +4,00 MAD   (+)
  …

(−)  1  (+)      →   [ Ajouter 1 pour 52,00 MAD ]
```

**Deux choses à retenir :**
- un **groupe** d'options a un **nom**, un **maximum**, et parfois un **minimum** ;
- le CTA affiche **déjà le prix final** (`Ajouter 1 pour 52,00 MAD`) — pas un « Ajouter ».

### Étape 4 — Panier
```
Votre commande
1 produit
[miniature] Red Velvet        30,00 MAD
              🗑  1  (+)                    ← stepper inline
                                            [ Continuer ]   ← barre fixe
```

Et sur la fiche resto, une **barre flottante persistante** :
`[ Aller au panier · 219,00 MAD ]` puis `ⓘ Informations sur les frais`.

### Étape 5 — Récapitulatif / connexion
Modale *« Bienvenue »* : préfixe + numéro, **Continuer avec WhatsApp**, Google, E-mail.
Nota : chez Glovo, le téléphone est **le compte** (OTP derrière).

### Étape 6 — Suivi
Paiement à la livraison accepté (FAQ : *« Vous pouvez payer par carte… ou en espèces »*).
Statuts : acceptée → en préparation → en route → livrée.

### La contrainte la plus importante, écrite noir sur blanc dans la FAQ Glovo

> *« Il n'y a qu'**un seul panier à la fois**. Pour commander depuis un autre
> établissement, vous devez d'abord finaliser votre commande. »*
> *« Il y a un **montant de panier minimum** chez Glovo. »*

**Deux règles que nous n'avons pas encore implémentées.** Détail plus bas.

---

## 2. Ce que Glovo fait et que TUC n'a PAS besoin de faire

| Glovo | TUC | Pourquoi |
|---|---|---|
| Coursier, suivi GPS, ETA live | ❌ | **TUC n'opère pas de livraison** — le resto livre |
| Paiement en ligne + Prime | ❌ | **cash uniquement**, décision actée |
| Commissions, payouts aux restos | ❌ | **0 % commision**, packs fixes |
| Attribution de commande algorithmique | ❌ | une commande = un restaurant = un téléphone de caisse |
| Compte client obligatoire | ❌ | à table, **pas de compte** : n° de table suffit |
| Multi-vendeurs (Dokan/WCFM) | ❌ | restaurants, pas vendeurs |

**Ce qu'on copie :** la structure du panier, les groupes d'options contraints,
la barre panier persistante, les 4 indicateurs de la fiche resto, l'adresse en étape 1.

---

## 3. Le modèle de panier TUC — propositions à valider

### 3.1 Les groupes d'options (module 3)

Aujourd'hui `tuc_options` est une liste plate. Il faut des **groupes** :

```json
"tuc_options": [
  { "id":"sides", "nom":"Extra Sides", "min":0, "max":10, "choix":[
      { "id":"wings", "nom":"3 Hot Wings", "prix":20 },
      { "id":"coleslaw", "nom":"Coleslaw", "prix":10 }
  ]},
  { "id":"sauce", "nom":"Extra Sauce", "min":1, "max":4, "choix":[
      { "id":"bbq", "nom":"BBQ", "prix":4 },
      { "id":"mayo", "nom":"Mayo", "prix":4 }
  ]}
]
```

Le serveur **valide** `min` et `max` : un client qui envoie 12 sides reçoit un 422.
Rien de tout ça n'existe encore — c'est le vrai travail du module 3.

### 3.2 Un seul restaurant par panier

Glovo l'impose. TUC doit le faire aussi, mais le message doit être **compréhensible** :

> *« Votre panier contient déjà des plats de **O'Tacos**. Voulez-vous le vider ? »*
> `[ Garder mon panier ]` `[ Vider et commander ici ]`

Implémentation : `localStorage` contient `{resto: slug, lignes: []}`. Un ajout depuis
un autre slug déclenche la modale. **Côté serveur aussi** : un POST dont les items
appartiennent à deux restos est refusé en 422 (déjà en place).

### 3.3 Panier minimum

Champ `tuc_minimum` créé. Le serveur renvoie déjà `422` avec `manque: X MAD`.
Il reste à l'**afficher** : la barre panier doit dire
`Encore 12 MAD pour commander` au lieu de `Aller au panier`.

### 3.4 Les statuts — machine à états stricte

```
nouvelle ──accept──► acceptee ──► preparation ──► prete ──► servie
   │                    │
   └──timeout 3 min──►  └──refusee / annulee
```

Déjà en place : `POST /commande/{id}/statut` refuse tout statut hors liste et
horodate chaque transition dans `tuc_historique`.
**Il manque** : le **timeout** (resto qui ne répond pas en 3 min → la caisse sonne
à nouveau, puis annulation auto) et la **notification client** à chaque transition.

### 3.5 Ce qui manque encore — liste honnête

| # | Manque | Gravité |
|---|---|---|
| 1 | Groupes d'options min/max | 🔴 bloquant module 3 |
| 2 | Un seul resto par panier (client) | 🔴 bloquant |
| 3 | Minimum de panier affiché | 🟠 important |
| 4 | Timeout de non-réponse du resto | 🟠 important |
| 5 | Notification client à `prete` | 🟠 important |
| 6 | Horaires d'ouverture appliqués (`tuc_ferme` créé, pas encore testé en code) | 🟠 |
| 7 | Frais calculés par distance (aujourd'hui : frais fixe par resto) | 🟡 |
| 8 | Promos appliquées **côté serveur** (`tuc_promo` existe, jamais utilisé) | 🟡 |
| 9 | Le téléphone n'est **pas vérifié** (pas d'OTP) | 🟡 acceptable en cash |
| 10 | Rate-limit basé sur le téléphone, **qui est optionnel** → anonyme non limité | 🟠 à corriger (cookie/table) |

---

## 4. Ce que je recommande avant de valider

**Ne pas attaquer le panier complet d'un coup.** Trois lots, dans cet ordre :

| Lot | Contenu | Pourquoi d'abord |
|---|---|---|
| **A** | Groupes d'options + validation min/max serveur + prix final au CTA | c'est l'écran qui définit la qualité perçue |
| **B** | Panier persistant (`localStorage`) + un seul resto + minimum + barre flottante | le parcours complet, testable au téléphone |
| **C** | Timeout resto + Web Push caisse + notifications de statut | ce qui rend le système **utilisable en vrai service** |

**Le lot C est celui qui décide si un restaurant peut vivre avec TUC.**
Un panier parfait qui ne sonne pas sur la caisse ne sert à rien.

---

## 5. Ce que l'audit confirme de nos choix

| Choix | Verdict de l'audit |
|---|---|
| Pas de paiement en ligne | ✅ cohérent : Glovo même accepte le cash |
| Pas de flotte de livraison | ✅ c'est le resto qui livre, on n'a pas besoin du suivi GPS |
| Pas de compte client à table | ✅ **avantage réel** vs Glovo : 0 friction, n° de table suffit |
| Un seul plugin | ✅ 4 modules déployés, 44 Ko, zéro requête sur l'accueil |
| Thème sans jQuery | ✅ la barre panier et les steppers tiennent en 4 Ko de JS |
