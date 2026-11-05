# Audit UX & design — références Glovo partagées

**Date :** 5 novembre 2026  
**Source observée :** 42 captures d’écran fournies en quatre lots dans la conversation. Une capture desktop montre DoorDash, pas Glovo; elle est traitée comme référence comparative séparée.
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

Le parcours cible communiqué pour TUC **n’a pas de page de paiement/checkout classique** : depuis le panier, le client appuie sur « Commander » et arrive dans WhatsApp via `wa.me`, avec un message prérempli adressé au **WhatsApp de TUC**, pas à celui du restaurant. Le message récapitule la commande; le client doit encore l’envoyer dans WhatsApp. TUC crée ensuite le profil client, lui transmet son accès par lien magique et l’informe de ses points de fidélité.

**Conséquences pour la conception et l’implémentation :**
- ne pas demander au client de remplir un formulaire de paiement ni créer un écran qui promet un paiement en ligne;
- faire apparaître le commerce, le mode de service, les articles/configurations et le total calculé côté serveur dans le panier et le texte prérempli;
- expliquer clairement que la commande n’est transmise qu’après l’envoi du message WhatsApp; `wa.me` ouvre un brouillon, il ne l’envoie pas automatiquement;
- ne considérer ni le clic ni l’ouverture de WhatsApp comme une commande reçue : attendre la réception/validation du message avant de confirmer la commande et de créer le profil;
- envoyer un lien magique à usage unique, limité dans le temps; ne jamais générer ou transmettre un mot de passe en clair. Si un mot de passe est requis, laisser le client le définir après validation du lien;
- informer le client des points attendus; créditer les points une seule fois selon une règle explicite, idéalement après confirmation/achèvement de la commande plutôt qu’à l’ouverture du brouillon;
- isoler les données personnelles non nécessaires du message et fournir un moyen de corriger une commande avant son envoi.

À capturer ensuite pour ce flux TUC : écran de panier, bouton « Commander », brouillon prérempli ouvert sur WhatsApp (mobile; desktop si supporté), puis message de confirmation contenant le lien magique et l’information de fidélité. Utiliser une commande fictive et masquer les coordonnées réelles.

### G. Recherche, filtres et résultats

Les captures montrent une page de résultats « Restaurants » avec la recherche en haut, une rangée de catégories, des filtres sous forme de pastilles, le nombre de résultats, une action de réinitialisation et des commerces présentés en cartes verticales avec photo, promo, frais, délai et note. Le dernier lot ajoute les panneaux « Trier par » (recommandé, autour de moi, meilleure note, frais de livraison) et « Type de plat », avec une action explicite pour appliquer les résultats. Ces panneaux deviennent une feuille basse sur mobile et une boîte de dialogue sur desktop. Une capture DoorDash distincte montre une navigation desktop latérale; elle n’est pas utilisée comme spécification Glovo.

**Adaptation TUC :**
- garder la recherche immédiatement disponible après le choix du quartier;
- proposer peu de filtres utiles au départ : type de cuisine, ouvert maintenant, frais, délai et promotion;
- rendre les filtres actifs visibles et supprimables individuellement, avec « Réinitialiser »;
- annoncer le nombre de résultats et fournir un état vide avec une action claire;
- ne pas mélanger des catégories non prises en charge ou des données d’un autre pays.

### H. Connexion et compte

Une capture montre un écran de bienvenue avec numéro de téléphone, indicatif, connexion WhatsApp et alternatives Google/e-mail, accompagné du texte de consentement et de liens légaux.

**Adaptation TUC :** la commande ne doit pas exiger un compte préalable. Si un compte est proposé, afficher son bénéfice en une phrase, privilégier le numéro de téléphone si c’est le canal de suivi choisi, et demander le minimum de données. Les consentements et conditions doivent être lisibles et ne pas être confondus avec une autorisation marketing.

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
| Étape de paiement classique | non | non | non applicable : ce n’est pas le flux TUC |
| Panier TUC → brouillon `wa.me` adressé à TUC | non | non | capture du parcours TUC mobile; desktop seulement si supporté |
| Message envoyé, confirmation, lien magique et points de fidélité | non | non | capture de confirmation/démo si déjà disponible; sinon à concevoir |
| Connexion | oui | non | desktop seulement si l’écran diffère; non bloquant pour la commande invité |
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
