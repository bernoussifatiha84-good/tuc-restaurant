# Livre — job CI « HEIC avec décodeur réellement présent »

*(référence citée par l'avertissement de `tests/wp-docker-regression.php` ; ce
fichier existait en tant que nom, pas en tant que contenu, jusqu'à la présente
ronde — c'était un défaut du trail, corrigé ici.)*

## 1. Ce que la revue n°18 exige

> Un job avec décodeur HEIC **réellement présent** où la conversion est
> **obligatoire** : échec de conversion **ou** décodeur manquant ⇒ **rouge**.
> Le chemin « sans décodeur » reste testé **à part**.

## 2. Ce que la CI prouve aujourd'hui, et ce qu'elle ne prouve pas

Prouvé, dans le job `wordpress-docker` (run 42926883487, SHA `a8968c3`) :

- l'image `wordpress:cli-php8.2` **n'a pas** de décodeur HEIC — mesuré trois
  fois `décodeur HEIC : non` (`Imagick::queryFormats('HEIC')` vide) ;
- **avec** un job « à décodeur requis » sur une image sans décodeur, le test
  `tests/heic-decodeur-requis.php` **sort en 1**, et le run l'exige :
  `OK : sans décodeur, le job à décodeur requis ÉCHOUE bien (code 1)` ;
- les cas 27-31 du contrat : décodeur absent ⇒ capacité **signalée**, pas
  contournée ; pas de conversion silencieuse ; pas de fichier produit ; pas
  d'écrasement de l'original.

**Non prouvé en CI : une conversion HEIC réellement réussie dans GitHub
Actions.** Le positif est établi hors CI, dans le bac jetable (libheif +
Imagick présents) — logs livrés, section 3/4 de
`log-tests-bac-complet-a8968c3.txt`.

## 3. Ce qu'il faut ajouter (le patch est prêt, pas appliqué)

`patch-ci-heic-decodeur.patch` ajoute **un job séparé** `heic-decodeur` :

1. `.env` éphémère, identifiants masqués (`::add-mask::`) — comme le job
   principal, parce que `compose.yaml` évalue **tous** les services ;
2. `docker compose up` (WordPress + MariaDB), `core install`, activation du
   plugin ;
3. **une seule** commande dans le conteneur `wpcli`, en root :
   `TUC_APT_INSTALL=1 bash /opt/tuc-tests/ci-heic-decodeur.sh`
   (une seule parce que `--rm` détruit le conteneur : installer dans une étape
   et tester dans la suivante testerait une image qui n'a rien reçu) ;
4. en cas de rouge, `tail` du `debug.log` pour le diagnostic.

La logique est dans `tests/ci-heic-decodeur.sh` (déjà dans le dépôt, périmètre
`tests/`) et non dans le YAML : le YAML ne contient plus aucun imbrication de
guillemets, et le script est **rejouable hors CI**.

Règles du job, toutes vérifiables :

| Cas | Verdict attendu |
|---|---|
| `apt-get` n'installe pas de décodeur | **rouge** (`heic-decodeur-requis.php` sort en 1 avant même de convertir) |
| décodeur présent, conversion du HEIC de référence échoue | **rouge** |
| décodeur présent, conversion réussie | **vert** |
| HEIC tronqué accepté comme « converti » | **rouge** (contrôle négatif 2/3) |
| fichier sans image nommé `.heic` accepté | **rouge** (contrôle négatif 3/3) |

Aucun `continue-on-error`, aucun `if: failure()` sur le cœur, aucun repli.

## 4. Rejouabilité hors CI (mesure faite le 2026-11-11)

```bash
TUC_WP_ROOT=<racine-wp> TUC_TESTS_DIR=<dépôt>/tests TUC_APT_INSTALL=0 \
  bash tests/ci-heic-decodeur.sh
```

Dans le bac jetable, sortie : phase 1 `OK — un vrai HEIC est converti en JPEG
par le module` ; phases 2 et 3 `OK — ... refusé (rouge obtenu, et c'est voulu)` ;
`bilan : TOUT VERT`, code 0. Le `bash -n` du script et le `git apply --check` du
patch sur `a8968c3` sont passés.

**Ce qui n'a PAS pu être vérifié ici, et doit l'être par la session autorisée :**
le `apt-get install php8.2-imagick` dans l'image `wordpress:cli-php8.2` réelle.
Le nom du paquet dépend du dépôt Sury de l'image ; le script essaie aussi
`php-imagick`. Si aucun des deux n'apporte de décodeur HEIC, **le job sera
rouge avec ce message** — ce sera une information sur l'image, pas un test
tolérant. Alternative si le rouge vient du paquet : construire une image locale
(`php:8.2-cli` + Imagick compilé avec libheif) et la pointer via
`TUC_WP_CLI_IMAGE`.

## 5. Qui peut l'appliquer

Pas cette session : le jeton GitHub CLI utilisé a pour ports d'autorisation
`gist, read:org, repo` (vérifié le 2026-11-11) — **pas `workflow`**, donc
`.github/workflows/**` est refusé au push. Une session disposant du droit
« workflow » applique le patch, **sans jamais coller de jeton dans le fil** ;
les identifiants de test sont générés dans le run et masqués.

## 6. Limites à ne pas effacer

- La fixture est un **HEIC synthétique** (`ftypheic`, mire 1600×1200, 4 312
  octets), pas une photo d'iPhone. Un job vert prouverait « le décodeur et la
  conversion fonctionnent dans l'image », **pas** « le parcours iPhone est
  validé ». Le vrai iPhone reste **non testé**, sur un environnement à
  identifier, avec déploiement autorisé séparément.
- Le chemin HTTP multipart de la section 5 du test de limiteur est **séquentiel**
  : il prouve demande/quota/photo/orphelins, pas le deadlock (prouvé sur la même
  section critique en concurrence CLI).
- En CI, la section 5 joue contre un serveur `php -S` **auto-démarré par le
  test** sur une racine ombre : elle ne prouve rien sur Apache.
