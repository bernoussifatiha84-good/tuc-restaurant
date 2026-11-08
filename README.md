# TUC Market

Marketplace de restaurants au Maroc : livraison par le restaurant, commande à emporter et commande à table par QR. Le dépôt contient le thème public WordPress, le plugin métier, les tests et les workflows de déploiement.

## Contenu

```text
wordpress/tuc-theme/              Présentation, accueil et fiche restaurant
wordpress/tuc-market-core/        Logique métier WordPress (plugin maison)
tests/                            Régressions WordPress, E2E mobile, checkout, onboarding, NVDA
compose.yaml                      WordPress + MariaDB + n8n éphémères pour les tests
.github/workflows/deploy.yml      Déploiement SSH vers dev, UAT ou production
.github/workflows/wordpress-docker-smoke.yml  CI complète sans déploiement
```

Voir [`docs/IMPORT-STATUS.md`](docs/IMPORT-STATUS.md) pour la provenance du snapshot, [`docs/ROADMAP-MARKETPLACE.md`](docs/ROADMAP-MARKETPLACE.md) pour la feuille de route, [`docs/ESPACE-PRO-MVP.md`](docs/ESPACE-PRO-MVP.md) pour le périmètre Pro/panier/suivi, et [`docs/RELEASE-CHECKLIST.md`](docs/RELEASE-CHECKLIST.md) pour les gates avant UAT/public.

## Fonctionnalités présentes dans le code

Cette branche d’intégration contient `develop` (fusionné, ascendance vérifiée par `tests/lineage-contract.py`), le travail de la PR #1 et la régression NVDA. Plugin `0.8.8`, thème `0.2.0`. Il contient notamment :

- les types de contenu `tuc_resto`, `tuc_menu_item` et `tuc_commande` ;
- les fiches restaurant, le menu public, le panier client et la commande cash avec recalcul serveur des prix ;
- le suivi client par jeton opaque, sans exposition des données personnelles ;
- les rôles et l’isolation par restaurant (`owner`, `manager`, `cashier`, `server`) ;
- l’Espace Pro mobile : menu, brouillon/publication, disponibilité, promotion simple, options, photo, commandes et réglages boutique ;
- la demande restaurateur assistée : formulaire public, photo du menu, relais WhatsApp manuel, vérification humaine et lien magique d’accès ;
- la génération de QR pour les tables et les modes de service ;
- les comptes clients par numéro de téléphone et liens de connexion temporaires ;
- la PWA de caisse, le service Web Push et un contrat sonore de secours ;
- des fonctionnalités de localisation/quartiers et une route de santé du site.

Le thème fournit l’accueil, le gabarit de fiche restaurant, les helpers de rendu et les images avec variantes WebP/AVIF. Les données de démonstration restent temporaires : en production, les commerces et menus doivent venir de contenus WordPress vérifiés.

### Limites connues

- L’import OCR automatique des menus n’est pas implémenté ; la photo du menu est un point de départ pour assistance humaine.
- Aucun fournisseur WhatsApp réel n’est configuré dans la CI : `wa.me` ouvre WhatsApp pour envoi manuel, et n8n est testé avec des événements simulés signés.
- Les tests manuels sur vrais appareils, lecteurs d’écran, réseau lent et avec restaurateurs consentants restent à faire.
- Cette branche n’est pas déployée en production et ne vaut pas validation UAT finale.

## CI sans déploiement

Le workflow `WordPress Docker smoke test` installe un WordPress éphémère, active le plugin/thème, puis exécute :

- régressions WordPress/TUC (sécurité, isolation, prix, idempotence, options, suivi) ;
- parcours E2E mobile, dix scénarios de commande client/restaurant, onboarding restaurateur via n8n simulé ;
- audit web professionnel (Lighthouse mobile, SEO, WCAG/axe, responsive, REST) ;
- job Windows : **régression automatisée NVDA + eSpeak intégré** avec capture PCM français/arabe sans pilote audio virtuel — ce n’est pas une validation d’accessibilité (pas de voix Windows réelle ni d’utilisateur réel) ;
- garde-fous de **lignée `develop`** et de **contrat de déploiement** (le script de résolution de cible est exécuté sur 10 cas, sans déployer).

Dernier run d’intégration réussi : <https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42482683516>.

## Déploiement

Le workflow SSH adopte le flux décrit dans la passation :

| Référence | Environnement |
|---|---|
| branche `develop` | `dev` |
| tag `pre-release-x.y.z` | `uat` |
| tag `release-x.y.z` | `prd` |

Les tags `pre-release-*` et `release-*` sont des **tags Git**, pas des branches. Une exécution manuelle depuis une référence non autorisée est rejetée. Le déploiement en production doit aussi être protégé par les règles d’approbation de l’environnement GitHub `prd`.

Le workflow vérifie PHP, CSS, JavaScript et les fichiers WordPress nécessaires, envoie le thème et le plugin via SSH/rsync, active les deux composants, purge les caches puis vérifie `/wp-json/tuc/v1/sante` et leurs versions.

### Configuration GitHub Actions

Les valeurs de déploiement sont des variables de dépôt : `URL_DEV`, `ROOT_DEV`, `URL_UAT`, `ROOT_UAT`, `URL_PRD`, `ROOT_PRD` et `SSH_PORT` (65002 par défaut). Les secrets SSH (`SSH_HOST`, `SSH_USERNAME` ou `SSH_USER`, `SSH_PRIVATE_KEY`) doivent être configurés dans les environnements GitHub concernés.

**Ne jamais mettre une clé privée, un jeton, un mot de passe, un secret de webhook ou une clé privée Web Push dans Git.** Le répertoire confidentiel présent dans l’archive de passation n’a pas été intégré.

> `rsync --delete` supprime sur le serveur les fichiers du thème ou du plugin qui ne sont pas dans le dépôt. Vérifier la cible et disposer d’une sauvegarde avant un déploiement.

## Installation manuelle

Copier `wordpress/tuc-theme/` dans `wp-content/themes/tuc-theme/` et `wordpress/tuc-market-core/` dans `wp-content/plugins/tuc-market-core/`, puis activer le plugin et le thème dans WordPress. Le workflow SSH effectue ces activations automatiquement après le transfert.

## Environnement WordPress local

Le fichier [`compose.yaml`](compose.yaml) démarre WordPress + MariaDB localement et monte le thème/plugin du dépôt. Voir le [guide de test Docker](docs/LOCAL-WORDPRESS-DOCKER.md). Cette configuration n’effectue aucun déploiement.

## Vérifications locales

```bash
find wordpress -name '*.php' -print0 | while IFS= read -r -d '' f; do php -l "$f"; done
find wordpress tests -name '*.js' -not -path '*/node_modules/*' -print0 | while IFS= read -r -d '' f; do node --check "$f"; done
git diff --check
```

Le workflow de déploiement exécute le lint PHP et les contrôles CSS/JS avant transfert. Le workflow `WordPress Docker smoke test` va plus loin : runtime WordPress, régressions métier, navigateur mobile, audit web et NVDA Windows. Il ne déploie rien.
