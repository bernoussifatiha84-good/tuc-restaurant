# Vérification des lecteurs d’écran — demande restaurateur

## Contrôles automatisés

Le workflow WordPress Docker exécute axe-core sur le composant avant envoi et après apparition du lien WhatsApp, pour les règles WCAG 2.0/2.1/2.2 A et AA. Playwright vérifie aussi l’ordre clavier nom → photo → bouton, la langue `ar` lorsque le nom est saisi en arabe, le rôle de statut, le nom accessible du lien et le déplacement du focus après l’envoi.

Un second job Windows lance le formulaire rendu par WordPress dans Chromium avec **NVDA réellement actif**. Le script parcourt le formulaire par Tab/Shift+Tab/Entrée et lit la transcription de NVDA Speech Viewer pour vérifier les libellés, aides, statut et nom du lien. La transcription est publiée comme artefact du workflow. Cela vérifie les annonces textuelles émises dans NVDA, pas leur rendu audio ni la qualité de prononciation.

Le job Windows lance NVDA et vérifie ses annonces dans le Speech Viewer. Le runner n’a pas de périphérique audio : le pilote eSpeak intégré de NVDA ne peut pas initialiser son `WavePlayer` et retombe sur OneCore (`Couldn't open specified or default audio device`). Les essais d’installation de la carte audio virtuelle Scream avec `devcon` et `pnputil` ont chacun expiré; ils ne sont pas conservés.

Pour capturer quand même **les séquences de parole réellement émises par NVDA**, le workflow installe eSpeak NG 1.52.0 et active le Developer Scratchpad temporaire de NVDA, avec un petit pilote de test `espeakWav`. Ce pilote reçoit les textes et changements de langue de NVDA, fait produire les WAV par eSpeak NG, puis ferme le flux sans carte son. Le job exige que NVDA charge ce pilote, et vérifie la présence d’audio français et arabe dans les séquences capturées. Les WAV sont publiés comme artefacts.

Ce contournement valide le parcours NVDA et ses commandes de parole converties par eSpeak, **pas** le pilote eSpeak intégré à NVDA ni l’écoute sur un vrai appareil. Il ne peut pas juger si la prononciation est naturelle : une personne doit écouter les WAV. VoiceOver/Safari sur iOS/macOS nécessite aussi un appareil Apple réel.

## Test manuel à exécuter avant mise en production

Faire les deux parcours ci-dessous sur la page `/devenir-restaurateur/`, avec un nom français puis un nom arabe, et noter version du système, navigateur et résultat dans la PR.

### iPhone / VoiceOver / Safari

1. Activer VoiceOver dans Réglages → Accessibilité, puis ouvrir la page dans Safari.
2. Parcourir au balayage : le titre principal, l’introduction, le champ « Nom du restaurant », son aide « En français ou en arabe », le champ « Photo du menu », ses formats et sa limite de taille, puis le bouton d’envoi.
3. Saisir un nom arabe et vérifier qu’il est parcourable de droite à gauche et prononcé dans la langue attendue par la configuration VoiceOver.
4. Envoyer une demande de test. Vérifier l’annonce du statut et que le focus arrive sur « Continuer dans WhatsApp », annoncé comme ouvrant un nouvel onglet.
5. Refaire avec une image invalide ou sans fichier : vérifier que la validation identifie clairement le champ à corriger.

### Windows / NVDA / Firefox (ou Chrome)

1. Lancer NVDA, ouvrir la page et parcourir les titres et formulaires avec les raccourcis de navigation NVDA.
2. Vérifier les mêmes libellés et textes d’aide que dans le parcours iPhone, d’abord avec un nom français puis arabe.
3. Parcourir les champs avec Tab et Shift+Tab; soumettre au clavier avec Entrée.
4. Vérifier l’annonce du statut sans déplacement inattendu, le focus sur le lien WhatsApp, et son annonce d’ouverture dans un nouvel onglet.
5. Refaire avec une validation en erreur et vérifier que la personne sait quelle correction faire.

## Compte rendu manuel

- Appareil / OS :
- Lecteur d’écran et version :
- Navigateur et version :
- Français : réussi / échec (détail) :
- Arabe : réussi / échec (détail) :
- Validation et retour d’erreur :
- Focus après envoi :
- Date et testeur :

Ne marquer les tests manuels comme réussis qu’après leur exécution par une personne utilisant réellement le lecteur d’écran. Si aucun appareil/testeur n’est disponible, conserver explicitement le statut « non vérifié manuellement » et ne pas présenter les résultats axe/Playwright comme équivalents.
