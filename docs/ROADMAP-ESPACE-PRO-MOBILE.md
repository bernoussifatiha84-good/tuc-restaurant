# Feuille de route — Espace restaurateur mobile TUC

**État :** le code des cinq jalons (rôle/helper et isolation, migration options v2, espace mobile, panier complet, suivi de commande) est en cours d’achèvement sur la branche PR. Non déployé; aucun compte métier réel n’a été affecté et aucun restaurateur réel n’a encore testé l’interface. Le smoke WordPress Docker final doit passer après les changements avant de présenter le MVP comme vérifié.

**Dépend de :** [Roadmap produit & technique](ROADMAP-MARKETPLACE.md), phases 0–4.

## Décision d’architecture proposée

Faire du plugin **TUC Market Core la source de vérité** des restaurants, menus, options, promotions et commandes. Construire une interface restaurateur mobile-first dédiée, servie par le plugin/thème, sans demander aux restaurateurs d’utiliser l’admin WordPress.

**L’inventaire UAT est réalisé, mais aucune suppression, migration ou nouvelle désactivation n’est autorisée sans validation.** WooCommerce est déjà inactif sur l’UAT; ses données/configurations résiduelles doivent être évaluées avant toute décision. Voir le [relevé détaillé](INVENTAIRE-WOOCOMMERCE-UAT-2026-11-05.md). Éviter de maintenir deux catalogues ou deux flux de commande indépendants.

Cette décision vise le produit souhaité; elle ne signifie pas que le plugin actuel possède déjà toutes ces fonctions.

## Point de départ vérifié et état actuel

Le plugin déclare les contenus `tuc_resto`, `tuc_menu_item` et `tuc_commande`; la caisse TUC reste le canal de réception. Le relevé WooCommerce UAT est strictement en lecture seule : WooCommerce demeure installé mais inactif, sans suppression, migration ou nouvelle désactivation.

### Jalons de réalisation sur la branche PR — non déployés

- Les rôles TUC restent admin-affectés uniquement; `TUC_Acces::peut()` centralise capacité + restaurant publié + `tuc_resto_id`. Les tests couvrent l’isolation inter-tenant et les comptes non affectés.
- Options v2 incluent une migration WP-CLI en dry-run par défaut, idempotente, avec copie de sauvegarde des anciennes métadonnées par article. Aucune base réelle n’a été migrée.
- L’Espace Pro mobile comprend Menu, Commandes et Boutique : CRUD menu, disponibilités, photos/options, vue et transition des commandes, ouverture/pause, minimum, frais, modes et nombre de tables.
- Le panier client conserve les articles localement, valide le total côté serveur et crée une commande cash idempotente; le suivi client utilise un jeton opaque de 30 jours et n’expose pas le téléphone, les articles ou le total.
- Les promotions restent des pourcentages immédiats; catégories/horaires, paiement en ligne, test matériel réel, RTL/arabe et pilote restaurateur restent hors validation.

### Validation encore nécessaire

- Le code reste dans la branche PR, non déployée; aucun accès de restaurateur n’a été accordé.
- La tête précédente `656c672` avait passé le smoke WordPress Docker (run [42142107785](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42142107785)); ce résultat ne couvre pas les changements en cours. Le nouveau commit doit repasser le lint PHP, les régressions WordPress/TUC et les E2E n8n avant de déclarer la vérification finale réussie. CI n’est pas un déploiement.
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

**État dans cette PR** : ajout de `TUC_Acces::peut()` et branchement des autorisations caisse/Espace Pro; l’interface admin d’affectation, routes et verrouillage CPT restent tenant-scoped. Les tests WordPress couvrent rôles, capacités, affectations absentes et accès croisés aux commandes/menus. Aucun compte n’est affecté automatiquement; aucun accès n’est déployé. Les parcours sur appareil restent à valider en environnement de test.

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

**État dans cette PR** : l’interface responsive comporte les onglets Menu, Commandes et Boutique, avec réglages métier et transitions de commande tenant-scoped. La conception est mobile-first; vrais restaurateurs, appareils et réseau lent n’ont pas encore été testés.

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

**État dans cette PR** : schéma d’options v2, validation/tarification serveur et compatibilité legacy implémentés. `wp tuc-options migrate` propose dry-run par défaut, application idempotente et sauvegarde des valeurs originales par plat; tests de non-écriture, sauvegarde et relance. La migration n’a été exécutée que sur les fixtures Docker, pas sur des données réelles. Promotion MVP : pourcentage immédiat, sans programmation ni cumul avancé.

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

**État dans cette PR** : le menu inclut liste, création/modification, brouillon/publication, disponibilité, promotion %, options, photo et aperçu; l’espace ajoute une vue commandes et réglages boutique. Catégories, duplication, horaires, historique détaillé des modifications et analytique ne sont pas inclus dans ce périmètre.

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

**État dans cette PR** : fiche client, panier persistant, validation de prix côté serveur, idempotence et commande cash; le client reçoit aussi un jeton opaque de suivi et voit l’évolution du statut. La réponse de suivi omet PII, contenu et total. Le smoke final est en attente; aucun paiement réel ni pilote UAT n’a eu lieu.

- Les tests Docker vérifient total falsifié, idempotence, options, tenant, statut et suivi tokenisé.
- La caisse TUC reçoit la commande avec mode et détail des options; le tableau mobile permet le traitement par les rôles autorisés.
- Suivi client limité à 30 jours, nonce non requis mais jeton secret, débit limité; restauration du lien possible par nouvelle tentative avec la même clé idempotente.
- Aucun flux de paiement réel ou déploiement n’est inclus.

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
