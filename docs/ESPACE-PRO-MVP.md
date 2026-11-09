# Espace Pro, options, panier et suivi TUC

**État :** implémentation de code sur la branche `arena/87e5dbce-tuc-restaurant`, PR #1 ouverte. Smoke test WordPress Docker réussi sur le code au commit `1cf6f04` (run [42145910794](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42145910794)). Rien n’est déployé; aucune donnée réelle n’a été migrée et aucun compte restaurateur/gestionnaire n’a été affecté. Ce document n’est pas une autorisation de mise en service.

## 1. Rôles et isolation

- Les rôles `tuc_restaurateur`, `tuc_gestionnaire`, `tuc_caissier` et `tuc_serveur` n’obtiennent pas `manage_options`. Le rôle serveur reste sans accès opérationnel activé.
- `TUC_Acces::peut()` vérifie conjointement une capacité métier, un restaurant TUC publié et l’affectation explicite `tuc_resto_id`; la façade est utilisée par les contrôles caisse et Espace Pro.
- L’affectation d’un compte reste une action admin-only et n’est jamais automatique.
- Les routes de menu, commandes, statuts et boutique revalident l’appartenance au tenant côté serveur. Le tableau Espace Pro n’envoie pas de téléphone client.

## 2. Espace Pro mobile

`/espace-pro/` propose les onglets **Menu**, **Commandes** et **Boutique**, adaptés aux petits écrans. Le menu permet brouillon/publication, modification, disponibilité, promotion simple, groupes d’options, photo et aperçu. L’onglet commandes liste les commandes récentes du seul restaurant et permet les transitions de service; l’onglet Boutique règle pause/ouverture, minimum, frais, modes et nombre de tables.

| Méthode | Route | Effet |
| --- | --- | --- |
| GET | `/tuc/v1/pro/restaurant` | Lit le restaurant assigné et ses réglages nécessaires |
| PUT/PATCH | `/tuc/v1/pro/restaurant` | Met à jour les réglages validés de la boutique |
| GET | `/tuc/v1/pro/menu` | Plats publiés et brouillons du seul tenant |
| POST | `/tuc/v1/pro/menu` | Crée un plat, brouillon par défaut |
| PUT/PATCH | `/tuc/v1/pro/menu/{id}` | Modifie un plat appartenant au tenant |
| DELETE | `/tuc/v1/pro/menu/{id}` | Archive en brouillon sans supprimer les historiques |
| POST | `/tuc/v1/pro/menu/{id}/photo` | Téléverse JPEG/PNG/WebP, 5 Mo maximum |
| GET | `/tuc/v1/pro/commandes` | Liste les commandes du restaurant assigné, filtre de statut possible |
| POST | `/tuc/v1/pro/commandes/{id}/statut` | Met à jour le statut après contrôle de capacité et de tenant |
| GET public | `/tuc/v1/menu/{slug}` | Menu publié et informations client non sensibles |

Le contenu WordPress natif du CPT menu n’est pas rendu éditable aux rôles métier; l’API native du CPT ne remplace pas les routes tenant-scoped.

## 3. Options riches et migration

Le schéma v2 prend en charge groupes, min/max, obligatoire/facultatif, supplément, disponibilité des choix et conditions sur un groupe précédent. Le client n’envoie que les identifiants; le serveur valide la sélection et recalcule le prix. Les commandes conservent un instantané des libellés et prix choisis. L’ancien format `{id, nom, prix}` reste lisible.

Une migration explicite est disponible via WP-CLI :

```sh
wp tuc-options migrate                 # dry-run uniquement, aucune écriture
wp tuc-options migrate --apply         # applique après sauvegarde et revue du dry-run
wp tuc-options migrate --apply --limit=5000
wp tuc-options rollback                 # dry-run de restauration des options legacy
wp tuc-options rollback --apply        # restaure les valeurs d’origine sauvegardées
```

L’application est idempotente, traite les articles par lots, sauvegarde chaque valeur d’origine dans `tuc_options_legacy_backup` et laisse intacte toute définition invalide en signalant son identifiant. Une relance reprend les éléments restants. La commande n’a pas été lancée sur une base UAT/production; avant une application réelle, exiger une sauvegarde complète vérifiable, tester la restauration et faire approuver le dry-run. Le pourcentage `tuc_promo_pct` reste une remise immédiate; les promotions datées/cumulables et les horaires/catégories ne sont pas inclus.

## 4. Panier client et commande

La fiche publique offre personnalisation d’options, quantités, retrait de ligne, panier local, modes de service, frais, minimum et confirmation. Le panier persistant ne stocke pas le téléphone. À la confirmation, le serveur revalide restaurant, disponibilité, tables/mode, options, prix, frais et minimum, puis crée une commande cash. La clé d’idempotence et un verrou atomique évitent les doublons, y compris lors d’envois concurrents, et permettent de restituer le même lien de suivi lors d’une nouvelle tentative.

Aucun paiement en ligne n’est ajouté : le règlement est sur place. WooCommerce et son inventaire restent hors de ce périmètre; rien n’a été désactivé, supprimé ou migré.

## 5. Suivi client de commande

`POST /tuc/v1/commande/suivi` accepte un jeton opaque transmis dans le corps JSON. Le jeton est dérivé d’une clé aléatoire d’idempotence et du secret WordPress, seul son hash est stocké; sa validité est de 30 jours et les requêtes sont limitées en débit. La réponse ne révèle ni téléphone, ni lignes d’articles, ni total. Le navigateur conserve le jeton localement pour proposer le suivi et peut l’effacer de l’appareil. Ce lien n’est pas un mécanisme d’authentification de compte.

## Tests et limites de mise en service

- Le smoke WordPress Docker passe sur le commit `1cf6f04` (run [42145910794](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42145910794)), dont les régressions couvrent migration/restauration options, rôle et isolation inter-tenant, réglages mobile, commandes et suivi sans données client sensibles.
- Le parseur PHP/`node --check` local ne remplacent pas le lint PHP et l’exécution runtime WordPress Docker.
- Photos réelles, navigateurs mobiles, RTL/arabe, charge et essais avec restaurateurs consentants restent à valider sur environnement de test.
- Pas de migration de données réelles, aucun compte métier affecté, aucun déploiement.
- Inventaire WooCommerce maintenu en lecture seule; ne pas exposer de valeurs sensibles et ne pas désactiver/supprimer/migrer WooCommerce sans validation explicite et sauvegarde vérifiable.
