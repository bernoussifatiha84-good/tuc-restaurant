# Roadmap produit & technique — TUC Market

**Version :** 1.0 · **Date :** 5 novembre 2026  
**Horizon indicatif :** 14–18 semaines pour un MVP pilote, avec 2 développeurs full-stack, un designer disponible à temps partiel et une personne métier/QA. Les durées sont des estimations de capacité, pas des dates promises.

## 1. Vision produit

Construire une marketplace marocaine de restaurants **premium dans l’expérience, gratuite dans l’usage**, où :

1. un client peut trouver un commerce et commander sans formation ni friction;
2. une personne peu à l’aise avec le numérique peut comprendre chaque étape, notamment sur un téléphone ancien ou une connexion lente;
3. un restaurateur peut créer sa boutique, publier son menu, ses prix, ses horaires et ses modes de service depuis son téléphone;
4. le restaurant garde la maîtrise de ses commandes et effectue lui-même la livraison;
5. les fonctions essentielles restent gratuites pour les clients et les restaurants, sans commission cachée ni frais imposés au moment de commander.

### Hypothèse produit à confirmer

Pour cette feuille de route, « gratuite » signifie : **pas de frais de plateforme pour le client, pas de commission sur les ventes, pas de frais pour créer une boutique et publier un menu**. Les coûts d’hébergement, d’assistance et de modération restent réels : avant un déploiement large, le propriétaire doit valider un budget et une source de financement transparente. Toute option future payante doit rester facultative et ne jamais verrouiller les fonctions de base.

## 2. Principes UX non négociables

- **Une action principale par écran**, avec un bouton explicite et un retour visible après chaque action.
- **Commande sans compte obligatoire**; ne demander que les informations indispensables à la livraison ou au retrait.
- Libellés simples, français clair, Darija/arabe prévus dès la conception; pas de jargon tel que « checkout », « SKU » ou « fulfillment » dans l’interface.
- Grandes zones tactiles (cible ≥ 48 px), texte confortable (base ≥ 16 px), contraste WCAG 2.2 AA, navigation clavier et lecteur d’écran.
- Sauvegarde automatique des brouillons de boutique et de menu; avertir avant toute perte de saisie.
- Prix, frais, mode et adresse récapitulés clairement avant l’envoi; aucun montant ne dépend uniquement du navigateur.
- Fonctionnement robuste sur réseau lent : pages légères, images adaptées, états de chargement et possibilité de réessayer sans créer de doublon.
- Le service n’essaie pas de « deviner » une position ou un prix : toute donnée estimée est présentée comme telle et peut être corrigée.

## 3. Personnes et parcours prioritaires

### Client
Trouver un commerce → choisir livraison / retrait / commande à table → parcourir un menu lisible → composer le panier → vérifier le prix final → envoyer → suivre l’état → payer en espèces au restaurant ou à la livraison.

### Restaurateur sur téléphone
Créer un accès → créer la boutique → ajouter le nom, une photo, l’adresse, les horaires et les modes de service → ajouter quelques plats avec prix et options → prévisualiser → publier → recevoir et traiter la première commande.

### Personnel de caisse
Se connecter à une caisse privée → être alerté d’une nouvelle commande → accepter ou refuser → faire progresser son statut → retrouver l’historique même après une perte temporaire de réseau.

## 4. État de départ du code

Le dépôt contient le thème `tuc-theme` et le plugin `tuc-market-core` (version indiquée dans le snapshot : 0.8.5). Le code inclut déjà les CPT, les fiches restaurant, une API de commande, les QR, des fonctions de compte, une PWA de caisse et Web Push. Le thème dispose de la page d’accueil, d’un gabarit de restaurant et de variantes d’images.

**Présence de code ne signifie pas validation de production.** La couverture fonctionnelle, la sécurité, les tests, la configuration des environnements et l’état réel de l’UAT doivent être vérifiés avant d’exposer de vrais restaurants ou clients.

Les principaux trous fonctionnels connus sont : panier client complet, suivi de commande client, espace restaurateur de création/gestion, parcours de publication du menu, géolocalisation fiable et import OCR. Le code de commande ne suffit pas tant que le client ne peut pas construire et envoyer un panier utilisable.

## 5. Phases et critères de sortie

### Phase 0 — Stabiliser le socle et sécuriser la livraison (1–2 semaines)

