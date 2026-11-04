# Pousser le repo depuis ton Mac

L'environnement d'Arena a été réinitialisé : **je n'ai plus accès à ton GitHub**
(pas de `gh`, pas de token, pas de remote). Voici la commande exacte.

## 1. Récupérer les fichiers

Télécharge le dossier `tuc-restaurant/` depuis l'espace de travail, puis :

```bash
cd tuc-restaurant
git remote add origin git@github.com:bernoussifatiha84-good/tuc-restaurant.git
git branch -M main
git push -u origin main --force
```

> `--force` écrase le commit initial vide. Il n'y a rien à perdre : le repo ne contient qu'un README d'une ligne.

## 2. Créer les environnements GitHub

`Settings → Environments` → **New environment** → crée `dev` puis `prd`.

Dans chacun, ajoute les 6 secrets :

| Secret | Valeur |
|---|---|
| `SSH_HOST` | ton hôte SSH Hostinger |
| `SSH_USER` | ton identifiant hPanel (`u…`) |
| `SSH_PORT` | `65002` |
| `SSH_PRIVATE_KEY` | la clé privée ed25519 complète |
| `WP_ROOT` | `domains/tuc.ma/public_html` |
| `SITE_URL` | `https://dev.tuc.ma` |

## 3. Autoriser la clé sur Hostinger

`hPanel → Avancé → Accès SSH → Gérer les clés SSH` → colle le contenu de la clé **publique**.

## 4. Tester

```bash
git checkout -b develop
git commit --allow-empty -m "test: premier déploiement"
git push origin develop
```

`Actions` doit afficher : PHP OK → CSS OK → JS OK → rsync → `/sante` vert.

## 5. Installer le thème

Si le `WP_ROOT` est bon, le thème arrive tout seul dans `wp-content/themes/tuc-theme`.
Il reste **à l'activer une fois** :

`Apparence → Thèmes → TUC Market → Activer`

---

## En cas d'échec

| Message | Cause |
|---|---|
| `Permission denied (publickey)` | clé publique non autorisée sur hPanel |
| `rsync: change_dir failed` | `WP_ROOT` faux (vérifie avec `pwd` en SSH) |
| `/sante ne répond pas` | le thème n'est pas activé, donc la route REST n'existe pas |
