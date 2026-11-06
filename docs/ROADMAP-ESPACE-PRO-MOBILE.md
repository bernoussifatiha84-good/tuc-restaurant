# Feuille de route — Espace restaurateur mobile TUC

**État :** MVP technique implémenté dans la branche PR, non déployé. Aucun compte métier n’a été affecté et aucun restaurateur réel n’a encore testé l’interface.

**Dépend de :** [Roadmap produit & technique](ROADMAP-MARKETPLACE.md), phases 0–4.

## Décision d’architecture proposée

Faire du plugin **TUC Market Core la source de vérité** des restaurants, menus, options, promotions et commandes. Construire une interface restaurateur mobile-first dédiée, servie par le plugin/thème, sans demander aux restaurateurs d’utiliser l’admin WordPress.

**L’inventaire UAT est réalisé, mais aucune suppression, migration ou nouvelle désactivation n’est autorisée sans validation.** WooCommerce est déjà inactif sur l’UAT; ses données/configurations résiduelles doivent être évaluées avant toute décision. Voir le [relevé détaillé](INVENTAIRE-WOOCOMMERCE-UAT-2026-11-05.md). Éviter de maintenir deux catalogues ou deux flux de commande indépendants.

Cette décision vise le produit souhaité; elle ne signifie pas que le plugin actuel possède déjà toutes ces fonctions.

## Point de départ vérifié et état actuel

Le plugin déclare les contenus `tuc_resto`, `tuc_menu_item` et `tuc_commande`; la caisse TUC reste le canal de réception. Le relevé WooCommerce UAT est strictement en lecture seule : WooCommerce demeure installé mais inactif, sans suppression, migration ou nouvelle désactivation.

### MVP implémenté dans la branche PR — pas encore déployé

- Page d’affectation des rôles TUC aux comptes existants, réservée aux administrateurs WordPress; l’affectation exige un restaurant publié et aucun compte n’est assigné automatiquement.
- Routes dédiées pour créer, lire, modifier, publier et archiver les plats, ainsi que téléverser une photo JPEG/PNG/WebP (5 Mo). Les réponses et mutations sont limitées au restaurant explicitement affecté; les rôles TUC n’ont pas accès à l’API REST native d’édition du CPT.
- Modèle d’options version 2 avec groupes, choix unique/multiple via min/max, choix requis/facultatifs, suppléments, disponibilité et conditions sur un groupe précédent. Les anciens choix plats restent lus sans migration forcée.
- Espace Pro mobile pour le menu : brouillons/publication, disponibilité, pourcentage de promotion, groupes d’options, photos et aperçu.
- Fiche client raccordée au menu public, à un panier persistant localement et à la création de commande. Le serveur revalide restaurant, table/mode, disponibilité, options, prix, minimum et frais; la commande conserve un instantané lisible.
- Les promotions MVP sont des pourcentages sans programmation de dates; aucun paiement en ligne n’est ajouté (règlement sur place).

### Validation encore nécessaire

- Le code reste dans la branche PR, non déployée; aucun accès de restaurateur n’a été accordé.
- Smoke test WordPress Docker complet passé sur le commit `2dd07d7` (run [42141785151](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42141785151)), incluant syntaxe PHP, régressions WordPress/TUC et E2E n8n. C’est une validation CI, pas un déploiement.
- Tester l’interface sur de vrais appareils et avec des restaurateurs consentants; aucun test utilisateur réel n’a été effectué.
- Faire confirmer par le propriétaire l’usage des pages brouillon et réglages PayPal/MaxMind avant toute action WooCommerce. Le rapport d’inventaire [lecture seule](INVENTAIRE-WOOCOMMERCE-UAT-2026-11-05.md) ne contient pas leurs valeurs sensibles.

## Principes UX mobile non négociables

- Concevoir d’abord pour un téléphone de largeur 360 px; l’interface doit aussi fonctionner à 320 px et sur tablette, sans exiger un ordinateur.
- Navigation courte et stable, par exemple : **Accueil · Menu · Commandes · Boutique**. Ne montrer à chaque rôle que les fonctions qu’il a le droit d’utiliser.
- Grandes zones tactiles (au moins 48 px), texte lisible (base 16 px), contraste accessible, boutons explicites et retours immédiats après chaque action.
- Une tâche principale par écran. Formulaires longs découpés en étapes, sauvegarde de brouillon, reprise après interruption et confirmation avant publication ou suppression.
- États réseau visibles : chargement, enregistré, en attente, échec avec action « Réessayer ». Une modification non confirmée par le serveur ne doit jamais sembler publiée.
- Utiliser des saisies adaptées au téléphone : clavier numérique pour les prix, MAD clairement affiché, sélection simple pour disponibilité et horaires, prise/import de photos avec compression.
- Prévisualisation exacte de la fiche publique sur téléphone avant publication.
- Préparer l’interface pour le français et le RTL arabe dès la conception; faire relire les traductions et tester les nombres/prix en RTL avant d’activer l’arabe.
- Tester sur de vrais appareils Android/iOS d’entrée de gamme et réseau lent, avec des restaurateurs; ne pas valider l’UX uniquement sur ordinateur ou sur un prototype visuel.

