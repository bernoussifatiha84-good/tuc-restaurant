# Roadmap produit & technique — TUC Market

**Version :** 1.1 · **Date :** 5 novembre 2026

**Horizon indicatif :** 16–20 semaines pour un MVP pilote, avec 2 développeurs full-stack, un designer disponible à temps partiel et une personne métier/QA. L’intégration de deux parcours WhatsApp, la vérification humaine des commerces et l’accessibilité multilingue ajoutent du travail; les durées restent des estimations de capacité, pas des dates promises.

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
- Libellés simples et concrets; prévoir l’internationalisation français, arabe et anglais dès la conception. Garder le choix de langue visible et mémorisé. L’arabe doit fonctionner en RTL sans bricolage; garder les numéros et champs téléphoniques lisibles en LTR. Ne pas utiliser les drapeaux comme seuls choix de langue.
- Pour les personnes qui lisent peu ou pas : une action par écran, photos et icônes accompagnées de mots simples, consignes courtes, écoute audio facultative (français et, si validé, darija), notes vocales et aide humaine par téléphone. Tester avec des personnes concernées, sans stéréotypes.
- Grandes zones tactiles (cible ≥ 48 px), texte confortable (base ≥ 16 px), contraste WCAG 2.2 AA, navigation clavier et lecteur d’écran.
- Sauvegarde automatique des brouillons de boutique et de menu; avertir avant toute perte de saisie.
- Prix, frais, mode et adresse récapitulés clairement avant l’envoi; aucun montant ne dépend uniquement du navigateur.
- Fonctionnement robuste sur réseau lent : pages légères, images adaptées, états de chargement et possibilité de réessayer sans créer de doublon.
- Le service n’essaie pas de « deviner » une position ou un prix : toute donnée estimée est présentée comme telle et peut être corrigée.

## 3. Personnes et parcours prioritaires

### Client
Trouver un commerce → choisir avant le menu livraison (si disponible), emporter ou sur place → composer le panier → nouveau client : cliquer « Créer un compte » et envoyer le message `wa.me` prérempli au WhatsApp de TUC, sans saisir le numéro sur le site → recevoir identifiant (numéro WhatsApp) + lien magique → revenir connecté au même panier et au même mode → si livraison, saisir l’adresse sur le site → vérifier zone et prix final → confirmer → transmettre à la caisse du commerce → informer des points gagnés et suivre l’état → payer en espèces selon le mode. Un client déjà connecté passe directement à la commande; un compte existant peut se reconnecter par téléphone/mot de passe ou lien magique. Depuis un QR de table, le mode sur place, le commerce et la table sont préremplis; l’option livraison est masquée. L’ouverture de WhatsApp seule ne crée ni compte ni commande; la provenance est gardée côté serveur, sans forcer un message WhatsApp à chaque commande.

### Restaurateur sur téléphone
« Espace Pro » → se connecter (téléphone + mot de passe ou lien magique) ou « Créer ma boutique » → ouvrir le WhatsApp Pro TUC avec une référence `PRO` préremplie et l’envoyer, sans saisir son numéro sur le site → répondre une question à la fois (nom et rôle déclaré, commerce, zone, modes de service, photos de toutes les pages du menu; lien Google Business/Maps facultatif) dans la langue choisie, par boutons, texte, photo ou note vocale → reprendre plus tard si besoin → recevoir l’appel humain de vérification → TUC crée un compte propriétaire vérifié et un brouillon de boutique/menu → le restaurateur ouvre un lien magique, choisit son mot de passe et vérifie l’aperçu → approuver/publication → inviter séparément managers, caissiers et serveurs avec des permissions adaptées.

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
- Avant d’automatiser, confirmer les deux numéros WhatsApp Business, le fournisseur/API officielle, les tarifs et règles de messages, la gestion des consentements et la protection/rétention des photos reçues; stocker les identifiants API/webhook uniquement dans le gestionnaire de secrets.
- Vérifier le workflow SSH : bon tag/type de référence, cible configurée, transfert complet, activation, purge, health check et rollback documenté.

**Critères de sortie :** lint PHP/JS/CSS vert; tests de sécurité critiques automatisés; déploiement réussi sur `dev` puis `uat`; restauration de sauvegarde répétée; aucune clé dans Git, les artefacts ou les logs.

### Phase 1 — Demande restaurateur, vérification et accès Pro (3–4 semaines)

**But :** un restaurateur peut démarrer sur son téléphone avec peu de saisie; TUC vérifie la personne et le commerce avant de donner un accès actif ou de publier une boutique.

