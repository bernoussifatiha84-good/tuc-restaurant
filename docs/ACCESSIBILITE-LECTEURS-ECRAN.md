# Vérification des lecteurs d’écran — demande restaurateur

## Contrôles automatisés

Le workflow WordPress Docker exécute axe-core sur le composant avant envoi et après apparition du lien WhatsApp, pour les règles WCAG 2.0/2.1/2.2 A et AA. Playwright vérifie aussi l’ordre clavier nom → photo → bouton, la langue `ar` lorsque le nom est saisi en arabe, le rôle de statut, le nom accessible du lien et le déplacement du focus après l’envoi.

Un second job Windows lance le formulaire rendu par WordPress dans Chromium avec **NVDA réellement actif**. Le script parcourt le formulaire par Tab/Shift+Tab/Entrée par événements clavier Windows. Le moteur utilisé est le pilote eSpeak intégré à NVDA (`nvdaBuiltin.synthDrivers.espeak`), pas un exécutable eSpeak séparé.

Le runner n’a pas de périphérique audio. Au lieu d’installer une carte son virtuelle, le test remplace uniquement la destination `nvwave.WavePlayer` du pilote intégré par un sink PCM fichier. NVDA doit prouver que le moteur intégré est initialisé ; tout repli sur OneCore fait échouer le job. Les annonces françaises, l’écho arabe, le focus final sur le lien WhatsApp et la présence de PCM français/arabe non silencieux sont validés. Les WAV, le manifeste, la transcription, la configuration effective et le journal NVDA sont publiés comme artefacts.

Cette capture avant WASAPI prouve la production audio par NVDA/eSpeak, mais ne remplace pas une écoute humaine sur un vrai périphérique ni un test VoiceOver/Safari sur appareil Apple réel.

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