**But :** savoir exactement quel code est déployé, où il va, et pouvoir le tester sans toucher aux clients.

- Comparer le snapshot importé avec la branche GitHub de référence; consigner les écarts et fixer la version du plugin/thème.
- Confirmer les environnements `dev`, `uat`, `prd`, leurs URLs/racines et les règles d’approbation; ne jamais essayer une release production pour « voir si ça marche ».
- Révoquer/renouveler les identifiants exposés dans l’archive de passation et vérifier les secrets d’Actions sans les afficher dans les logs.
- Monter un environnement de test reproductible (WordPress, PHP 8.2, base compatible avec UAT et production), puis activer les erreurs PHP en test seulement.
- Ajouter des tests de régression pour l’activation/désactivation du plugin, les CPT, les permissions REST, les totaux, l’idempotence et l’endpoint santé.
- Faire un audit de sécurité du plugin, notamment auth caisse, liens magiques, Web Push, webhook WhatsApp, limitation de débit, statut de commande et données personnelles.
- Vérifier le workflow SSH : bon tag/type de référence, cible configurée, transfert complet, activation, purge, health check et rollback documenté.

**Critères de sortie :** lint PHP/JS/CSS vert; tests de sécurité critiques automatisés; déploiement réussi sur `dev` puis `uat`; restauration de sauvegarde répétée; aucune clé dans Git, les artefacts ou les logs.

### Phase 1 — Espace restaurateur et création de boutique (2–3 semaines)

**But :** un restaurateur peut créer sa boutique de bout en bout depuis son téléphone, sans wp-admin.

- Définir les rôles `tuc_owner` et `tuc_staff`, les capacités minimales et la relation propriétaire ↔ boutique.
- Construire un espace pro mobile, distinct de l’administration WordPress.
- Assistant en étapes courtes : identité de la boutique, contact, adresse/quartier, horaires, livraison/retrait/sur place, zone et frais, image/logo, aperçu.
- Gérer les états `brouillon`, `à vérifier`, `publié`, `suspendu`; éviter qu’un utilisateur puisse publier ou modifier la boutique d’un autre.
- Vérifier le téléphone par un mécanisme explicite, permettre la récupération du compte et prévoir une vérification humaine des premiers commerces.
- Créer des URL de boutique lisibles, uniques, réservées contre les collisions avec pages et routes système.

**Critères de sortie :** au moins 8 restaurateurs pilotes terminent la création d’une boutique sur mobile; 80 % y arrivent sans assistance; aucune permission inter-boutique ne passe les tests.

### Phase 2 — Création et gestion du menu (2–3 semaines)

**But :** publier et modifier un menu fiable sans compétence technique.

- CRUD de catégories et d’articles : nom, description courte, prix en MAD, disponibilité, photo, options et suppléments.
- Réordonner simplement les catégories et plats; activer/désactiver un plat sans le supprimer.
- Import CSV avec modèle téléchargeable, aperçu, validation des erreurs et confirmation avant écriture.
- Upload photo avec compression, recadrage guidé et formats AVIF/WebP/JPG; texte alternatif et limites de taille.
- Prévisualisation publique avant publication; brouillon sauvegardé automatiquement.
- Afficher le statut « menu complet / incomplet » et signaler les champs qui bloquent la publication.
- OCR éventuel en **assistant de saisie** seulement : rien n’est publié sans validation explicite du restaurateur.

**Critères de sortie :** un commerce ajoute 20 articles en moins de 15 minutes depuis son téléphone; prix/option validés côté serveur; import invalide sans écriture partielle; modifications auditables.

### Phase 3 — Découverte et commande client (3–4 semaines)

**But :** permettre une vraie commande simple et sûre depuis la fiche restaurant.

- Fiche mobile rapide : menu, horaires, disponibilité, modes de service, frais et minimum clairement présentés.
- Recherche par nom, cuisine, catégorie et quartier; gérer proprement les résultats vides et les commerces fermés.
- Panier persistant à court terme, options comprises, quantités modifiables, suppression facile.
- Livraison : adresse et zone desservie; retrait et QR à table : demander uniquement les détails utiles.
- Calcul du total côté serveur depuis les prix publiés; vérifier disponibilité, frais, minimum et restaurant avant de créer la commande.
- Prévenir double clic et répétitions réseau par idempotence; limiter l’abus sans bloquer les familles partageant un téléphone.
- Confirmation accessible avec numéro de commande, commerce, montant, mode et délai estimé; expliquer le paiement en espèces.
- Prévoir un statut de suivi client et des notifications compréhensibles sans imposer un compte.

