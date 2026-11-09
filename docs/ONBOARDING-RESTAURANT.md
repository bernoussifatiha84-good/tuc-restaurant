# Demande d’accompagnement restaurant

## Parcours du restaurateur

1. Ouvrir `/devenir-restaurateur/` sur mobile.
2. Saisir uniquement le nom (français ou arabe) et une photo JPEG, PNG ou WebP du menu (5 Mo maximum).
3. Appuyer sur **Continuer dans WhatsApp**, puis envoyer soi-même le message prérempli à TUC. Aucun message WhatsApp n’est envoyé automatiquement.
4. L’équipe TUC ouvre **Restaurants → Demandes TUC**, retrouve le dossier grâce au code affiché dans la conversation et vérifie le numéro de l’expéditeur.
5. L’équipe saisit/confirme le numéro et choisit **Créer le restaurant et préparer l’accès**. Le compte restaurateur, le restaurant et l’affectation tenant sont alors créés ensemble.
6. Le bouton **Ouvrir WhatsApp pour envoyer l’accès** ouvre un message prérempli avec un lien personnel à usage unique, valable 15 minutes. L’équipe l’envoie manuellement. Si le lien expire, **Préparer un nouveau lien WhatsApp** révoque l’ancien et en crée un autre.
7. Le lien connecte le restaurateur à `/espace-pro/`. Aucun mot de passe n’est transmis.

## WhatsApp et n8n

Le formulaire est sans fournisseur de géocodage, Google Places, WhatsApp Cloud API ni autre API payante. `wa.me` ouvre simplement WhatsApp pour que la personne appuie elle-même sur Envoyer.

Si un adaptateur entrant signé est configuré, le webhook TUC/n8n peut associer automatiquement au dossier le numéro reçu. Sinon, l’équipe retrouve le code dans sa conversation et saisit le numéro dans l’interface d’administration. Le formulaire initial ne demande ni téléphone, ni e-mail, ni mot de passe.

Le workflow Docker de GitHub démarre n8n localement et teste le message entrant simulé, la vérification HMAC, la création différée par l’équipe, le lien magique mobile et son usage unique. Il ne contacte aucun vrai compte WhatsApp.
