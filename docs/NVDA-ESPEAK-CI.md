# Audit NVDA / eSpeak et CI sans carte son

> **Nature de la preuve : régression automatisée NVDA + eSpeak.** NVDA portable
> sur runner Windows, moteur eSpeak intégré, sortie PCM fichier. Pas de voix
> Windows réelle, pas de périphérique audio, pas d’utilisateur réel. Ce n’est
> **pas** une validation d’accessibilité et cela ne certifie rien.

## État constaté le 8 novembre 2026

Le dépôt `main` ne contient pas le formulaire restaurateur ni les tests NVDA.
Ce travail se trouve dans la PR #1, branche `arena/87e5dbce-tuc-restaurant`,
révision `31f5acf30c84c1880c2019e27cab9757c41b5365` au moment de l'audit.

**L'autre session a fait une partie du travail, mais pas la validation demandée.**

- Les installations Scream/devcon/pnputil ont bien été retirées du workflow actuel.
- NVDA portable et eSpeak NG séparé s'installent.
- Le Scratchpad NVDA charge `espeakWav` : le run 42408754556 contient
  `Loaded synthDriver espeakWav`.
- Cet adaptateur appelle un exécutable eSpeak séparé. Il prouve que NVDA lui
  transmet du texte, pas que le moteur eSpeak intégré à NVDA produit les WAV.
- Le job Windows du dernier run échoue avant la fusion audio : l'annonce
  `Demander l’accompagnement` manque dans Speech Viewer. Des runs précédents
  échouent aussi sur l'ordre du focus ou des timeouts de lecture UIA.
- Les artefacts de ce dernier run ne contiennent pas les trois WAV fusionnés ni
  leur manifeste. On ne peut donc pas déclarer le test vocal français/arabe réussi.
- La PR est toujours en brouillon et n'est pas fusionnée.

Liens de diagnostic :

- https://github.com/bernoussifatiha84-good/tuc-restaurant/pull/1
- https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42408754556

## Résultat exécuté sur GitHub

**Succès le 8 novembre 2026**, run
https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42481128144
sur la révision `492887c33a8093d17164c1669f4aaf9113525f8d` de la PR draft #2 :

- export WordPress : réussi ;
- installation NVDA portable avec contrôle SHA-256 : réussie ;
- chargement du moteur `nvdaBuiltin.synthDrivers.espeak` : réussi ;
- parcours clavier Windows et annonces : réussis ;
- validation du PCM français et arabe : réussie ;
- aucun pilote audio virtuel installé.

L'artefact `nvda-native-espeak-42481128144` contient les WAV, manifeste,
transcription, rapport clavier, configuration et journal NVDA. Il est publié pour
7 jours, jusqu'au 15 novembre 2026.

Cette preuve est ensuite intégrée au workflow `WordPress Docker smoke test` :
run [42482683516](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42482683516),
artefact `nvda-native-espeak-42482683516`. Aucun déploiement et aucune fusion
vers `main` n'ont été effectués.

## Méthode intégrée au smoke test WordPress

Le job `nvda-windows` du workflow `WordPress Docker smoke test` réutilise le
formulaire réellement rendu par WordPress dans le job Linux, puis le teste dans
Chromium sur `windows-2025`. Il n’y a plus de workflow NVDA séparé ni de pilote
`espeakWav` appelant un eSpeak externe.

Il n'installe **aucun pilote audio** et n'utilise ni devcon ni pnputil. NVDA est
installé en portable dans le profil temporaire du runner, avec vérification SHA-256.

Le Scratchpad surcharge `synthDrivers/espeak.py` mais hérite du vrai pilote
`nvdaBuiltin.synthDrivers.espeak`. Pendant son initialisation, seule la classe
`nvwave.WavePlayer` est remplacée par un sink PCM fichier. Le moteur eSpeak,
les voix, le SSML et les changements de langue restent ceux livrés avec NVDA.
Des marqueurs d'index permettent de découper le PCM par langue sans deviner la
langue à partir des caractères Unicode.

Le test refuse de continuer si ce moteur ne s'est pas initialisé. Il ne suffit
pas de vérifier qu'un processus NVDA existe : le fichier de preuve doit identifier
le moteur intégré et la destination WAV. La validation finale exige notamment :

- annonces françaises attendues du formulaire ;
- saisie arabe et écho de ses caractères ;
- PCM français et arabe non vide et non silencieux ;
- absence d'annonce de langue non prise en charge ;
- parcours Tab, Shift+Tab et Entrée par événements clavier Windows ;
- focus final sur le lien WhatsApp.

## Limites de la preuve (à lire avant de conclure)

Cette méthode capture le PCM avant WASAPI. Elle est plus forte qu'un WAV produit
par un exécutable eSpeak indépendant, mais ce n'est pas un enregistrement de
haut-parleur, ni un test d'audibilité sur un périphérique réel. Elle ne remplace
pas un essai humain NVDA/Firefox ou VoiceOver/Safari. Les interruptions de parole
peuvent produire des utterances partielles : elles sont marquées comme telles et
ne sont pas utilisées pour la validation des langues.

Le job publie les WAV, le manifeste, le texte transmis au moteur, la
configuration effective et le journal NVDA, même en cas d'échec. Un résultat
vert doit être confirmé par la présence et le contenu de ces artefacts.

## Références techniques GitHub

- NVDA 2026.3, pilote intégré :
  https://github.com/nvaccess/nvda/blob/release-2026.3/source/synthDrivers/espeak.py
- `_espeak.initialize` crée `nvwave.WavePlayer` :
  https://github.com/nvaccess/nvda/blob/release-2026.3/source/synthDrivers/_espeak.py
- API du sink PCM :
  https://github.com/nvaccess/nvda/blob/release-2026.3/source/nvwave.py
- Accès aux modules intégrés depuis une surcharge :
  https://github.com/nvaccess/nvda/blob/release-2026.3/source/nvdaBuiltin.py
- Problème historique des périphériques audio sur runners Windows :
  https://github.com/actions/runner-images/issues/2528
