# Réconciliation du snapshot de passation

Date d’intégration : 5 novembre 2026.

## Provenance et périmètre

Le code source intégré provient du sous-dossier `01-code-actuel/tuc-restaurant/` de l’archive `passation-TUC-20261105.zip` partagée sur Google Drive. Le snapshot s’identifie comme le code de la branche `develop`, au commit `73e7044`, avec le plugin `0.8.5` et le thème `0.1.0`. Ces métadonnées sont celles du document de passation; elles n’ont pas été validées contre l’état distant de `develop`.

Le dossier WordPress du snapshot a été importé : plugin métier, gabarit de fiche restaurant, helpers d’images, outil de conversion et variantes WebP/AVIF. Le workflow SSH a été repris et adapté pour contrôler le type de référence, protéger les valeurs de secrets, vérifier les versions et rendre les erreurs de transfert/activation visibles.

Les documents techniques utiles ont été rédigés dans le dépôt sans les fichiers d’accès ou d’audit serveur. Le code et les documents ne contiennent pas les fichiers du répertoire confidentiel.

## Exclusions intentionnelles

N’ont pas été importés :

- `08-secrets/` (clés privées, jeton et secrets applicatifs);
- `04-serveur-audit/` (état détaillé du serveur et données/configuration d’environnement);
- les scripts d’audit qui ciblent directement un serveur ou manipulent des accès;
- les captures de sites tiers, maquettes volumineuses et preuves d’écran non nécessaires au code de production.

La copie ZIP téléchargée pour l’extraction a été supprimée de l’espace de travail après import. Aucun secret de l’archive n’a été copié dans le dépôt.

## Écarts de version et précautions

Le snapshot contenait 137 fichiers dans son arborescence de dépôt, contre 49 dans l’arborescence initialement auditée. Cinq fichiers communs différaient, dont le workflow, `HOSTINGER.md`, `functions.php`, `front-page.php` et `inc/rest.php`. Le code a donc été importé comme un snapshot plus récent, pas comme une simple addition de fichiers.

Le workflow utilise désormais le modèle `develop` → `dev`, tag `pre-release-*` → `uat`, tag `release-*` → `prd`. Les variables `URL_*` et `ROOT_*`, les secrets SSH par environnement, et les règles d’approbation de production doivent être confirmés dans les paramètres GitHub avant tout déploiement. L’accès de cette session n’a pas permis de vérifier les valeurs ni les noms configurés pour les variables et secrets.

Le transfert utilise `rsync --delete` : il remplace le contenu géré du thème et du plugin. Ne pas exécuter un déploiement sans sauvegarde et sans confirmer la cible.

## Contrôles et travail restant

- JavaScript, équilibre des accolades CSS, chemins d’images et différences Git à vérifier avant intégration.
- Le lint PHP est prévu dans GitHub Actions; PHP n’était pas installé dans l’environnement local au moment de l’import.
- Le panier, le suivi client et l’OCR ne sont pas implémentés dans le snapshot.
- Les résultats de tests et l’état UAT cités dans l’archive restent à rejouer indépendamment.
