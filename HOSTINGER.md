# Environnements WordPress

Cette note décrit le modèle de déploiement du projet, pas les identifiants du compte d’hébergement. Les hôtes, utilisateurs SSH, chemins d’installation et clés ne doivent pas être publiés dans le dépôt.

## Modèle décrit dans la passation

| Environnement | Déclencheur | Usage |
|---|---|---|
| `dev` | branche `develop` | développement |
| `uat` | tag `pre-release-*` | validation avant release |
| `prd` | tag `release-*` | production; à protéger par approbation |

La passation indique que `dev` et `uat` utilisent des racines WordPress distinctes sur un même hébergement et que la production n’était pas encore raccordée. Cet état n’a pas été vérifié en direct et peut avoir changé.

## Configuration à conserver hors du dépôt

Les variables d’URL et de chemin (`URL_*`, `ROOT_*`) et le port SSH se configurent dans GitHub Actions Variables. Les secrets SSH se configurent dans les environnements GitHub appropriés. Ne pas copier de clé privée, token, mot de passe ou secret de webhook dans ce fichier, dans le dépôt ou dans les journaux d’action.

Le workflow utilise `rsync --delete` : son exécution peut supprimer des fichiers du thème ou du plugin présents uniquement sur le serveur. Sauvegarder et vérifier la cible avant chaque déploiement.
