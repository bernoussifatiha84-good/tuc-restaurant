# Audit de sécurité ciblé — TUC Market Core

**Date :** 5 novembre 2026  
**Portée :** plugin WordPress `tuc-market-core`, parcours de commande/caisse, compte et WhatsApp, Push, permissions REST, limitation de débit et données personnelles.  
**Méthode :** revue ciblée du code et tests de régression d’intégration WordPress dans le workflow Docker GitHub. Ce document ne constitue pas un pentest ni une certification.

## Synthèse

La revue a identifié des contrôles insuffisants sur les permissions de caisse/statut, le prix affiché dans le parcours WhatsApp, le polling du lien magique, la validation des endpoints Web Push et plusieurs validations/limites de requêtes publiques. Les correctifs ont été validés par le workflow Docker WordPress GitHub sur le commit `798a6481409fa1191a24b5055aa620ae922d0fad` (run [42092149867](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42092149867)). Le workflow a linté le PHP du plugin, du thème et des tests, exécuté les régressions d’intégration et vérifié les routes HTTP/REST.

Aucun merge, déploiement, accès UAT/production ou appel au fournisseur WhatsApp n’a été effectué. Les points résiduels ci-dessous empêchent de considérer le parcours multi-commerce et l’authentification WhatsApp comme prêts pour la production.

## Constats et traitement

| ID | Sévérité | Constat | Traitement dans ce patch |
|---|---|---|---|
| SEC-01 | Élevée (P1) | L’aperçu de commande WhatsApp acceptait des prix, frais et libellés fournis par le navigateur. | Les plats, options, prix, frais et libellés sont reconstruits depuis WordPress; commerce, mode, table, disponibilité et minimum sont vérifiés. Test avec prix/frais/libellés falsifiés ajouté. |
| SEC-02 | Élevée (P1) | `edit_posts` était trop large pour ouvrir la caisse et la route historique de statut ne vérifiait pas que la cible était une commande. | Caisse et route historique limitées à `manage_options` comme mesure temporaire fail-closed; le callback historique vérifie le type de contenu. Tests sur permissions réellement enregistrées, utilisateur éditeur et cible d’un autre type ajoutés. |
| SEC-03 | Élevée (P1) | Un endpoint d’abonnement Web Push est contrôlé par le client et pouvait être utilisé comme cible de requête serveur (SSRF). | Refus des URL non HTTPS, ports autres que 443, identifiants et fragments; validation WordPress et `wp_safe_remote_post` à l’envoi. Tests des URL locales/non HTTPS ajoutés. |
| SEC-04 | Élevée (P1) | La référence WhatsApp seule permettait de sonder l’état et de récupérer le lien magique associé. | Ajout d’un secret navigateur distinct, seul son SHA-256 est conservé dans le transient; contrôle constant-time et réponses `no-store`. Tests sans secret, avec mauvais secret et avec secret valide ajoutés. |
| SEC-05 | Modérée (P2) | Les erreurs de validation pouvaient consommer la limite liée au téléphone; les commandes publiques n’avaient pas de limite IP complémentaire. | Le quota commande est consommé après validation métier/prix et applique les limites par téléphone normalisé et IP. Les routes publiques de création WhatsApp ont aussi une limite IP. Tests de tentatives au total erroné ajoutés. |
| SEC-06 | Modérée (P2) | Des articles non publiés/indisponibles pouvaient être soumis; certaines entrées publiques n’étaient pas bornées. | Refus des articles non publiés ou indisponibles; plafonds panier (50 lignes), quantité (50) et options par ligne (20); téléphone et clé d’idempotence validés. Tests de régression ajoutés. |

## Risques résiduels et conditions avant production

1. **Rôles et isolation par commerce — blocage pour l’ouverture aux équipes des restaurants.** Il n’existe pas encore de modèle complet de rôles owner/manager/cashier lié à un commerce. Le correctif ferme temporairement la caisse aux seuls administrateurs WordPress (`manage_options`); il évite une élévation d’accès mais n’est pas une solution opérationnelle multi-locataire. Implémenter et tester des capacités dédiées et vérifier l’appartenance à la boutique pour chaque lecture/modification avant d’ouvrir la caisse aux commerçants.
2. **Consommation du lien magique — blocage avant activation de ce parcours.** Le dépôt ne contient pas de client `/connexion/` qui consomme `/magie`; le flux navigateur complet n’a donc pas pu être vérifié. Le jeton est dans une URL. La page de consommation doit empêcher cache et referrer, retirer le jeton de l’URL/historique au plus tôt et être testée de bout en bout avant lancement.
3. **Webhook WhatsApp.** Le webhook utilise un secret partagé statique avec comparaison constante-time et échoue fermé si le secret manque. Il n’y a pas de signature HMAC du fournisseur, timestamp/rejeu ou rotation automatisée documentée dans ce dépôt. Configurer un secret aléatoire fort depuis un gestionnaire de secrets, le faire transiter uniquement en HTTPS, le faire tourner et ajouter la vérification de signature/rejeu dès que le fournisseur le permet. L’intégration n8n/fournisseur n’a pas été auditée.
4. **Rate limiting.** Les compteurs reposent sur les transients WordPress et ne sont pas atomiques sous concurrence; `REMOTE_ADDR` doit représenter correctement le client. Derrière proxy/CDN, configurer une chaîne de proxies de confiance ou appliquer une limite au niveau du proxy/WAF. Les limites applicatives ne remplacent pas une protection volumétrique.
5. **Données personnelles.** Les numéros de téléphone et commandes sont nécessaires au service, mais ce dépôt ne définit pas une durée de conservation, purge automatisée, export/effacement complet, information de confidentialité ni journal d’accès. Définir ces règles avant une exploitation réelle et limiter les journaux contenant des URL ou identifiants.
6. **Couverture.** Les nouveaux tests ciblent les régressions décrites; ils ne remplacent pas des tests multi-utilisateurs multi-boutiques, un test navigateur complet du lien magique, une vérification de configuration en environnement de production, ni un pentest. Aucun service externe n’est contacté par les tests.

## Vérifications

- `git diff --check` : réussi avant les commits.
- PHP local : non disponible dans le sandbox; syntaxe PHP vérifiée dans l’image WP-CLI PHP 8.2 du runner.
- Workflow Docker WordPress : **réussi** au run [42092149867](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42092149867), commit `798a6481409fa1191a24b5055aa620ae922d0fad`; lint PHP plugin/thème/tests, régressions WordPress/TUC, vérification HTTP/REST et absence d’erreur PHP fatale réussis.

## Fichiers examinés/corrigés

`class-tuc-compte.php`, `class-tuc-caisse.php`, `class-tuc-commande.php`, `class-tuc-push.php`, `tests/wp-docker-regression.php` et `.github/workflows/wordpress-docker-smoke.yml`.
