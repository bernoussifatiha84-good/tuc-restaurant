# Sécurité et gestion des secrets

- Ne jamais commiter de clé privée SSH, jeton d’accès, mot de passe, secret de webhook ou clé privée VAPID.
- Les secrets de déploiement doivent être stockés dans les GitHub Actions Secrets / Environments ou dans un gestionnaire de secrets approuvé, jamais dans le code ou la documentation versionnée.
- Ne pas ajouter au dépôt les dossiers `08-secrets/`, `04-serveur-audit/` ni les archives de passation. Ils peuvent contenir des identifiants, des données d’environnement ou des clés privées.
- Si un secret a été partagé ou commité, le révoquer et le remplacer. Supprimer seulement le fichier ne suffit pas à invalider une clé déjà exposée.
- Les scripts et logs de déploiement ne doivent pas afficher le contenu des secrets.

Le workflow de déploiement utilise SSH et rsync. Les règles d’approbation de l’environnement de production doivent être configurées dans GitHub; le fichier YAML ne peut pas imposer à lui seul ces approbations.
