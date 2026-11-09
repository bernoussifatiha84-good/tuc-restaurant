# Décisions du propriétaire

Consignées pour que le code et les tests s'y réfèrent. Une décision change le
contrat ; elle ne se déduit pas du code existant.

## 1. Alerte sonore — tous les rôles caisse, tous les modes

**Décision :** le patron et la cuisine doivent entendre toute nouvelle commande.

Avant, le bip et la vibration exigeait cinq conditions simultanées, dont le rôle
`tuc_serveur` et `mode === 'table'`. Conséquence : un restaurateur tenant sa caisse
n'entendait rien, et une commande emporter ou livraison ne sonnait pour personne.

Après :

| Critère | Règle |
|---|---|
| Capacité `CAP_AUDIBLE_ORDER_ALERTS` | accordée à `tuc_restaurateur`, `tuc_gestionnaire`, `tuc_caissier`, `tuc_serveur` |
| Mode de commande | **aucun filtrage** — table, emporter, livraison |
| Onglet affiché | **aucun filtrage** — l'alerte est calculée sur la liste complète |
| Rattachement `tuc_resto_id` | **obligatoire et conservé** |
| Autre restaurant, ou sans affectation | **refusé**, notification silencieuse |

`TUC_Roles::SCHEMA_VERSION` passe de 3 à 4 : sans ce bump, `synchroniser()` sort
immédiatement sur une installation existante et les nouvelles capacités ne sont
jamais accordées. C'est le piège qui aurait rendu le correctif inopérant en UAT.

Le rôle n'est plus codé en dur dans `profil_alerte()` : **la capacité décide**. Ajouter
un rôle alertable se fait désormais dans `TUC_Roles`, pas dans la logique d'alerte.

Côté caisse, la détection d'arrivée est déplacée de `charger()` — qui ne voit que
l'onglet courant — vers `chargerCompteurs()`, qui interroge tous les statuts. Une
baseline est posée au premier relevé complet pour ne pas sonner sur l'existant.

L'opt-in de l'appareil reste requis : le son ne se déclenche qu'après activation
par l'utilisateur, contrainte des navigateurs mobiles.

## 2. WhatsApp — un numéro de site, pour la vérification seulement

**Décision :** `wa.me` sert à confirmer une création de compte ou à demander un
code. **Les commandes vont au tableau de bord du restaurant, pas à un téléphone.**

Vérifié conforme dans le code : le panier client poste sur `/tuc/v1/commande`
(`assets/pro/client-cart.js`), qui crée la commande en base et alimente la caisse.
Le front n'utilise `wa-lien` et `wa-statut` que pour la vérification.

`get_option('tuc_wa_numero')` est une option **globale au site**. C'est voulu : c'est
le numéro de TUC, pas celui d'un restaurant. Il n'y a pas de numéro par restaurant,
et il n'en faut pas pour ce parcours.

Sans cette option, **deux** endpoints renvoient **503** et bloquent : création de
compte client et inscription restaurant. Ce n'est pas une dégradation silencieuse,
c'est un refus explicite.

