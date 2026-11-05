# Environnement local WordPress/Docker

Cet environnement sert aux tests fonctionnels WordPress/Docker. Le workflow GitHub `WordPress Docker smoke test` lance WordPress/MariaDB et un conteneur n8n éphémère, importe le workflow de test, puis simule un webhook WhatsApp signé jusqu’au webhook WordPress, au polling du lien magique et à sa consommation à usage unique. Les services, secrets et données sont jetables; rien n’est installé de façon persistante ni déployé vers UAT ou production.

## Prérequis

- Docker Engine (ou Docker Desktop) démarré.
- Plugin Docker Compose v2 (`docker compose`).
- Accès réseau pour télécharger les images WordPress, MariaDB et n8n au premier démarrage.

## Test Docker sur GitHub

`.github/workflows/wordpress-docker-smoke.yml` lance le test sur un runner GitHub avec Docker lors d’une pull request vers `main`. Il génère des secrets aléatoires éphémères, installe WordPress, vérifie l’activation du plugin/thème, lint le PHP, puis exécute les régressions WP-CLI métier et sécurité. Il importe et active ensuite `tests/n8n/tuc-wa-smoke.workflow.json` dans n8n 2.46.6, envoie un événement HMAC simulé, vérifie le relais vers `/wa-hook`, le refus d’une signature invalide, d’un événement expiré et d’un `event_id` rejoué, puis teste le polling et la consommation unique du lien magique. Le conteneur n8n, la base et les secrets sont supprimés en fin de job; aucun secret de production n’est utilisé.

Le déclenchement manuel est disponible une fois le workflow présent sur la branche par défaut; pour tester une branche de travail avant fusion, le déclencheur de pull request est prévu.

## Démarrage local (facultatif)

1. Depuis la racine du dépôt, créer le fichier local non versionné :

   ```bash
   cp .env.example .env
   ```

2. Dans `.env`, remplacer les mots de passe DB, `TUC_WA_SECRET`, `TUC_N8N_INBOUND_SECRET`, `TUC_N8N_POLL_SECRET` et `N8N_ENCRYPTION_KEY` par des valeurs locales aléatoires distinctes (par exemple `openssl rand -hex 32`). Ne jamais y mettre les identifiants UAT/production. `.env` est ignoré par Git. Les valeurs d’exemple sont des placeholders non sécurisés.
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
- Le workflow de test n8n utilise un format HMAC interne simulé, pas un webhook de Meta/Twilio/360dialog ni un numéro WhatsApp réel. Adapter la vérification de signature au fournisseur choisi, tester ses retries/identifiants d’événement et configurer les secrets dans un gestionnaire avant UAT/production.
- Les tests n’installent pas n8n de manière persistante sur GitHub : GitHub Actions exécute puis détruit un conteneur éphémère. Pour un service durable, déployer n8n sur une plateforme d’hébergement dédiée, pas dans un runner GitHub.
- N’utiliser ni comptes clients réels, ni données personnelles, ni commande/paiement réel. Les URLs de développement restent liées à `127.0.0.1`.
- Cette configuration n’exécute pas la CI et ne déclenche aucun déploiement.

## Résultats des tests GitHub

Le premier smoke test a réussi sur un runner GitHub avec Docker, sous la PR brouillon [#1](https://github.com/bernoussifatiha84-good/tuc-restaurant/pull/1), run [42037839606](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42037839606), commit `c43c680`. Il a validé l’installation WordPress, l’activation du plugin/thème, la page HTTP, la route de santé, PHP et le nettoyage.

La suite d’intégration WordPress/TUC a ensuite réussi sur le runner Docker GitHub dans le run [42085195862](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42085195862), commit `f1e7f80` : configuration Compose, installation et activation/désactivation du plugin, syntaxe PHP et régressions métier sont toutes passées. Les assertions couvrent les CPT et la santé, le calcul serveur des prix/suppléments/frais, le total falsifié, l’idempotence, les validations métier (dont fermeture, minimum et commerce/article croisés), les permissions de statut et la limite de débit. Les données de test sont jetables et le runner a nettoyé ses volumes et identifiants.

Cette suite valide le pont n8n→WordPress avec des signatures HMAC de test et un parcours magique côté serveur; elle ne valide pas le format/provider WhatsApp réel, la livraison du message par un fournisseur, la page navigateur `/connexion/`, les rôles multi-commerce, l’arabe/RTL, les paiements ou le déploiement UAT/production. La PR reste brouillon; rien n’a été fusionné ni déployé.

Le moteur Docker n’est pas installé dans l’environnement Agent local; l’exécution réelle a donc été faite sur le runner Docker de GitHub, comme demandé.
