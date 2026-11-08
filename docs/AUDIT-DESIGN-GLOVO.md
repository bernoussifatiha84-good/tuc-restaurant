# Audit UX & design — références Glovo partagées

**Date :** 5 novembre 2026  
**Source observée :** 43 captures distinctes fournies en cinq lots dans la conversation. Une capture desktop montre DoorDash, pas Glovo; elle est traitée comme référence comparative séparée.
**Important :** audit visuel des captures uniquement. Le site Glovo en direct n’a pas été ouvert depuis cet environnement. Les captures ne permettent pas de confirmer les interactions non visibles, les tailles CSS réelles, les règles de personnalisation ou le fonctionnement du paiement.

## 1. Synthèse exécutive

Les captures montrent une expérience construite autour de quatre moments : choisir où livrer, découvrir des commerces, composer un produit avec ses options, puis vérifier le panier. La structure est facilement reconnaissable et les actions principales ressortent visuellement : adresse, recherche, navigation par catégories, ajout au panier et bouton de continuation.

**Pour TUC, il faut reprendre les principes de parcours, pas copier Glovo.** TUC a un périmètre volontairement plus focalisé : restaurants, mahlabas, snacks et cafés; le restaurant livre lui-même; le retrait et la commande à table par QR sont importants; le paiement en ligne n’est pas dans le MVP. Les couleurs, photos, textes, illustrations, icônes et signes de marque Glovo ne sont pas des assets à reprendre.

Le thème TUC possède déjà une base cohérente avec les captures : accent jaune, teal, choix de quartier, catégories rondes, recherche et rails de commerces. La suite doit surtout transformer cette maquette en un parcours complet, fiable, accessible et alimenté par les données du plugin.

## 2. Qualité et limites du jeu de captures

### Observations visibles

- Les vues mobiles fournies font environ **268–275 px de large** dans les fichiers. Elles sont utiles pour repérer les composants, mais trop étroites pour décider seules de la mise en page mobile standard. Revalider au minimum à 360, 390 et 430 px.
- Les captures desktop incluent de longues pages et des fenêtres modales; elles aident à comprendre la hiérarchie générale, pas les comportements de défilement ou de redimensionnement.
- Le jeu de références semble **mélanger des marchés et des produits** : certaines vues Casablanca/O’Tacos montrent des prix en MAD; d’autres rails desktop présentent des enseignes canadiennes et des prix en dollars canadiens; une capture desktop est explicitement DoorDash. Ces éléments ne sont pas des références Glovo Maroc et doivent rester séparés. Les données, catégories, promotions et motifs propriétaires ne doivent pas être transposés en bloc à TUC.
- Les captures ne permettent pas de mesurer précisément le contraste, les tailles CSS, l’état clavier/lecteur d’écran, la performance, ni toutes les validations de formulaires.

### Conséquence

Traiter les captures comme des **preuves de motifs d’interface** et non comme une spécification pixel-perfect. Pour fixer le design final, capturer les mêmes parcours dans un marché, une adresse, une langue et un état de connexion cohérents.

## 3. Parcours et motifs observés

### A. Localisation avant la commande

Les captures montrent une feuille mobile avec carte et repère de position, une demande « Où devons-nous livrer ? », un choix d’adresse et une action pour utiliser/partager la position. Sur desktop, la demande est présentée comme une fenêtre au-dessus de la page assombrie, avec un champ de recherche et une action de géolocalisation.

**À retenir pour TUC :** l’adresse et le quartier changent réellement la disponibilité, les frais et la distance; il faut donc rendre le choix facile et modifiable. La localisation ne doit toutefois pas bloquer la consultation du menu ni déclencher une demande GPS sans contexte.

**Adaptation TUC :**
- proposer d’abord un quartier ou une adresse saisie manuellement;
- présenter la géolocalisation comme une option facultative avec explication;
- garder une adresse visible et modifiable dans l’entête;
- valider le périmètre de livraison avec le restaurant avant le panier;
- ne jamais prétendre avoir localisé une personne si la position n’a pas été calculée.

### B. Accueil et découverte

La page mobile utilise un entête lumineux avec marque, adresse, connexion, catégories illustrées et champ de recherche. Le desktop expose aussi l’adresse et les catégories, puis empile des rangées thématiques. Les listes utilisent des cartes horizontales, des titres de section et « Voir tout »; certains rails sont promotionnels ou sponsorisés.

