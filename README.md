# TUC Market

Marketplace de restaurants au Maroc : livraison par le restaurant, commande à emporter et commande à table par QR. Le dépôt contient le thème public WordPress, le plugin métier et les workflows de déploiement.

## Contenu

```text
wordpress/tuc-theme/          Présentation, accueil et fiche restaurant
wordpress/tuc-market-core/    Logique métier WordPress (plugin maison)
.github/workflows/deploy.yml  CI et déploiement SSH vers dev, UAT ou production
.github/workflows/deploy-ftp.yml  Ancien secours FTP manuel — non recommandé
```

Le code du plugin et les assets additionnels ont été intégrés depuis le snapshot de passation du 5 novembre 2026. Voir [`docs/IMPORT-STATUS.md`](docs/IMPORT-STATUS.md) pour la provenance, et [`docs/ROADMAP-MARKETPLACE.md`](docs/ROADMAP-MARKETPLACE.md) pour la feuille de route produit et technique.

## Fonctionnalités présentes dans le code

Le plugin `tuc-market-core` est en version `0.8.5` dans le snapshot intégré. Il contient notamment :

- les types de contenu `tuc_resto`, `tuc_menu_item` et `tuc_commande`;
- les URL de fiches restaurant à la racine du site;
- l’API de commande, avec recalcul serveur des prix et protections documentées dans le code;
- la génération de QR pour les tables et les modes de service;
- les comptes clients par numéro de téléphone et liens de connexion temporaires;
- la PWA de caisse et le service Web Push;
- des fonctionnalités de quartiers et une route de santé du site.

Le thème fournit l’accueil, le gabarit de fiche restaurant, les helpers de rendu et les images JPG avec variantes WebP/AVIF. Les données de démonstration actuelles comprennent **25 commerces et 8 promotions**.

### Limites connues

- Le panier client n’est pas encore raccordé au flux de commande.
- La page de suivi de commande côté client reste à faire.
- L’import OCR des menus n’est pas implémenté.
- La géolocalisation et le choix de quartier restent à compléter.
- Les fonctionnalités et tests décrits dans les documents de passation ne sont pas tous rejoués dans cet environnement.

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

## Vérifications locales

```bash
find wordpress -name '*.php' -print0 | while IFS= read -r -d '' f; do php -l "$f"; done
find wordpress -name '*.js' -print0 | while IFS= read -r -d '' f; do node --check "$f"; done
git diff --check
```

Le workflow GitHub Actions exécute également le lint PHP et des contrôles CSS. Des tests fonctionnels complets nécessitent un WordPress de test et ne sont pas exécutés par ces commandes locales.