- Créer un parcours « Espace Pro » distinct : connexion existante (téléphone + mot de passe ou lien magique) et « Créer ma boutique ».
- Réserver un numéro WhatsApp Business **TUC Pro**, distinct du numéro public des commandes clients; confirmer le fournisseur/API officielle et le coût opérationnel avant le pilote.
- Le CTA « Créer ma boutique » ouvre `wa.me` avec un message prérempli et une référence `PRO` opaque, à usage unique, liée côté serveur à la source de la demande. Ne pas demander le numéro au préalable : le webhook officiel fournit le numéro WhatsApp après que la personne envoie elle-même le message.
- Relier WhatsApp Business à n8n par webhook vérifié; n8n appelle des endpoints TUC authentifiés, avec validation de signature, référence, expiration, limitation de débit et idempotence. Ne pas créer un utilisateur WordPress directement depuis un texte libre.
- Mener l’accueil automatisé une question à la fois, avec réponses rapides, choix de langue, reprise après interruption et aide humaine. Accepter des notes vocales et photos si le fournisseur le permet; ne pas exiger une longue réponse écrite.
- Recueillir d’abord les champs minimaux : nom de la personne, rôle déclaré (propriétaire/manager/employé), nom du commerce, zone/adresse de base, modes de service souhaités, une ou plusieurs photos de chaque page du menu. Lien public Google Business/Maps facultatif; ne jamais demander les identifiants Google.
- Permettre l’envoi du menu directement depuis l’appareil photo; les photos de chaque plat peuvent être ajoutées plus tard. Les données OCR restent des suggestions non publiées tant que les prix et articles ne sont pas vérifiés.
- Créer un dossier de demande provisoire avec états traçables (`reçue`, `infos à compléter`, `appel à faire`, `vérifiée`, `brouillon`, `à valider`, `publiée`/`refusée`). Le personnel TUC appelle pour confirmer identité, rôle, commerce, coordonnées et informations critiques avant activation.
- Après vérification, créer un compte propriétaire et une boutique en brouillon; envoyer l’identifiant (numéro vérifié) et un lien magique à usage unique. Le restaurateur définit son mot de passe après ouverture si un mot de passe est requis; ne jamais transmettre un mot de passe en clair. Le propriétaire relit l’aperçu et approuve avant publication.
- Séparer le rôle déclaré pendant la demande des permissions réelles. Seul le propriétaire vérifié peut gérer les invitations; un employé demandant un accès doit être approuvé par le propriétaire. Créer des comptes individuels, invitations à usage unique, temporaires et révocables. Définir les capacités par rôle : `owner` gère la boutique et l’équipe; `manager` gère les opérations autorisées; `cashier` traite les commandes; `server` ne voit que les fonctions de service à table nécessaires. Aucune permission ne vient du seul rôle déclaré dans WhatsApp.
- Réserver et vérifier les URL de boutique, empêcher les collisions avec les routes du site et empêcher tout accès inter-boutique.

**Critères de sortie :** au moins 8 commerces pilotes passent de la demande au brouillon puis à la publication approuvée; 80 % terminent la demande initiale mobile sans aide technique; 100 % des boutiques publiques et comptes propriétaires ont passé l’appel/vérification prévue; aucune invitation ou permission inter-boutique non autorisée ne passe les tests.

### Phase 2 — Création et gestion du menu (2–3 semaines)

**But :** publier et modifier un menu fiable sans compétence technique.

- CRUD de catégories et d’articles : nom, description courte, prix en MAD, disponibilité, photo, options et suppléments.
- Réordonner simplement les catégories et plats; activer/désactiver un plat sans le supprimer.
- Import CSV avec modèle téléchargeable, aperçu, validation des erreurs et confirmation avant écriture.
- Upload photo avec compression, recadrage guidé et formats AVIF/WebP/JPG; texte alternatif et limites de taille.
- Prévisualisation publique avant publication; brouillon sauvegardé automatiquement.
- Afficher le statut « menu complet / incomplet » et signaler les champs qui bloquent la publication.
- Accepter dès la demande initiale une ou plusieurs photos prises au téléphone (une par page); proposer ensuite l’ajout facultatif de photos individuelles des plats, sans bloquer la première publication.
- Lien Google Business/Maps facultatif, utilisé comme piste pour préremplir certains détails seulement si autorisé; le restaurateur confirme nom, adresse, horaires et modes de service avant publication. Ne jamais demander les identifiants Google.
- OCR éventuel des photos en **assistant de saisie** seulement : produire un brouillon relisible, signaler les prix incertains et ne rien publier sans validation explicite du restaurateur.

**Critères de sortie :** après vérification, un commerce peut corriger son brouillon et publier 20 articles en moins de 15 minutes depuis son téléphone; prix/options validés côté serveur; import invalide sans écriture partielle; modifications auditables; aucune extraction OCR non confirmée publiée.