Les cartes de commerce affichent principalement photo, nom, note, distance/temps, frais et parfois une promotion. La répétition des sections facilite le balayage visuel, mais la page desktop devient très longue et contient des rubriques spécifiques aux courses et au commerce de détail.

**Adaptation TUC :**
- conserver un accueil de découverte mobile-first;
- limiter les catégories aux commerces servis par TUC;
- utiliser des sections qui correspondent à une promesse vérifiable : « près de vous », « ouverts maintenant », « livraison offerte », « promos du jour »;
- n’afficher distance, temps, gratuité et note que si les données sont justes et à jour;
- éviter les rails sponsorisés et les catégories retail dans le MVP gratuit de TUC;
- prévoir une page ou un résultat « Voir tout » réel, pas un lien décoratif.

### C. Fiche restaurant et menu

La capture mobile d’O’Tacos rassemble photo de couverture, nom, indicateurs de note/délai/frais, informations de promotion, recherche dans le menu et onglets de menu. Une fenêtre « informations de l’établissement » présente adresse, horaires, informations partenaires et une fermeture claire. Les nouvelles captures desktop montrent une navigation persistante des catégories à gauche, les articles du menu au centre et un panier fixé à droite. Le panneau du panier conserve le récapitulatif visible pendant le parcours du menu, en état vide comme rempli.

**Adaptation TUC :**
- placer nom, état ouvert/fermé, modes de service, quartier/adresse, temps estimé et frais près du haut de la fiche;
- séparer les informations du restaurant des produits, sans faire disparaître le menu sous trop de bannières;
- ajouter un accès clair à horaires, contact, livraison par le restaurant et commande sur place;
- réserver le bandeau de couverture aux médias fournis par le restaurant ou autorisés;
- permettre au restaurateur de prévisualiser la fiche avant publication.

### D. Configuration d’un produit

Les captures montrent un panneau de configuration avec photo, nom, prix et remise, puis des groupes d’options. Certains groupes sont obligatoires (« Requis — choisir 1 »), d’autres facultatifs ou avec un plafond (« maximum de 5 produits »). Sur desktop, le panneau sépare la liste d’options et le résumé visuel du produit en deux colonnes; le CTA et la quantité restent visibles tandis que la liste défile. Les captures mobiles montrent la version étroite du configurateur.

**Points forts du motif :** la composition du plat est visible avant l’ajout; les choix sont regroupés par question; le total d’action reste accessible.

**Risques à éviter dans TUC :** longue liste sans résumé, statut obligatoire peu visible, prix des suppléments difficiles à comprendre, boutons trop petits, ou CTA qui masque la dernière option.

**Adaptation TUC :**
- marquer explicitement « Obligatoire — choisir 1 » et « Facultatif »;
- afficher le supplément à côté de chaque option et le nouveau total;
- garder une sélection persistante lorsqu’on ferme puis rouvre la fiche;
- afficher un message près du groupe incomplet, pas seulement en haut de l’écran;
- réserver une zone basse fixe au total et à « Ajouter au panier », avec marge pour les gestes système;
- écrire les règles de minimum/maximum d’options côté serveur également.

### E. Panier

Les captures mobiles montrent un panier vide et un panier rempli. Les nouvelles vues desktop confirment un panier fixe à droite, avec articles configurés, quantités, retrait, total courant, information sur les frais et un CTA distinct. Elles complètent également l’écran de menu avec panier vide.

**Adaptation TUC :**
- afficher le commerce concerné et le mode choisi;
- rendre la modification et la suppression compréhensibles et accessibles;
- afficher sous-total, frais éventuels et total final sans frais cachés;
- conserver le panier après une perte réseau ou un retour à la fiche;
- prévenir clairement si un restaurant ferme ou si un plat devient indisponible;
- pour la commande à table, afficher le numéro de table et confirmer que le paiement se fera à la fin.

### F. Commande TUC : transfert vers WhatsApp (règle métier, distincte de Glovo)

Le parcours TUC envisagé **n’a pas de paiement en ligne**. Le mode de service se choisit **avant le menu**, selon ce que le commerce propose : livraison (uniquement si disponible), emporter ou sur place. Un client qui arrive par un QR de table est directement en mode sur place, avec le commerce et la table préremplis; il ne voit pas l’option livraison.

