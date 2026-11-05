# Inventaire WooCommerce — UAT

**Date de relevé :** 5 novembre 2026, 21:25 UTC  
**Exécution GitHub Actions :** [42112380548](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42112380548)  
**Mode :** lecture seule. L’étape de déploiement a été ignorée; aucun plugin, contenu, page ou réglage n’a été modifié. Le relevé ne contient aucun nom, adresse, courriel, identifiant ou détail de commande/client.

## Résultats

| Élément | Constat UAT |
|---|---|
| WooCommerce | Installé en **11.2.1**, mais **inactif** au moment du relevé. |
| Produits | **0** enregistrements; types simple, groupé, variable et externe à zéro. |
| Variantes | **0** enregistrements. |
| Coupons | **0** enregistrements. |
| Catégories produit | **1** terme de catégorie présent; il n’a aucun produit associé. |
| Commandes HPOS | Réglage HPOS `yes`; table HPOS présente, **0** commande de type `shop_order`. |
| Anciennes commandes WordPress | **0** enregistrements `shop_order` dans les articles WordPress. |
| Pages WooCommerce assignées | Boutique (ID 6), panier (ID 7), commande (ID 8) et compte (ID 9) existent toutes, mais leur statut est **brouillon**. |
| Contenus TUC | **1** restaurant publié, **1** plat publié, **0** commande TUC. Aucun titre ni contenu individuel n’a été extrait. |

Les listes de statuts sont vides pour les produits, variantes, coupons et commandes parce qu’aucun enregistrement correspondant n’a été trouvé dans les tables interrogées. `products_on_sale` n’a pas été calculé : l’extension WooCommerce est inactive; le total de produits relevé directement est néanmoins nul.

## Extensions

### Actives

- LiteSpeed Cache — 7.9.3
- TUC Market Core — 0.8.7
- Two Factor — 0.17.0
- UpdraftPlus - Backup/Restore — 1.26.14
- WP Mail SMTP — 4.10.3

### Installées mais inactives

- WooCommerce — 11.2.1
- Akismet Anti-spam: Spam Protection — 5.7.2
- Hello Dolly — 1.7.2
- SQLite Database Integration — 3.1.3

## Indices de réglages/intégrations

Deux noms d’options WooCommerce existent dans la base : `woocommerce_paypal_settings` et `woocommerce_maxmind_geolocation_settings`. **Seuls les noms ont été relevés**, jamais les valeurs, qui peuvent contenir des paramètres sensibles. Leur présence ne prouve ni que ces intégrations sont activées, ni qu’elles sont encore utilisées. La liste des extensions actives ne contient pas d’extension de paiement séparée.

## Portée et suites

- Les compteurs de produits, variantes, coupons et anciennes commandes ont été lus directement par type/statut dans les tables WordPress; les commandes HPOS ont été comptées par type/statut, sans lire les lignes de commande ni les données client.
- Le site UAT a été vérifié avant l’inventaire. Le script PHP temporaire a été supprimé après exécution.
- Le résultat montre que WooCommerce est déjà inactif sur l’UAT; **ce constat n’est pas une demande de désactivation ailleurs, de suppression, ni de migration**.
- Avant toute décision, faire valider l’usage des pages brouillon et des réglages PayPal/MaxMind par le propriétaire du site. Ne pas supprimer les extensions, options ou pages tant que cette validation et une sauvegarde vérifiable ne sont pas faites.