*(Le troisième, « aperçu de panier », n'existe plus : `/wa-panier` a été supprimé
— voir la section suivante. La phrase précédente annonçait encore trois endpoints
alors qu'il n'en restait que deux ; c'est corrigé ici.)*

**Réglage par l'écran** : `Réglages → Réglages TUC` dans l'administration.

Le champ est **normalisé à l'enregistrement**, et c'est indispensable : les deux
lectures se contentent de retirer les caractères non numériques et de préfixer
`https://wa.me/`. Sans normalisation, `0600000000` produirait
`wa.me/0600000000` — un lien cassé, sans aucune erreur. L'écran stocke donc
toujours la forme internationale, et affiche la valeur enregistrée ainsi qu'un
aperçu du lien produit.

Effacer le champ est permis : le site repasse en état « non configuré » et les
deux endpoints refusent de nouveau en 503.

**Solution de secours, si l'écran n'est pas disponible** (par exemple avant
déploiement, ou sur un environnement sans administration) :

**À régler sur chaque environnement**, hors dépôt.

Le motif n'est **pas** le secret : ce numéro est le contact **public** de TUC.
`/wa-lien` est déclaré `permission_callback => '__return_true'`
(`class-tuc-compte.php:177-181`) et renvoie `https://wa.me/<numéro>` dans sa
réponse (l.318) — n'importe quel visiteur obtient ce numéro, c'est même le but.

Le motif est qu'une **valeur de configuration propre à un environnement** n'a
rien à faire dans du code versionné : elle diffère entre dev, uat et production,
elle change sans qu'on touche au code, et la mettre en dur ferait qu'un
environnement hériterait silencieusement du numéro d'un autre. Le dépôt étant
public, la règle a en plus l'avantage d'éviter d'y publier une donnée
d'exploitation — mais ce n'est pas une question de confidentialité :

```bash
wp option update tuc_wa_numero '<indicatif><numéro, chiffres seuls>'
wp option get tuc_wa_numero
```

Le message est pré-rempli et envoyé par le client depuis son propre WhatsApp :
aucun abonnement API, aucun coût par message.

### `/wa-panier` supprimé

L'endpoint construissait un lien `wa.me` avec tout le panier vers le numéro du
site. Il était appelable anonymement et contredisait la décision ci-dessus. Il est
retiré, avec la méthode `TUC_Compte::wa_panier()` et le formateur
`TUC_WA_Message::panier()` que plus rien n'appelait. `TUC_WA_Message::wa_url()` est
conservé : l'onboarding restaurant l'utilise.

**Supprimer ne devait pas faire perdre de couverture.** Les deux assertions portées
par cet endpoint ont été reportées sur `/commande`, seul chemin de commande réel :

| Propriété | Nouvel emplacement |
|---|---|
|Prix et libellés fournis par le client ignorés | `$validOrder` envoie `nom` et `pu` falsifiés ; le total serveur reste 31 et le libellé stocké est celui de WordPress |
| Mode absent de la configuration refusé | `/commande` avec `mode=emporter` alors que `tuc_modes=['livraison']` → 422 |

La protection contre un total falsifié était déjà couverte sur `/commande` (409 avec
`total_serveur`), elle n'a pas eu besoin d'être reportée.

Une **garde de non-réintroduction** affirme que `/tuc/v1/wa-panier` est absent des
routes enregistrées, et que `/commande` et `/wa-lien` y sont toujours. Réintroduire
le chemin WhatsApp ferait échouer la CI.

### Bouton « appeler » sur la fiche restaurant

Demandé : un bouton d'appel avec le numéro **du restaurant**, distinct du WhatsApp
du site. Nécessite un champ téléphone par restaurant et son affichage. Pas encore
implémenté.

## 3. Moteur de base — UAT aligné sur MySQL

**Décision :** l'UAT doit tourner sur le moteur de la cible, pour que valider en
UAT préfigure la production.

État constaté : CI sur MariaDB 10.11 (`compose.yaml`), `dev` sur MySQL, UAT sur
SQLite. La roadmap visait déjà « SQLite UAT et MySQL cible »
(`docs/ROADMAP-MARKETPLACE.md`) ; l'alignement lève l'écart.

**Ce n'est pas un interrupteur.** `PLUGINS.md` avertit que
`sqlite-database-integration` fournit le drop-in `db.php` qui fait tourner la base
UAT. Le désactiver sans migrer rend le site indisponible. Séquence exigée :
sauvegarde vérifiée de la base SQLite, export, import MySQL, bascule du drop-in,
contrôle de santé, et seulement ensuite retrait du plugin SQLite.

Opération serveur, hors de portée du dépôt.

## 4. Compte de test UAT

**Décision :** créer un compte porteur d'un rôle caisse, rattaché à un restaurant
publié via `tuc_resto_id`, pour que le parcours `/caisse/` puisse être exercé.

Sans ce rattachement, aucune alerte sonore n'est possible — la règle d'isolation
le refuse, et c'est voulu. Le test métier n'a pas de sens avant.

## 5. Catalogue public — base + démonstration marquée (option B)

**Décision :** brancher le thème sur la base, et garder un jeu de démonstration
**clairement signalé**, au lieu de faux commerces présentés comme réels.

### Pourquoi les cartes n'étaient pas cliquables

`inc/data.php` contenait 25 restaurants codés en dur. Sans article WordPress
derrière eux, ils n'avaient **aucun permalien** : `front-page.php` rendait
`<article class="card">` sans lien. Les vrais restaurants, eux, étaient bien liés
(`get_permalink`) — mais n'apparaissaient que sur une recherche par quartier ou par
ville. Ce mélange de deux sources rendait la page incohérente, et **un reset de la
base n'y aurait rien changé** puisque les faux commerces venaient d'un fichier PHP
déployé depuis Git.

### Ce qui change

- `tuc_restos()` et `tuc_promos()` interrogent `tuc_resto` / `tuc_menu_item`. La
  forme du tableau est conservée pour ne pas casser les gabarits ; s'y ajoutent
  `id`, `url`, `demo`, `thumb`, `livraison`.
- Cartes restaurants **et** promotions enveloppées dans un lien vers la boutique.
- Badge « Démo » sur tout contenu portant `tuc_demo = 1`.
- Trois métas enregistrés sur `tuc_resto` : `tuc_kind` (section d'accueil),
  `tuc_demo`, `tuc_img` (visuel de repli).
- « Livraison offerte » n'aspire plus un commerce qui ne livre pas.

### Jeu de démonstration

```bash
wp eval-file tools/seed-demo.php                  # crée ou met à jour, idempotent
wp eval-file tools/seed-demo.php -- --supprimer    # retire tout le contenu démo
```

12 restaurants et 36 plats, créés comme de **vrais articles** : ils ont donc un
permalien, une fiche restaurant et un menu. Tous marqués `tuc_demo=1`, donc badgés
et retirables d'un coup quand de vrais commerces arrivent.

Le script **refuse de s'exécuter** si `WP_ENVIRONMENT_TYPE` vaut `production`, et
n'utilise **aucun nom de marque réelle** — l'ancien jeu citait des enseignes
existantes, ce qui faisait passer de la fiction pour de vrais commerces.

Il ne crée ni numéro WhatsApp ni compte : ce sont des décisions distinctes.

**BREAKING :** `/tuc/v1/restos` et `/tuc/v1/promos` renvoient désormais la base.

### Exécuter le seed sur un serveur : deux prérequis, pas zéro

La commande ci-dessus suppose le fichier **présent sur l'hôte**. Or `deploy.yml`
ne synchronise que `wordpress/tuc-theme/` et `wordpress/tuc-market-core/` :
`tools/` n'est **pas déployé**. Constater avant d'exécuter :

```bash
wp eval 'echo wp_get_environment_type();'   # 'production' => le script refusera
test -r tools/seed-demo.php                 # absent => rien à exécuter
```

**Ne pas déplacer le seed dans `tuc-market-core/tools/` pour le voir déployé.** Le
job `deploiement` est commun à `uat` et `prd` (`environment:` dynamique) et le
rsync du plugin est le même pour les deux : le script de démonstration partirait
aussi **en production**, dans un répertoire servi par le web. C'est l'inverse du
but recherché.

Le bon modèle existe déjà dans ce dépôt : `tools/woocommerce-inventory.php` est
envoyé par `scp` vers `/tmp/tuc-…-<run_id>.php`, exécuté par `wp eval-file`, dans un
job `environment: uat` garde-fouté contre `URL_UAT == URL_PRD`. Rien ne reste à
demeure sur l'hôte. Le seed doit suivre ce chemin, **sur déclenchement manuel** —
on ne re-sème pas de la démo à chaque déploiement.

### `WP_ENVIRONMENT_TYPE` est un réglage d'hôte, pas de dépôt

Il vit dans `wp-config.php`. Le poser à la main hors Git veut dire qu'une
réinstallation le fait revenir à `production` — le seed refuse alors de nouveau :
la panne est du bon côté. Le risque réel est inverse : un `wp-config.php` d'UAT
recopié en production y passerait la prod en `staging`.

**Effet de bord à connaître :** `TUC_Security_Headers` n'émet HSTS qu'en
`production`. Passer l'UAT en `staging` **retire donc `Strict-Transport-Security`**
des réponses de l'UAT, qui ne reproduit plus ce comportement de la prod. Les
autres en-têtes sont inchangés. Ce contrat est désormais couvert par la suite de
régression (`headers_for()`), pour qu'un changement d'environnement ne soit plus
un effet silencieux.

Le plugin ne doit **jamais** définir cette constante lui-même : il l'imposerait à
tous les sites où il est installé. Le déploiement peut en revanche la **lire et
refuser** si elle ne correspond pas à la cible (`uat` → `staging`, `prd` →
`production`).