Pour un nouveau client non connecté, « Créer un compte » ouvre `wa.me` vers le WhatsApp officiel de TUC avec un message prérempli contenant une référence courte, liée côté serveur au panier, au commerce et à la provenance. Le client n’a pas à saisir son numéro sur le site : n8n le reçoit avec le message entrant. Après validation de la référence, le serveur retrouve/crée le profil et TUC renvoie l’identifiant (le numéro WhatsApp) ainsi qu’un lien magique à usage unique. Ce lien reconnecte le client et restaure son panier et son mode de service. Un client déjà connecté commande directement; un compte existant peut utiliser téléphone/mot de passe ou demander un lien magique. Ne pas imposer un nouveau message WhatsApp à chaque commande : garder l’attribution côté serveur.

**Parcours recommandé :** choix du mode de service sur la fiche commerce avant le menu → parcourir le menu et composer le panier → nouveau client : « Créer un compte » → envoi du message prérempli → validation webhook et correspondance numéro/référence → profil créé/rattaché → identifiant + lien magique → retour connecté au même panier et au même mode → adresse sur le site seulement si livraison → vérification de la zone, des frais et du total → confirmation de commande → information/crédit des points selon la règle choisie.

**Conséquences pour la conception et l’implémentation :**
- reprendre de la capture Glovo la simplicité du CTA WhatsApp, mais **ne pas demander le numéro aux nouveaux clients** : le récupérer du message entrant. Retirer Google/e-mail si TUC n’en veut pas; conserver +212 par défaut seulement dans une éventuelle connexion téléphone/mot de passe d’un compte existant;
- ne pas demander de mot de passe avant d’avoir vérifié le numéro WhatsApp. Après le lien magique, proposer au besoin de définir un mot de passe; ne jamais en envoyer un en clair;
- `wa.me` ne transmet rien tant que le client n’a pas appuyé sur « Envoyer ». L’ouverture seule ne vérifie pas le numéro, ne crée pas le profil et ne confirme pas de commande;
- inclure dans le message une référence opaque, temporaire, à usage unique, liée côté serveur au panier et à sa source (accueil, fiche boutique, QR/table). Ne pas exposer d’identifiant séquentiel ni de données personnelles inutiles;
- n8n doit recevoir le message via l’API officielle/le fournisseur WhatsApp configuré et appeler une API TUC authentifiée; valider webhook, référence, expiration et idempotence;
- demander l’adresse **sur le site**, après identification et choix « Livraison », mais avant la confirmation finale, afin de valider la zone, les frais et le total. Saisie manuelle d’abord, GPS facultatif. Ne pas demander d’adresse pour retrait ou QR à table; le QR fournit déjà le commerce/la table;
- afficher le montant final avant le bouton de confirmation; router la commande confirmée vers la caisse TUC du bon commerce, pas vers le WhatsApp personnel du restaurant;
- créditer les points une seule fois selon une règle définie (de préférence après acceptation/achèvement), et préciser au client quand ils seront disponibles;
- confirmer le coût réel des messages sortants auprès de Meta/fournisseur : un message initié par l’utilisateur ne garantit pas à lui seul que la réponse avec lien magique sera gratuite.

Les captures déjà reçues couvrent les références Glovo de connexion, fiche, configurateur et panier. Les seules captures supplémentaires utiles sont celles du flux TUC réel : brouillon WhatsApp + retour par lien magique, puis adresse de livraison sur le site. Si le flux n’est pas encore construit, il peut être conçu sans autre capture Glovo.

### G. Recherche, filtres et résultats

Les captures montrent une page de résultats « Restaurants » avec la recherche en haut, une rangée de catégories, des filtres sous forme de pastilles, le nombre de résultats, une action de réinitialisation et des commerces présentés en cartes verticales avec photo, promo, frais, délai et note. Le dernier lot ajoute les panneaux « Trier par » (recommandé, autour de moi, meilleure note, frais de livraison) et « Type de plat », avec une action explicite pour appliquer les résultats. Ces panneaux deviennent une feuille basse sur mobile et une boîte de dialogue sur desktop. Une capture DoorDash distincte montre une navigation desktop latérale; elle n’est pas utilisée comme spécification Glovo.

**Adaptation TUC :**
- garder la recherche immédiatement disponible après le choix du quartier;
- proposer peu de filtres utiles au départ : type de cuisine, ouvert maintenant, frais, délai et promotion;
- rendre les filtres actifs visibles et supprimables individuellement, avec « Réinitialiser »;
- annoncer le nombre de résultats et fournir un état vide avec une action claire;
- ne pas mélanger des catégories non prises en charge ou des données d’un autre pays.

