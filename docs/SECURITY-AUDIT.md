# Audit de sécurité ciblé — TUC Market Core

**Date :** 5 novembre 2026  
**Portée :** plugin WordPress `tuc-market-core`, parcours de commande/caisse, compte et WhatsApp, Push, permissions REST, limitation de débit et données personnelles.  
**Méthode :** revue ciblée du code et tests de régression d’intégration WordPress dans le workflow Docker GitHub. Ce document ne constitue pas un pentest ni une certification.

## Synthèse

La revue a identifié des contrôles insuffisants sur les permissions de caisse/statut, le prix affiché dans le parcours WhatsApp, le polling du lien magique, la validation des endpoints Web Push et plusieurs validations/limites de requêtes publiques. Les correctifs initiaux ont passé le workflow Docker WordPress sur le commit `798a6481409fa1191a24b5055aa620ae922d0fad` (run [42092149867](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42092149867)). Un complément ajoute maintenant HMAC/timestamp/rejeu et une exécution n8n éphémère dans la CI; cette nouvelle partie reste à valider par son run GitHub.

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
| SEC-07 | Modérée (P2) | Le webhook WhatsApp utilisait un secret statique sans fraîcheur de requête ni protection contre le rejeu. | HMAC-SHA256 lié au timestamp, à l’`event_id`, au code et au téléphone normalisé; fenêtre de 5 minutes et rejet des identifiants déjà traités. Workflow n8n de test et tests invalides/expirés/rejoués ajoutés. |

## Risques résiduels et conditions avant production

1. **Rôles et isolation par commerce — blocage pour l’ouverture aux équipes des restaurants.** Il n’existe pas encore de modèle complet de rôles owner/manager/cashier lié à un commerce. Le correctif ferme temporairement la caisse aux seuls administrateurs WordPress (`manage_options`); il évite une élévation d’accès mais n’est pas une solution opérationnelle multi-locataire. Implémenter et tester des capacités dédiées et vérifier l’appartenance à la boutique pour chaque lecture/modification avant d’ouvrir la caisse aux commerçants.
2. **Consommation du lien magique — vérification navigateur restante.** Le workflow CI vérifie maintenant le relais n8n→WordPress, le polling avec secret navigateur, puis l’échange du lien magique et son usage unique côté serveur. Le dépôt ne contient toujours pas de client `/connexion/` qui consomme `/magie`; le comportement navigateur complet, l’en-tête Referrer, le cache et le nettoyage de l’URL/historique restent à valider avant lancement.
3. **Webhook WhatsApp — adaptateur fournisseur restant.** Le contrat interne n8n→WordPress est signé par HMAC avec timestamp et `event_id`; l’E2E utilise un événement simulé et vérifie mauvaise signature, expiration et rejeu. Le fournisseur WhatsApp n’est pas choisi/configuré : son format de signature officiel (souvent sur le corps HTTP brut) doit être vérifié dans n8n avant normalisation. Les secrets doivent être aléatoires, stockés hors Git et rotatifs. Le stockage replay WordPress repose sur les transients; la concurrence et une forte volumétrie nécessiteraient un mécanisme atomique/persistant.
4. **Rate limiting.** Les compteurs reposent sur les transients WordPress et ne sont pas atomiques sous concurrence; `REMOTE_ADDR` doit représenter correctement le client. Derrière proxy/CDN, configurer une chaîne de proxies de confiance ou appliquer une limite au niveau du proxy/WAF. Les limites applicatives ne remplacent pas une protection volumétrique.
5. **Données personnelles.** Les numéros de téléphone et commandes sont nécessaires au service, mais ce dépôt ne définit pas une durée de conservation, purge automatisée, export/effacement complet, information de confidentialité ni journal d’accès. Définir ces règles avant une exploitation réelle et limiter les journaux contenant des URL ou identifiants.
6. **Couverture.** Le runner GitHub installe n8n 2.46.6 temporairement et teste un événement synthétique signé, le relais vers WordPress, le rejet d’une signature erronée/expirée et d’un event_id rejoué, puis le lien magique côté serveur. Cela ne contacte aucun fournisseur WhatsApp réel et ne remplace pas la vérification de sa signature officielle, des retries, les tests navigateur du lien magique, les tests de rôles multi-commerce ou un pentest.

## Vérifications

- `git diff --check` : réussi avant les commits.
- PHP local : non disponible dans le sandbox; syntaxe PHP vérifiée dans l’image WP-CLI PHP 8.2 du runner.
- Workflow Docker WordPress sans n8n : **réussi** au run [42092149867](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42092149867), commit `798a6481409fa1191a24b5055aa620ae922d0fad`.
- Extension HMAC + n8n E2E : **en attente du run GitHub** sur cette modification; ne pas annoncer comme validée avant son succès.

## Fichiers examinés/corrigés

`class-tuc-compte.php`, `class-tuc-caisse.php`, `class-tuc-commande.php`, `class-tuc-push.php`, `tests/wp-docker-regression.php`, `tests/n8n/`, `compose.yaml` et `.github/workflows/wordpress-docker-smoke.yml`.