**Critères de sortie :** parcours E2E de commande valide sur Android/iPhone; impossible de modifier un prix via le navigateur; tests contre commande vide, plat d’un autre commerce, double envoi, commerce fermé et erreur réseau; aucune commande fantôme.

### Phase 4 — Opérations de caisse robustes (2–3 semaines)

**But :** la commande arrive à la bonne boutique et reste traitable quand le réseau ou Push échoue.

- Associer chaque caisse aux boutiques autorisées; vérifier chaque permission côté serveur.
- Afficher commandes récentes, détails lisibles, âge de la commande et transitions autorisées.
- Accepter/refuser, passer en préparation/prête/terminée; demander un motif pour les refus si utile.
- Notifications Push avec permission expliquée, son/vibration réglables et déduplication.
- Fallback par rafraîchissement contrôlé/polling quand Push est indisponible; badge « connexion interrompue » et rattrapage au retour réseau.
- Mesurer et alerter sur les commandes non vues; ne pas considérer la livraison Push comme une garantie de réception.
- Tester installation PWA, reconnexion, abonnement expiré, plusieurs appareils, session expirée et heure incorrecte.

**Critères de sortie :** 50 commandes de test consécutives reçues sans perte; mode de secours opérationnel; accès d’un restaurant à une autre caisse bloqué; statut et historique cohérents après reconnexion.

### Phase 5 — Pilote, accessibilité et qualité premium (2–3 semaines)

**But :** valider l’expérience avec de vrais usages avant d’ouvrir la marketplace.

- Pilote limité à 8–15 commerces dans une zone de Casablanca; support humain direct.
- Tests modérés avec personnes âgées, utilisateurs peu familiers du smartphone et restaurateurs qui n’ont pas d’ordinateur.
- Corriger les blocages observés avant d’ajouter des fonctions secondaires.
- Tester FR et AR/RTL, zoom 200 %, lecteur d’écran, clavier, contraste, cibles tactiles et messages d’erreur.
- Optimiser images et rendu; test sur téléphone d’entrée de gamme et réseau lent.
- Rédiger politique de confidentialité, consentements, conservation/suppression des données et procédure de support; faire valider les obligations locales avant ouverture générale.
- Former les restaurateurs : guide illustré d’une page, QR imprimable, procédure de fermeture/indisponibilité et support de première commande.

**Critères de sortie :** aucun bug bloquant/critique; taux de commande réussie ≥ 98 % sur le pilote; au moins 80 % des nouveaux restaurateurs publient leur première version de menu sans assistance; incidents et demandes support suivis.

### Phase 6 — Ouverture progressive et croissance (après le pilote)

- Étendre quartier par quartier, avec un seuil minimum de commerces actifs par zone.
- Mettre en place modération des fiches, signalements, fermeture temporaire et prévention des faux commerces.
- Ajouter horaires spéciaux, promotions, favoris, avis vérifiés et langues selon les données du pilote.
- Ajouter l’OCR de menus uniquement si le flux manuel est stable, avec contrôle humain et historique des corrections.
- Faire des tests de charge réalistes; instrumenter les requêtes et les index avant de migrer des données vers des tables dédiées.
- Étudier une source de financement durable et transparente; ne pas ajouter de commission sans décision explicite et communication claire.

## 6. Priorisation produit — MVP indispensable vs plus tard

### Indispensable pour appeler le produit « marketplace utilisable »

1. Boutique créée et gérée par le restaurateur.
2. Menu avec catégories, prix, photos et disponibilité.
3. Pages publiques propres, accessibles et indexables.
4. Panier et total serveur exact.
5. Commande et réception en caisse, avec solution de secours.
6. Suivi clair, annulation/refus et aide en cas de problème.
7. Parcours de base en français, arabe/RTL planifié dès les composants.
8. Confidentialité, sécurité et sauvegarde restaurable.

### À ne pas lancer avant validation du MVP

OCR automatique sans validation, fidélité, chat, recommandations algorithmiques, application native, paiement en ligne, flotte de livraison TUC, tableau de bord analytique complexe, personnalisation libre du site par chaque restaurant.