### H. Connexion et compte

Les captures mobile et desktop montrent l’écran de bienvenue : préfixe téléphonique, numéro, bouton WhatsApp principal, puis Google/e-mail comme alternatives, avec mentions légales.

**Adaptation TUC :** pour un nouveau compte, un bouton « Créer un compte » ouvre WhatsApp avec le message de référence; ne pas afficher de champ téléphone préalable. Le numéro de l’expéditeur devient l’identifiant après réception et validation. Pour un compte existant, proposer téléphone + mot de passe (préfixe **+212** par défaut) ou un lien magique. Retirer Google/e-mail si ces canaux ne sont pas prévus. Ne pas forcer le compte avant la consultation du menu. Afficher une mention de confidentialité claire, distincte d’un consentement marketing.

### I. Plus de détails sur le magasin et le panier

Les nouvelles captures complètent le parcours magasin : catégories de menu en onglets, promotions et meilleures ventes, cartes produit avec ajout rapide, panier vide, panier rempli, et fiche détaillée avec suppléments sélectionnables. Le bouton de panier reste visible après ajout; l’écran panier montre les quantités, la suppression et « Continuer ».

Le configurateur de « Benna Épicé » illustre des groupes de suppléments avec plafonds de sélection, un compteur de quantité et un CTA bas fixe qui reprend le prix. Le comportement est utile, mais il faut tester que ce CTA ne masque jamais le dernier choix et que le total évolue immédiatement avec les suppléments.

**Adaptation TUC :** garder les onglets de catégories accessibles, montrer les groupes requis avant les extras, afficher chaque plafond (ex. « jusqu’à 4 ») près du nom du groupe, et recalculer le prix côté serveur à l’envoi. Les cartes à ajout rapide ne doivent pas contourner la configuration requise d’un plat.

### J. Acquisition de restaurants (Glovo Local)

Une nouvelle capture longue montre une page séparée « Glovo Local » destinée aux entreprises : formulaire pour commencer à vendre, documentation requise, rubriques « Vendez / Gérez / Développez », témoignage, CTA de partenariat, présentation de l’accompagnement et FAQ.

**Adaptation TUC :** reprendre l’approche progressive (inscription courte, explication des étapes, documents et FAQ), pas le contenu ou les visuels Glovo. Pour TUC, distinguer la demande de contact de la création effective de boutique; sauvegarder un brouillon, rendre les pièces réellement nécessaires explicites et permettre au restaurateur de finir le parcours depuis son téléphone.

## 4. Ce qui est pertinent pour TUC — et ce qui ne l’est pas

### Motifs à adapter

- choix d’adresse toujours visible et facilement éditable;
- accueil par catégories et sections lisibles;
- cartes compactes avec les informations de décision;
- fiche commerce avec horaires et frais;
- options de produit regroupées par question;
- récapitulatif de panier et CTA persistant;
- fenêtres/modales avec fermeture explicite et contexte conservé.

### Motifs à ne pas reprendre tels quels

- l’étendue multi-catégorie (courses, pharmacies, boutiques, retail) : TUC est une marketplace spécialisée;
- les abonnements de type Prime, les offres payantes ou les emplacements sponsorisés;
- les noms, illustrations, logos, photos, textes, badges propriétaires ou couleur de marque exacte;
- les données canadiennes/les prix en CAD des captures desktop;
- toute pression pour créer un compte avant d’avoir compris le panier.

## 5. Direction visuelle proposée pour TUC

- **Identité :** conserver le teal et le jaune déjà présents dans le thème TUC, mais appliquer une hiérarchie plus calme que le grand aplat d’accueil seul. Utiliser le jaune comme accent de découverte et le teal pour les actions principales.
- **Typographie :** texte de lecture confortable; titres fortement hiérarchisés; labels courts; pas de contenu important réduit en micro-texte.
- **Surfaces :** fond clair, cartes et panneaux sobres, ombres modérées, arrondis cohérents; réserver la couleur vive aux catégories, promotions et actions.
- **Images :** photos autorisées et cohérentes avec les commerces TUC; recadrage contrôlé et variantes déjà disponibles; placeholders soignés lorsqu’une photo manque.
- **Mouvement :** transitions courtes et discrètes; respecter `prefers-reduced-motion`; ne pas faire dépendre une action de la couleur ou de l’animation seule.
- **RTL :** concevoir la structure pour le français et l’arabe sans inversions bricolées; valider séparément les libellés, flèches et feuilles de configuration.

