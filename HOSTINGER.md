# Brancher GitHub → Hostinger

Tout est déjà poussé. Il reste **7 étapes** côté Hostinger + GitHub.
Compte 15 minutes.

---

## ÉTAPE 1 · Activer l'accès SSH

`hPanel` → `Avancé` → `Accès SSH`

Note les 3 informations affichées :

```
Hôte SSH    : srvXXX.hostinger.com      (ou une IP)
Utilisateur : u123456789
Port        : 65002
```

Si l'accès SSH est désactivé, clique **Activer**.

> Ne confonds pas avec le **FTP** (port 21). Le CI a besoin de l'**SSH** (65002).

---

## ÉTAPE 2 · Autoriser la clé publique

Même écran, section **Clés SSH** → **Gérer les clés** → **Ajouter**

Colle **cette ligne, en entier** :

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICLqP0eBSZ/OE3HLUccMckN7SwcLUL2P0y/ZLE4Zbiv/ arena-agent@tuc.ma
```

Nom : `github-actions-tuc` → **Enregistrer**

> La clé **privée** correspondante est déjà dans le secret GitHub `SSH_PRIVATE_KEY`.
> Ne la colle nulle part ailleurs, jamais dans le repo.

---

## ÉTAPE 3 · Trouver le chemin de WordPress

C'est l'erreur n°1 des déploiements qui échouent : un `WP_ROOT` faux.

`hPanel` → `Avancé` → `Terminal` (ou connexion SSH) :

```bash
ls ~/domains
ls ~/domains/tuc.ma/public_html | head
```

Si tu vois `wp-config.php`, `wp-content`, `wp-admin` → **c'est le bon dossier**.

| Ce que tu trouves | Valeur de `WP_ROOT` |
|---|---|
| `domains/tuc.ma/public_html` | `domains/tuc.ma/public_html` |
| `public_html` à la racine | `public_html` |

> Sans `~` devant. Relatif au dossier utilisateur.

---

## ÉTAPE 4 · Créer le sous-domaine de test (obligatoire)

`hPanel` → `Domaines` → `Sous-domaines`

```
Sous-domaine : dev.tuc.ma
Dossier      : domains/dev.tuc.ma/public_html
```

Installe WordPress dessus à l'étape 5, puis `SITE_URL` = `https://dev.tuc.ma`.

> Sans sous-domaine de test, ton `develop` écraserait la prod.

---

## ÉTAPE 5 · Installer WordPress

`hPanel` → `Sites web` → `Gérer` → `WordPress` → **Installer**

- Langue : **Français**
- Nom du site : `TUC`
- Note l'utilisateur admin (il n'est pas dans le CI)

Puis dans `wp-admin` :
`Réglages` → `Permaliens` → **Nom de l'article** → Enregistrer
→ sans ça, `/wp-json/` renvoie 404.

---

## ÉTAPE 6 · Remplir les secrets GitHub

`github.com/bernoussifatiha84-good/tuc-restaurant` → `Settings` → `Environments`

Deux environnements existent déjà : **`dev`** et **`prd`**.

### Environnement `dev`

| Nom | Valeur | État |
|---|---|---|
| `SSH_PRIVATE_KEY` | *(clé ed25519)* | ✅ **déjà en place** |
| `SSH_HOST` | `srvXXX.hostinger.com` | ⬜ à toi |
| `SSH_USER` | `u123456789` | ⬜ à toi |
| `WP_ROOT` | `domains/dev.tuc.ma/public_html` | ⬜ à toi |
| `SITE_URL` | `https://dev.tuc.ma` | ⬜ à toi |

Variable déjà en place (onglet **Variables**) : `SSH_PORT` = `65002` ✅

### Environnement `prd`

Mêmes secrets, avec :

| Nom | Valeur |
|---|---|
| `WP_ROOT` | `domains/tuc.ma/public_html` |
| `SITE_URL` | `https://tuc.ma` |

> `SSH_PRIVATE_KEY` est **par environnement**. Il faut la recoller dans `prd` aussi.

### En ligne de commande

```bash
gh secret set SSH_HOST -R bernoussifatiha84-good/tuc-restaurant --env dev --body "srvXXX.hostinger.com"
gh secret set SSH_USER -R bernoussifatiha84-good/tuc-restaurant --env dev --body "u123456789"
gh secret set WP_ROOT  -R bernoussifatiha84-good/tuc-restaurant --env dev --body "domains/dev.tuc.ma/public_html"
gh secret set SITE_URL -R bernoussifatiha84-good/tuc-restaurant --env dev --body "https://dev.tuc.ma"
```

---

## ÉTAPE 7 · Lancer, puis activer le thème

```bash
git checkout develop
git commit --allow-empty -m "test: premier déploiement"
git push origin develop
```

`Actions` → le run doit finir **vert**.

Puis **une seule fois** dans `wp-admin` :

1. `Apparence` → `Thèmes` → **TUC Market** → **Activer**
2. `Réglages` → `Lecture` → page d'accueil = **une page statique vide**
   (c'est `front-page.php` qui rend l'accueil)

---

## Vérifier

```bash
curl https://dev.tuc.ma/wp-json/tuc/v1/sante
```

```json
{"ok":true,"theme":"0.1.0","heure":"2026-11-04T20:00:00+01:00","restos":28}
```

---

## Dépannage

| Message dans Actions | Cause | Solution |
|---|---|---|
| `Secret manquant : S_HOST` | étape 6 pas faite | ajouter les 4 secrets |
| `Permission denied (publickey)` | clé non autorisée | refaire étape 2 |
| `rsync: change_dir failed` | `WP_ROOT` faux | étape 3 (`pwd` en SSH) |
| `Connection refused` | mauvais port | `SSH_PORT` = `65002` |
| `/sante` → 404 | thème non activé | étape 7 |
| `/sante` → 500 | permaliens | `Réglages > Permaliens > Enregistrer` |
| `bash: wp: command not found` | WP-CLI absent | **non bloquant** |

---

## Ce que le CI fait à chaque push

```
push develop
  ├─ 1. php -l sur 8 fichiers PHP          échoue si une virgule manque
  ├─ 2. accolades CSS équilibrées          échoue si une accolade est ouverte
  ├─ 3. node --check sur les JS            échoue si une parenthèse manque
  ├─ 4. présence des 5 secrets             échoue en disant lequel manque
  ├─ 5. rsync --delete du thème            miroir exact, zéro fichier fantôme
  ├─ 6. purge cache + permaliens           si wp-cli existe
  └─ 7. GET /wp-json/tuc/v1/sante          échoue si le site ne répond pas
```

**Le point clé** : le déploiement est **rouge** si le site est cassé.
Pas de « c'est vert mais la page est blanche ».

---

## Branches

| Branche | Environnement | Cible |
|---|---|---|
| `develop` | `dev` | sous-domaine de test |
| `release-0.2.0` | `prd` | `tuc.ma` |
| `main` | *aucun déploiement* | vitrine du code |