### Phase 3 — Découverte et commande client (3–4 semaines)

**But :** permettre une vraie commande simple et sûre depuis la fiche restaurant.

- Fiche mobile rapide : menu, horaires, disponibilité, modes de service, frais et minimum clairement présentés.
- Recherche par nom, cuisine, catégorie et quartier; gérer proprement les résultats vides et les commerces fermés.
- Panier persistant à court terme, options comprises, quantités modifiables, suppression facile.
- Livraison : adresse et zone desservie; retrait et QR à table : demander uniquement les détails utiles.
- Choisir le mode de service sur la fiche du commerce avant le menu; n’afficher que les modes disponibles. Pour un QR de table, préremplir et verrouiller le mode sur place, le commerce et la table, sans option livraison.
- Pour un nouveau client non connecté, le bouton « Créer un compte » ouvre `wa.me` vers le numéro WhatsApp officiel de TUC avec une référence opaque, temporaire et à usage unique liée côté serveur au panier, au mode choisi et à sa provenance. Ne pas demander le numéro au préalable; le client doit envoyer lui-même le message. Ne jamais adresser le lien au WhatsApp personnel du restaurateur.
- Recevoir le message via le webhook officiel/fournisseur WhatsApp; valider signature, numéro, référence, expiration et idempotence. L’ouverture de WhatsApp seule ne vérifie rien et ne doit créer ni compte ni commande.
- Après réception valide, créer/rattacher le profil au numéro WhatsApp et envoyer un lien magique à usage unique, limité dans le temps. Ne jamais envoyer un mot de passe en clair; si un mot de passe est requis, le client le définit après ouverture du lien. Les clients déjà connectés passent directement à la commande.
- Au retour sur le site, restaurer le panier et le mode déjà choisi. Pour livraison seulement, demander l’adresse sur le site (saisie manuelle, GPS facultatif), vérifier la zone et les frais. Ne pas demander d’adresse pour emporter/sur place/QR à table.
- Calculer le total côté serveur depuis les prix publiés; vérifier disponibilité, minimum et commerce; afficher le montant final avant la confirmation explicite.
- Après confirmation, envoyer la commande à la caisse TUC du bon commerce; expliquer le paiement en espèces et fournir numéro, mode et délai estimé.
- Informer sur les points et créditer une seule fois à l’état choisi (par défaut après acceptation/achèvement), jamais à la simple ouverture du lien WhatsApp.
- Prévoir un statut de suivi client et des notifications compréhensibles; le compte ne doit pas être imposé avant de parcourir le menu.

**Critères de sortie :** parcours E2E Android/iPhone : mode choisi avant le menu → création via WhatsApp sans saisie préalable du numéro → lien magique → retour connecté au panier/mode → adresse validée si livraison → confirmation; le QR de table masque livraison et fixe commerce/table; aucun prix modifiable côté navigateur; tests contre référence expirée/réutilisée, message non envoyé, webhook en double, commande vide, commerce fermé et erreur réseau; aucune commande ni compte créé sur la seule ouverture de `wa.me`.

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
- Tests modérés avec des personnes âgées, des personnes qui lisent peu ou pas, des utilisateurs peu familiers du smartphone et des restaurateurs qui n’ont pas d’ordinateur; inclure l’aide audio/téléphonique et les notes vocales.
- Corriger les blocages observés avant d’ajouter des fonctions secondaires.
- Tester le MVP en français et valider dès maintenant la structure RTL/LTR sur des écrans représentatifs. Avant d’activer l’arabe ou l’anglais, faire relire leurs traductions par des locuteurs compétents et retester tous les parcours, notamment numéros, prix et champs téléphone en RTL; pas de traduction machine non relue.
- Optimiser images et rendu; test sur téléphone d’entrée de gamme et réseau lent.
- Rédiger politique de confidentialité, consentements, conservation/suppression des données et procédure de support; faire valider les obligations locales avant ouverture générale.
- Former les restaurateurs : guide illustré d’une page, QR imprimable, procédure de fermeture/indisponibilité et support de première commande.

**Critères de sortie :** aucun bug bloquant/critique; taux de commande réussie ≥ 98 % sur le pilote; au moins 80 % des restaurateurs terminent la demande WhatsApp initiale sans aide technique (l’appel de vérification reste prévu pour tous); incidents et demandes support suivis; les tests avec des personnes qui lisent peu ne révèlent aucun blocage critique.

### Phase 6 — Ouverture progressive et croissance (après le pilote)