Les captures seules ne permettent pas de relever des valeurs exactes de design tokens. Les couleurs et dimensions doivent être mesurées dans un navigateur à une échelle connue, puis consignées dans les variables du thème TUC.

## 6. Liste de contrôle pour le prochain audit instrumenté

Le dépôt `craft-anything` décrit des outils Chromium pour ouvrir un site, capturer une page et ses états visibles. L’outil est un **auditeur externe**, pas une dépendance à ajouter au plugin WordPress. Le dépôt Craft Anything est en alpha; sa capture peut aider à relever des faits visuels, mais ses reconstructions doivent être revues manuellement.

Quand l’accès au site est disponible, utiliser une session publique propre, en mode visuel/clean-room, sans identifiants ni commande réelle. Capturer des états comparables :

| Écran / état | Mobile reçu | Desktop reçu | À envoyer encore |
|---|---:|---:|---|
| Accueil et adresse déjà choisie | oui | oui | rien |
| Sélecteur d’adresse / géolocalisation | oui | oui | rien |
| Résultats, catégories, filtres, tri et ordre des cartes | oui | oui | rien |
| Fiche restaurant et navigation du menu | oui | oui | rien |
| Configuration d’un plat et suppléments | oui | oui | rien |
| Panier vide et rempli | oui | oui | rien |
| Choix du mode sur la fiche, avant le menu | non | non | règle TUC à concevoir; ne montrer que les modes disponibles |
| QR de table verrouillé sur le mode sur place | non | non | règle TUC à concevoir; masquer Livraison |
| Étape de paiement classique | non | non | non applicable : ce n’est pas le flux TUC |
| Panier TUC → brouillon `wa.me` adressé à TUC | non | non | capture du parcours TUC mobile; desktop seulement si supporté |
| Message envoyé, confirmation, lien magique et points de fidélité | non | non | capture de confirmation/démo si déjà disponible; sinon à concevoir |
| Saisie et validation d’adresse pour livraison, sur le site | non | non | capture si déjà disponible; sinon à concevoir après la connexion |
| Connexion | oui | oui | rien pour la référence Glovo; concevoir la version TUC en +212 sans Google/e-mail |
| Page partenaire / devenir restaurateur Glovo | une capture longue reçue (viewport à confirmer) | non | desktop optionnel; le portail TUC sera adapté à ses propres besoins |

Pour les prochaines images, viser environ **390 × 844 ou 393 × 852 px sur mobile** et **1440 × 900 px sur desktop**, en français et avec la même adresse Casablanca. Les fichiers mobiles reçus font 268–275 px de large : ils suffisent pour comprendre les motifs, mais pas pour valider les espacements et la lisibilité à une largeur mobile standard. Masquer téléphone, nom ou adresse personnelle. Ne jamais valider une vraie commande ni un paiement.

Pour chaque capture, noter le marché, l’adresse de test, la langue, l’état de connexion, le viewport et les actions effectuées. Ne pas mélanger captures de pays, devise, service (Glovo vs DoorDash) ou profils différents dans une même mesure.

**État de cet audit :** les captures envoyées par l’utilisateur ont été analysées visuellement; Craft Anything n’a pas été exécuté contre Glovo en direct dans cet environnement.

## 7. Décisions UI à traduire dans le produit TUC

1. Dessiner les composants TUC : `LocationPicker`, `CategoryTile`, `RestaurantCard`, `RestaurantHeader`, `MenuSection`, `ProductOptionSheet`, `CartLine`, `OrderSummary`.
2. Faire une maquette mobile complète avant le desktop; utiliser les captures Glovo comme repères de parcours, pas comme source d’assets.
3. Implémenter les états vide, chargement, erreur, restaurant fermé, article indisponible et mauvaise connexion.
4. Vérifier le parcours avec une personne âgée et un restaurateur débutant, sans lui expliquer les boutons à l’avance.
5. Passer chaque décision de prix par les règles métier du plugin; la page ne doit jamais être l’autorité sur les frais ou le total.
6. Une fois le panier et la boutique réelle raccordés, refaire le contrôle responsive et accessibilité sur données TUC, pas sur des données Glovo.
