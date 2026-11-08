# Plugins TUC — état du code et du site de test

Dernière consolidation documentaire : 5 novembre 2026. Les informations d’état du site ci-dessous proviennent de l’archive de passation et ne constituent pas une vérification en direct du serveur.

## Plugin métier livré dans ce dépôt

`wordpress/tuc-market-core/` contient le plugin maison **TUC Market Core**, version `0.8.5` dans le snapshot intégré. Il porte les fonctions qui doivent survivre à un changement de thème : types de contenu, commandes, comptes, QR et caisse.

| Module | Fichier principal | État d’après le code intégré |
|---|---|---|
| CPT restaurants, menus et commandes | `includes/modules/class-tuc-cpt.php` | Implémenté |
| URL de fiche restaurant à la racine | `includes/modules/class-tuc-rewrite.php` | Implémenté |
| Commandes REST | `includes/modules/class-tuc-commande.php` | Endpoint présent; le serveur recalcule les montants |
| QR restaurant/table/mode de service | `includes/modules/class-tuc-qr.php` | Implémenté |
| Quartiers et tri géographique | `includes/modules/class-tuc-geo.php` | Présent; intégration produit à compléter |
| Comptes client et liens temporaires | `includes/modules/class-tuc-compte.php` | Implémenté dans le plugin |
| Message WhatsApp prérempli | `includes/modules/class-tuc-wa-message.php` | Génération du message; ne remplace pas le panier du site |
| Notifications Web Push | `includes/modules/class-tuc-push.php` | Implémentées dans le code |
| PWA et endpoints de caisse | `includes/modules/class-tuc-caisse.php` | Implémentés dans le code |
| Endpoint de santé | `includes/class-tuc-health.php` | `/wp-json/tuc/v1/sante` |

### Fonctionnalités encore manquantes ou à valider

- Le panier client n’est pas raccordé au endpoint de commande.
- Le suivi de commande côté client est à construire.
- L’OCR/import de menus n’est pas présent.
- Le module de quartiers n’est pas encore complètement raccordé à la découverte.
- Les tests rapportés par la passation doivent être rejoués dans un environnement de test avant une mise en production.

## Extensions tierces

La passation décrit les extensions suivantes comme actives sur l’environnement UAT au moment de son relevé : WooCommerce, LiteSpeed Cache, WP Mail SMTP, Two Factor, UpdraftPlus et le plugin SQLite. Ce relevé n’est pas une vérification actuelle du serveur.

- Le code du thème utilise des conditionnels WooCommerce; ceux-ci sont désormais protégés par `function_exists()` pour éviter une erreur fatale si WooCommerce est désactivé.
- Le dépôt ne contient pas les extensions tierces. Leur installation, configuration et version doivent être gérées séparément sur WordPress.
- Aucun identifiant, mot de passe, jeton ou fichier de clé privée ne doit être commité dans ce dépôt.
