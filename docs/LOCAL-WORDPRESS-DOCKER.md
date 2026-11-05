# Environnement local WordPress/Docker

Cet environnement sert aux tests fonctionnels WordPress/Docker. Le même Compose est utilisé par le workflow GitHub `WordPress Docker smoke test` sur un runner `ubuntu-latest`; le workflow installe WordPress, vérifie l’activation du plugin/thème, la santé HTTP/REST/PHP et exécute la suite d’intégration `tests/wp-docker-regression.php` sur des fixtures jetables, puis détruit les volumes éphémères. Il ne déploie rien vers UAT ou production.

## Prérequis

- Docker Engine (ou Docker Desktop) démarré.
- Plugin Docker Compose v2 (`docker compose`).
- Accès réseau pour télécharger les images officielles WordPress et MariaDB au premier démarrage.

## Test Docker sur GitHub

`.github/workflows/wordpress-docker-smoke.yml` lance le test sur un runner GitHub avec Docker lors d’une pull request vers `main`. Il génère des mots de passe éphémères dans le runner, installe WordPress, teste l’activation/désactivation du plugin, et exécute les régressions avec WP-CLI : CPT/santé, calcul serveur du prix et des suppléments, rejet du total falsifié, idempotence, erreurs métier, droits de statut et limitation de débit. Les fixtures sont supprimées par le script; le workflow retire ensuite conteneurs, base et identifiants du runner. Ce workflow n’a aucun secret de production et ne déploie pas le site.

Le déclenchement manuel est disponible une fois le workflow présent sur la branche par défaut; pour tester une branche de travail avant fusion, le déclencheur de pull request est prévu.

## Démarrage local (facultatif)

1. Depuis la racine du dépôt, créer le fichier local non versionné :

   ```bash
   cp .env.example .env
   ```

2. Dans `.env`, remplacer `TUC_DB_PASSWORD` et `TUC_DB_ROOT_PASSWORD` par deux valeurs locales distinctes. Ne jamais y mettre les identifiants UAT/production. `.env` est ignoré par Git.
3. Vérifier la configuration et démarrer les conteneurs :

   ```bash
   docker compose config
   docker compose up -d
   docker compose ps
   ```

4. Ouvrir `http://127.0.0.1:8080`, terminer l’installation WordPress, puis activer **TUC Market Core** et le thème **TUC** dans l’administration.
5. Vérifier l’API de santé : `http://127.0.0.1:8080/wp-json/tuc/v1/sante`.

Le plugin et le thème sont montés en lecture seule depuis `wordpress/`; les changements de code se font dans Git. La base, les fichiers WordPress et les uploads de test restent dans des volumes Docker locaux.

## Commandes utiles

```bash
docker compose logs -f wordpress
docker compose logs -f db
docker compose stop
docker compose start
```

`docker compose down` arrête et retire les conteneurs, mais conserve les volumes. **`docker compose down -v` efface la base WordPress et tous les uploads de test locaux**; ne l’utiliser que pour repartir de zéro.

## Limites et sécurité

- Les images par défaut ciblent PHP 8.2 et MariaDB 10.11. Ce choix est un socle local de départ, pas une preuve de compatibilité avec l’hébergement UAT/production. Dès que leurs versions seront vérifiées, épingler des versions équivalentes pour les tests de validation.
- Aucun service n8n ni numéro WhatsApp n’est configuré ici. Tester d’abord avec des événements/messages fictifs; l’intégration réelle utilisera un numéro de test et les secrets du fournisseur hors Git.
- N’utiliser ni comptes clients réels, ni données personnelles, ni commande/paiement réel. Les URLs de développement restent liées à `127.0.0.1`.
- Cette configuration n’exécute pas la CI et ne déclenche aucun déploiement.

## Résultat du premier smoke test GitHub

Le test a été exécuté sur un runner GitHub hébergé avec Docker, sous la PR brouillon [#1](https://github.com/bernoussifatiha84-good/tuc-restaurant/pull/1), run [42037839606](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42037839606), commit `c43c680` : **succès**.

Le runner a validé `docker compose config`, démarré WordPress et MariaDB, installé WordPress avec WP-CLI, activé **TUC Market Core** et le thème, vérifié la page HTTP, `/wp-json/tuc/v1/sante`, PHP et l’absence d’erreur PHP fatale dans `debug.log`, puis supprimé conteneurs, volumes et identifiants éphémères.

C’est un smoke test d’installation/activation, pas encore une validation complète des parcours, des rôles, du flux WhatsApp, de l’arabe/RTL ou des commandes. La PR reste brouillon; rien n’a été fusionné ni déployé.

Le moteur Docker n’est pas installé dans l’environnement Agent local; l’exécution réelle a donc été faite sur le runner Docker de GitHub, comme demandé.