- Étendre quartier par quartier, avec un seuil minimum de commerces actifs par zone.
- Mettre en place modération des fiches, signalements, fermeture temporaire et prévention des faux commerces.
- Ajouter horaires spéciaux, promotions, favoris, avis vérifiés et approfondir les variantes linguistiques/régionales selon les données du pilote; la structure FR/AR/EN est prévue dès le MVP.
- Ajouter l’OCR de menus uniquement si le flux manuel est stable, avec contrôle humain et historique des corrections.
- Faire des tests de charge réalistes; instrumenter les requêtes et les index avant de migrer des données vers des tables dédiées.
- Étudier une source de financement durable et transparente; ne pas ajouter de commission sans décision explicite et communication claire.

## 6. Priorisation produit — MVP indispensable vs plus tard

### Indispensable pour appeler le produit « marketplace utilisable »

1. Demande restaurateur via WhatsApp Pro, appel/vérification humaine, boutique brouillon et approbation du propriétaire avant publication.
2. Accès propriétaire sécurisé et invitations d’équipe individuelles avec rôles `manager`, `cashier` et `server` à permissions minimales.
3. Menu avec catégories, prix, photos et disponibilité; photo du menu acceptée comme point de départ, OCR toujours validé.
4. Pages publiques propres, accessibles et indexables.
5. Panier et total serveur exact.
6. Commande et réception en caisse, avec solution de secours.
7. Suivi clair, annulation/refus et aide en cas de problème.
8. Internationalisation FR/AR/EN planifiée dès les composants, avec RTL validé.
9. Attribution simple et affichage clair des points de fidélité pour les commandes éligibles.
10. Confidentialité, sécurité et sauvegarde restaurable.

### À ne pas lancer avant validation du MVP

OCR automatique sans validation, fidélité avancée (paliers, échange de points, coupons), chat, recommandations algorithmiques, application native, paiement en ligne, flotte de livraison TUC, tableau de bord analytique complexe, personnalisation libre du site par chaque restaurant.

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
- Liens magiques à usage unique, expiration courte, protection contre énumération et réponses d’erreur qui ne révèlent pas l’existence d’un compte; aucun mot de passe en clair par WhatsApp.
- Séparer les numéros/parcours WhatsApp **commandes clients** et **TUC Pro**; utiliser l’API officielle du fournisseur, vérifier les webhooks et références `ORDER`/`PRO`, limiter les données et les médias conservés, et valider coûts, consentement et procédure de retrait.
- Vérifier chaque permission d’équipe côté serveur; invitations individuelles expirables/révocables; aucun compte partagé et aucun employé auto-promu propriétaire.
- Web Push traité comme best effort; aucun secret privé VAPID dans le JavaScript ou le dépôt.
- Procédure de sauvegarde et de restauration testée; revue des plugins tiers et mises à jour régulières.
- Toute fuite de credential déclenche révocation/rotation, vérification des logs et communication interne.

## 9. Mesures de succès

Mesurer avec des événements minimaux, sans collecter plus de données personnelles que nécessaire.

| Indicateur | Cible initiale du pilote |
|---|---:|
| Temps médian pour relire et publier 5 articles après validation du brouillon | ≤ 15 min |
| Restaurateurs pilotes qui terminent la demande WhatsApp initiale sans aide technique | ≥ 80 % |
| Boutiques publiques avec appel de vérification et approbation du propriétaire consignés | 100 % |
| Comptes d’équipe avec rôle autorisé, invitation acceptée et accès inter-boutique bloqué | 100 % |
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
3. Ordre de lancement retenu : français d’abord, architecture prête pour l’arabe/RTL puis l’anglais; planifier les traductions et tests utilisateurs sans bloquer les composants de base.
4. Organiser l’appel humain et la validation propriétaire avant activation/publication; fixer une équipe et un délai de rappel réalistes.
5. Choisir le fournisseur/API WhatsApp officiel pour les deux numéros Pro et clients, vérifier ses tarifs/règles et financer le support, les médias et les appels.
6. Confirmer la cible WordPress/base et l’environnement UAT avant le premier déploiement du plugin importé.

## Définition de « prêt à ouvrir au public »

Pas de lancement public tant que : demande restaurateur WhatsApp Pro, appel de vérification, boutique/menu brouillon et approbation du propriétaire, activation sécurisée du compte et invitations d’équipe, commande client réelle, réception en caisse, suivi, paiement en espèces, permissions par boutique, sauvegarde/restauration, tests de charge raisonnables, parcours français accessibles et procédures de support ne sont pas validés ensemble sur l’UAT. L’arabe et l’anglais ne sont activés qu’après traduction relue et tests de bout en bout dans chaque langue.