## 7. Architecture cible (sans sur-ingénierie)

- **Plugin `tuc-market-core` :** règles métier, CPT, rôles/capacités, commandes, validations et REST. Le thème ne doit pas être indispensable pour conserver les données métier.
- **Thème :** rendu public et composants d’interface; pas de contrôle d’accès ni calcul de prix métier.
- **Espace restaurateur et caisse :** interfaces front-end dédiées, auth/capabilities contrôlées côté serveur; jamais exposer wp-admin comme UX principale.
- **API :** namespace versionné, schéma/validation des entrées, permissions explicites, erreurs stables et documentation des endpoints.
- **Données :** commencer avec les API WordPress et indexer les requêtes; envisager des tables dédiées seulement après mesure des volumes et des lenteurs.
- **Compatibilité base :** tester SQLite UAT et MySQL cible avec les API WordPress; ne pas écrire de SQL spécifique à un seul moteur sans décision documentée.
- **Images :** originaux normalisés et variantes générées; ne pas charger les grands originaux dans les rails mobiles.
- **Événements :** garder un historique de statut et des événements idempotents; éviter une architecture microservices avant d’avoir des besoins mesurés.

## 8. Sécurité, confidentialité et confiance

- Authentification forte pour les propriétaires et le personnel; capacités vérifiées à chaque requête et séparation stricte entre boutiques.
- Calcul serveur du total, des suppléments, frais et disponibilités; le client n’est jamais source de vérité.
- Validation/sanitation des entrées, échappement des sorties, nonce/CSRF si cookie de session, limitation de débit et idempotence.
- Ne jamais journaliser token magique, secret webhook, contenu de commande complet ou clé privée; limiter la conservation du téléphone et des adresses.
- Liens magiques à usage unique, expiration courte, protection contre énumération et réponses d’erreur qui ne révèlent pas l’existence d’un compte.
- Web Push traité comme best effort; aucun secret privé VAPID dans le JavaScript ou le dépôt.
- Procédure de sauvegarde et de restauration testée; revue des plugins tiers et mises à jour régulières.
- Toute fuite de credential déclenche révocation/rotation, vérification des logs et communication interne.

## 9. Mesures de succès

Mesurer avec des événements minimaux, sans collecter plus de données personnelles que nécessaire.

| Indicateur | Cible initiale du pilote |
|---|---:|
| Temps médian pour créer une boutique et publier 5 articles | ≤ 15 min |
| Restaurateurs pilotes qui terminent sans aide | ≥ 80 % |
| Temps médian entre ouverture de fiche et envoi de commande | ≤ 3 min |
| Commandes envoyées avec calcul serveur cohérent | 100 % |
| Commandes livrées à la caisse sans perte (Push ou fallback) | ≥ 99 % |
| Échecs techniques bloquant la commande | < 1 % |
| LCP mobile sur 4G pour fiche/menu | ≤ 2,5 s au 75e percentile |
| Erreurs critiques d’accessibilité sur parcours commande | 0 avant ouverture large |
| Incidents de permissions inter-boutiques | 0 |

Les cibles seront recalibrées à partir des mesures réelles du pilote; elles ne doivent pas inciter à pousser une interface confuse ou des commandes non fiables.

## 10. Décisions à obtenir du propriétaire avant la phase 1

1. Confirmer que « gratuit » veut bien dire **zéro commission et zéro frais essentiels** pour les deux côtés.
2. Confirmer les modes de commande MVP : livraison par le restaurant, retrait, table/QR; paiement en espèces uniquement.
3. Choisir langues de lancement : français + arabe dès MVP, ou français d’abord avec structure RTL testée dès le départ.
4. Choisir le processus d’approbation des restaurants (publication immédiate avec modération a posteriori, ou validation manuelle avant publication).
5. Valider la zone pilote, les personnes support et le budget opérationnel.
6. Confirmer la cible WordPress/base et l’environnement UAT avant le premier déploiement du plugin importé.

## Définition de « prêt à ouvrir au public »

Pas de lancement public tant que : création autonome de boutique, publication du menu, commande réelle, réception en caisse, suivi client, paiement en espèces, permissions par boutique, sauvegarde/restauration, tests de charge raisonnables, accessibilité des parcours principaux et procédures de support ne sont pas validés ensemble sur l’UAT.
