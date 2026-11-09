# Publier les changements

Ce dépôt est géré par Git. Ne pas recréer le remote, renommer la branche par défaut ou forcer une mise à jour de `main` pour publier des changements.

## Publier une branche de travail

Vérifier d’abord la branche courante et l’état des fichiers, puis pousser cette branche sans réécrire l’historique :

```bash
git status --short --branch
git push -u origin <branche-de-travail>
```

Ne pas renommer la branche par défaut et ne pas utiliser `git push --force` pour publier ces changements. L’intégration dans `develop` et la création de tags de release se font séparément, selon le processus du propriétaire du projet.

## Déclencheurs de déploiement

- `develop` déclenche l’environnement `dev`.
- Un tag `pre-release-x.y.z` déclenche `uat`.
- Un tag `release-x.y.z` déclenche `prd`, sous réserve des protections et approbations GitHub.

Les tags `pre-release-*` et `release-*` ne sont pas des branches. Avant une release, vérifier les tests, les variables GitHub Actions et la sauvegarde de la cible.

## Secrets

Ne jamais télécharger ou copier les secrets de la passation dans les fichiers suivis par Git. Les clés privées, jetons, mots de passe et secrets applicatifs restent dans un gestionnaire de secrets ou dans les secrets GitHub Actions. Ne pas les imprimer dans le terminal, les journaux ou les commits.
