# Checklist avant UAT finale et ouverture publique

Cette checklist ne remplace pas la revue de code. Elle sert à éviter qu’un résultat CI vert soit interprété comme une autorisation de mise en production.

## 1. Intégration GitHub

- [x] Le code métier de la PR #1 et la régression NVDA automatisée sont réunis dans la PR #2.
- [x] `develop` est fusionné : `git merge-base --is-ancestor origin/develop HEAD` vrai, 0 commit manquant (garde-fou `tests/lineage-contract.py`).
- [x] Contrat de déploiement re-testé sans déployer : `tests/deploy-contract.py`, 10 cas de résolution + garde-fous.
- [x] Garde-fou de cohérence des versions : en-tête == constante, et tag `pre-release-X` ⇒ version `X`, vérifié avant le rsync (`tests/version-contract.py`, auto-test rejouant le tag fautif).
- [ ] Tags `pre-release-0.8.9` / `0.8.10` : anomalies **documentées** dans `docs/VERSIONS-ET-TAGS.md`, non supprimées (ce sont les traces de ce qui a tourné).
- [x] L’ancien job NVDA `espeakWav` externe est supprimé.
- [x] Le workflow `WordPress Docker smoke test` passe sur la révision d’intégration.
- [ ] Revue humaine de la PR d’intégration.
- [ ] Décision explicite de fusion vers `main` ; aucune fusion automatique depuis cette session.

Run d’intégration de référence : <https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42482683516>.

## 2. UAT / préproduction

- [ ] Confirmer `URL_UAT`, `ROOT_UAT`, la version WordPress/PHP/base et l’accès SSH.
- [ ] Sauvegarde complète vérifiable avant déploiement ; restauration testée.
- [x] Déploiement UAT rejoué sur la branche fusionnée : tag `pre-release-0.8.10`, run [42488078757](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42488078757), UAT en plugin 0.8.9 / thème 0.2.0.
- [ ] Prochain tag aligné sur sa version (`pre-release-0.8.11` ⇒ plugin `0.8.11`), le garde-fou le refuse sinon.
- [ ] Vérifier que la cible UAT n’est pas la production (`rsync --delete` est destructeur).
- [ ] Ne pas désactiver/supprimer/migrer WooCommerce sans décision écrite et sauvegarde.

## 3. Parcours métier à valider sur UAT

- [ ] Restaurateur : demande WhatsApp → vérification humaine → boutique brouillon → menu → approbation → publication.
- [ ] Client : fiche restaurant → panier → commande cash → réception caisse → suivi.
- [ ] QR table : mode sur place, commerce et table préremplis, livraison masquée.
- [ ] Livraison : adresse, zone, frais et minimum validés côté serveur.
- [ ] Réseau lent / reprise : pas de doublon de commande, statut retrouvé après reconnexion.
- [ ] Permissions croisées entre deux restaurants bloquées, y compris médias et statuts.

## 4. WhatsApp réel

- [ ] Choisir le fournisseur/API officiel pour les numéros TUC Pro et clients.
- [ ] Adapter la vérification de signature au fournisseur réel, pas seulement au HMAC interne simulé.
- [ ] Tester retries, identifiants d’événement, expiration et anti-rejeu avec le fournisseur.
- [ ] Configurer les secrets hors Git et vérifier les coûts/règles de messages.
- [ ] Valider le parcours manuel `wa.me` : ouverture seule ne crée ni compte ni commande.

## 5. Accessibilité et langues

- [x] Régression automatisée NVDA + eSpeak : PCM français/arabe non silencieux, focus lien WhatsApp. **Ne vaut pas validation d’accessibilité.**
- [ ] Écoute humaine des WAV publiés.
- [ ] Test manuel NVDA/Firefox ou Chrome sur vrai périphérique audio.
- [ ] Test manuel VoiceOver/Safari sur appareil Apple réel.
- [ ] Parcours français puis arabe/RTL avec personnes concernées ; anglais seulement après traduction relue.

## 6. Sécurité, confidentialité, exploitation

- [ ] Durée de conservation, purge, export/effacement des données personnelles définis.
- [ ] Rate limiting applicatif + proxy/WAF vérifiés derrière CDN/hébergement.
- [ ] Journaux sans secrets ni données personnelles inutiles.
- [ ] Sauvegarde/restauration répétée et procédure de rollback documentée.
- [ ] Support et délai de rappel humain réalistes pour les restaurateurs.
- [ ] Décision propriétaire sur gratuité, modes de service, langues et pilote.

## 7. Ouverture publique

Pas d’ouverture publique tant que les sections UAT, métier, WhatsApp réel, accessibilité humaine et sécurité/confidentialité ne sont pas validées ensemble. Le pilote doit rester limité à des commerces consentants et des commandes de test jusqu’au feu vert explicite.