## Phases de réalisation

### Phase A — Découverte, inventaire et décision WooCommerce

**Travail**
- Inventaire technique agrégé UAT terminé (produits, variantes, commandes HPOS et legacy, coupons, pages, extensions et noms d’options) : voir le [rapport](INVENTAIRE-WOOCOMMERCE-UAT-2026-11-05.md). Faire confirmer par le propriétaire du site l’usage réel des pages brouillon et des réglages PayPal/MaxMind, sans exposer leurs valeurs.
- Le relevé trouve un plat TUC publié et aucun produit WooCommerce; confirmer avec les responsables si ce plat représente le menu actuellement utilisé et s’il existe des dépendances hors de la base WordPress.
- Définir le périmètre : un propriétaire peut-il gérer plusieurs restaurants? Quels rôles existent? Qui peut modifier les prix et publier une promotion?
- Faire un export et une sauvegarde vérifiable avant toute migration ou désactivation de WooCommerce.

**Sortie attendue** : décision écrite sur les données à migrer, les dépendances à conserver et les fonctions WooCommerce à retirer éventuellement. Aucun plugin ou contenu n’est supprimé pendant l’inventaire.

### Phase B — Modèle métier et sécurité multi-restaurant

**État dans cette PR** : l’interface admin d’affectation, les routes REST menu/médias, les contrôles d’appartenance et le verrouillage de l’API native du CPT sont ajoutés. Les tests couvrent les rôles, les accès croisés et les affectations invalides. Aucun compte n’est affecté automatiquement; le changement n’est pas déployé en UAT/production. Le téléversement réel et les parcours sur appareil restent à valider en environnement de test.

**Travail**
- Définir les rôles minimaux : propriétaire, responsable, caissier et serveur; préciser les actions autorisées pour chacun.
- Associer chaque utilisateur aux identifiants de restaurant autorisés. Ne jamais faire confiance à un `restaurant_id` envoyé par le navigateur sans vérifier l’appartenance côté serveur.
- Ajouter des capacités et des routes REST dédiées; les restaurateurs ne reçoivent pas `manage_options` et n’accèdent pas à l’admin WordPress pour gérer le menu.
- Restreindre lecture, création, modification, suppression et téléversement de médias au(x) restaurant(s) autorisé(s). Enregistrer qui a modifié prix, disponibilité ou promotion.
- Protéger les sessions, les appels REST, les téléversements et les liens d’invitation/connexion.

**Critères de sortie**
- Tests automatisés couvrant les quatre rôles et l’accès croisé entre deux restaurants.
- Les tests d’accès non autorisé couvrent aussi les médias, les options, les promos et les statuts de commande.
- Un restaurateur ne peut ni lister, ni deviner, ni modifier les contenus d’un autre commerce, même en changeant l’ID dans une requête.
- Tant que ces critères ne passent pas, aucun compte restaurateur ne reçoit de droits d’édition en production.

### Phase C — Prototype UX mobile et tests avec restaurateurs

**État dans cette PR** : prototype responsive du gestionnaire de menu réalisé (cible 320–360 px, tablette et aperçu client). Il n’inclut pas encore le tableau de bord des commandes/boutique. Les tests avec de vrais restaurateurs et appareils n’ont pas eu lieu.

**Écrans à prototyper**
1. Connexion/accueil Pro, avec la boutique active clairement nommée.
2. Vue d’ensemble : boutique ouverte/fermée, état du menu, commandes récentes et actions rapides.
3. Liste des plats : recherche, catégories, prix, disponibilité, promo et action rapide « Indisponible ».
4. Création/modification de plat guidée, avec aperçu avant publication.
5. Options d’un plat avec explication visuelle du minimum, maximum, prix et conditions.
6. Commandes et écran d’état pour les rôles autorisés.

Tester les tâches réelles — ajouter un plat, changer son prix, le rendre indisponible, créer une option et lancer/arrêter une promotion — avec plusieurs restaurateurs sur leur propre téléphone. Corriger les blocages observés avant de coder tous les écrans.

**Critère de sortie** : des utilisateurs de test terminent ces tâches sans aide, comprennent si la modification est enregistrée/publiée et ne confondent pas « supprimer » et « rendre indisponible ».

### Phase D — Catalogue TUC et règles d’options fiables

**État dans cette PR** : schéma d’options v2 et validation/calcul de prix côté serveur implémentés; les anciennes listes plates sont compatibles. La promotion MVP est un pourcentage immédiat, sans période programmée ni cumul avancé. Les tests couvrent groupes requis, min/max, supplément, disponibilité, condition et ancien format.

**Modèle cible d’un plat**
- Restaurant propriétaire, catégorie, nom, description, photo, prix de base, disponibilité et statut brouillon/publié.
- Prix promotionnel avec dates de début/fin et règles explicites de cumul; garder le prix normal visible et calculer le prix final côté serveur.
- Groupes d’options réutilisables ou propres au plat, par exemple taille, garniture, sauce, boisson ou supplément.

**Règles d’options à couvrir**
- Choix unique ou multiple.
- Minimum/maximum sélectionnable et groupe obligatoire/facultatif.
- Supplément propre à chaque choix.
- Affichage conditionnel d’un groupe selon un choix parent (ex. le choix « Menu » affiche les choix boisson; le format « XL » ajoute un supplément).
- Disponibilité d’un choix et limites raisonnables de quantité.
- Explication lisible au client et total recalculé sur le serveur à chaque étape.

À la commande, enregistrer un instantané lisible du plat, des choix, des prix et des règles appliquées. Une modification ultérieure du menu ne doit pas réécrire les anciennes commandes.

**Critères de sortie** : tests de calcul prix côté serveur, règles min/max, options invalides, choix conditionnels, promo expirée et prix falsifié par le navigateur. L’aperçu et le total avant confirmation correspondent au calcul serveur.

### Phase E — Tableau de bord mobile MVP

**État dans cette PR** : la liste, création/modification, brouillon/publication, disponibilité, promotion %, options, photo et aperçu sont disponibles dans le prototype mobile. Catégories, duplication, horaires, accueil, commandes et suivi des modifications ne sont pas inclus dans ce premier périmètre.

**Ordre de livraison suggéré**
1. Liste des plats et changement rapide de disponibilité.
2. Ajout/modification de plat et catégorie.
3. Gestion des prix et aperçu de la fiche publique.
4. Groupes d’options simples, puis règles conditionnelles.
5. Promotions programmées et indicateur clair de période.
6. Informations de la boutique : horaires, modes de service, pause temporaire et adresse.

Prévoir sauvegarde brouillon, duplication de plat, confirmation des changements sensibles, import photo, compression avant envoi et erreurs récupérables. Les actions rapides doivent afficher clairement quel restaurant est modifié.

**Critères de sortie**
- Un restaurateur peut ajouter un plat, modifier un prix, activer/désactiver un plat et gérer une option depuis un téléphone.
- Toute action confirme son résultat; les changements non enregistrés sont récupérables ou signalés.
- L’aperçu client, le menu publié et la caisse utilisent les mêmes données TUC.
- Pas de commande ou de paiement réel utilisé dans les tests.

### Phase F — Panier, commande et caisse de bout en bout

**État dans cette PR** : la fiche client propose personnalisation, panier, mode de service, téléphone, total confirmé, idempotence et création d’une commande cash à la caisse. Le smoke test complet est passé au run [42141785151](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42141785151); aucun paiement réel ni pilote UAT n’a eu lieu.

- Raccorder le panier client TUC au même catalogue et aux mêmes identifiants d’options.
- Revalider côté serveur : restaurant, disponibilité, options, quantités, promo, frais et total au moment de confirmer.
- Sauvegarder un instantané de commande; associer la commande à un seul restaurant.
- Transmettre la commande à la caisse TUC avec le mode de service et le détail des options; prévoir refus, indisponibilité, annulation et réseau interrompu.
- Préparer le suivi client seulement après fiabilisation de la réception caisse.

**Critères de sortie** : parcours automatisé du menu mobile à la commande de test en caisse, sans prix modifiable côté client ni accès à la caisse d’un autre restaurant.

### Phase G — Pilote et décision sur WooCommerce

- Piloter avec un petit groupe de restaurants consentants et des comptes de test; mesurer les erreurs, temps de tâche, commandes et demandes d’assistance.
- Faire tester les changements de menu par les restaurateurs eux-mêmes, pas uniquement par l’équipe TUC.
- Vérifier sauvegarde/restauration avant et après toute migration WooCommerce.
- WooCommerce étant déjà inactif sur l’UAT, ne pas le réactiver, le désinstaller, ni supprimer ses pages/options avant confirmation de leur usage et décision écrite; toute migration éventuelle exige une sauvegarde vérifiable.
- Élargir le pilote seulement après réussite des tests de sécurité, d’UX et de restauration.

## Indicateurs UX à suivre au pilote

- Temps pour ajouter un plat et publier son menu depuis un téléphone.
- Taux de tâches terminées sans aide et taux d’erreurs sur prix/options.
- Nombre d’échecs de sauvegarde/téléversement et temps de reprise après réseau faible.
- Taux d’accès interdit correctement bloqué entre restaurants.
- Délai pour rendre un plat indisponible et constater le changement côté client.
- Retour des restaurateurs : comprennent-ils les promotions, les options et l’état publié?

Ces indicateurs sont à mesurer; ils ne sont pas encore atteints ni validés.

## Hors périmètre de la première version du tableau de bord

Paiement en ligne, OCR qui publie automatiquement, applications natives, analytics complexes et synchronisation de deux catalogues WooCommerce/TUC. Ils pourront être examinés après validation du menu mobile, de l’isolation et du flux de commande.
