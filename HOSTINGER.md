# Brancher GitHub → Hostinger

Tout est déjà poussé. Il reste **6 étapes** côté Hostinger + **1 étape** côté GitHub.
Compte 15 minutes.

---

## ÉTAPE 1 · Activer l'accès SSH

`hPanel` → `Avancé` → `Accès SSH`

1. Ouvre le panneau **Accès SSH**
2. Note les 3 informations affichées :

```
Hôte SSH   : srvXXX.hostinger.com      ← ou une IP
Utilisateur: u123456789
Port       : 65002
```

3. Si l'accès SSH est désactivé, clique **Activer**

> ⚠️ Ne confonds pas avec le **FTP**. Le FTP c'est le port 21, l'SSH c'est 65002.
> C'est l'SSH dont le CI a besoin.

---

## ÉTAPE 2 · Autoriser la clé publique

Toujours dans `Avancé` → `Accès SSH`, section **Clés SSH** :

1. Clique **Gérer les clés** / **Ajouter une clé**
2. Colle **toute cette ligne** (une seule ligne, en entier) :

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICLqP0eBSZ/OE3HLUccMckN7SwcLUL2P0y/ZLE4Zbiv/ arena-agent@tuc.ma
```

3. Nom : `github-actions-tuc`
4. **Enregistrer**

> La clé **privée** correspondante est déjà dans le secret GitHub `SSH_PRIVATE_KEY`.
> Ne la colle nulle part ailleurs. Ne la mets jamais dans le repo.

**Vérification** (facultatif, depuis ton Mac) :

```bash
ssh -i ~/.ssh/tuc_deploy -p 65002 u123456789@srvXXX.hostinger.com "echo OK"
```

Si tu n'as pas la clé privée sur ton Mac, saute cette vérification : le CI la fera.

---

## ÉTAPE 3 · Trouver le chemin de WordPress

C'est l'erreur n°1 des déploiements qui échouent : un `WP_ROOT` faux.

**Méthode A — terminal Hostinger** (`hPanel` → `Avancé` → `Terminal` ou `Accès SSH`) :

```bash
ls ~/domains
```

Tu verras un dossier par site. Puis :

```bash
ls ~/domains/tuc.ma/public_html | head
```

Si tu vois `wp-config.php`, `wp-content`, `wp-admin` → **c'est le bon dossier**.

**Méthode B — gestionnaire de fichiers** : ouvre `public_html`, cherche `wp-config.php`.

Le `WP_ROOT` à utiliser dans le secret dépend de ce que tu trouves :

| Ce que tu vois | Valeur de `WP_ROOT` |
|---|---|
| `domains/tuc.ma/public_html` | `domains/tuc.ma/public_html` |
| `public_html` directement à la racine | `public_html` |

> Le rsync utilise `WP_ROOT` **sans** `~` devant, relatif au dossier utilisateur.

---

## ÉTAPE 4 · Créer le sous-domaine de test (recommandé)

`hPanel` → `Domaines` → `Sous-domaines`

```
Sous-domaine : dev.tuc.ma
Dossier      : domains/dev.tuc.ma/public_html   (ou public_html/dev)
```

Installe WordPress dessus (voir étape 5), puis `SITE_URL` = `https://dev.tuc.ma`.

> Sans sous-domaine de test, ton `develop` écraserait la prod. Ne fais pas ça.

---

## ÉTAPE 5 · Installer WordPress (si pas déjà fait)

`hPanel` → `Sites web` → `Gérer` → `WordPress` → **Installer**

- Langue : **Français**
- Nom du site : `TUC`
- Utilisateur : note-le, il n'est pas dans le CI

Puis dans `wp-admin` :

`Réglages` → `Permaliens` → **Nom de l'article** → Enregistrer
→ indispensable pour que `/wp-json/` fonctionne.

---

## ÉTAPE 6 · Remplir les secrets GitHub

`github.com/bernoussifatiha84-good/tuc-restaurant` → `Settings` → `Environments`

Tu as déjà deux environnements : **`dev`** et **`prd`** (je les ai créés).

### Environnement `dev`

| Nom | Valeur | Déjà fait ? |
|---|---|---|
| `SSH_PRIVATE_KEY` | *(la clé ed25519)* | ✅ **déjà en place** |
| `SSH_HOST` | `srvXXX.hostinger.com` | ⬜ à toi |
| `SSH_USER` | `u123456789` | ⬜ à toi |
| `WP_ROOT` | `domains/dev.tuc.ma/public_html` | ⬜ à toi |
| `SITE_URL` | `https://dev.tuc.ma` | ⬜ à toi |

Variable (onglet **Variables**, pas Secrets) — déjà en place :

| Nom | Valeur |
|---|---|
| `SSH_PORT` | `65002` ✅ |

### Environnement `prd`

Mêmes 4 secrets, mais :

| Nom | Valeur |
|---|---|
| `WP_ROOT` | `domains/tuc.ma/public_html` |
| `SITE_URL` | `https://tuc.ma` |

> `SSH_PRIVATE_KEY` est **par environnement**, pas global. Il faudra la recoller aussi dans `prd`.
> Le workflow lit l'environnement `dev` pour `develop`, `prd` pour `release-*`.

### En ligne de commande (si tu préfères)

```bash
gh secret set SSH_HOST -R bernoussifatiha84-good/tuc-restaurant --env dev --body "srvXXX.hostinger.com"
gh secret set SSH_USER -R bernoussifatiha84-good/tuc-restaurant --env dev --body "u123456789"
gh secret set WP_ROOT  -R bernoussifatiha84-good/tuc-restaurant --env dev --body "domains/dev.tuc.ma/public_html"
gh secret set SITE_URL -R bernoussifatiha84-good/tuc-restaurant --env dev --body "https://dev.tuc.ma"
```

---

## ÉTAPE 7 · Lancer et activer le thème

```bash
git checkout develop
git commit --allow-empty -m "test: premier déploiement"
git push origin develop
```

Puis `Actions` → le run doit finir **vert**.

Ensuite, **une seule fois**, dans `wp-admin` :

`Apparence` → `Thèmes` → **TUC Market** → **Activer**

Et : `Réglages` → `Lecture` → page d'accueil = **une page statique vide**
→ c'est `front-page.php` qui rend l'accueil.

---

## Vérifier que ça marche

```bash
curl https://dev.tuc.ma/wp-json/tuc/v1/sante
```

Réponse attendue :

```json
{"ok":true,"theme":"0.1.0","heure":"2026-11-04T20:00:00+01:00","restos":28}
```

---

## Dépannage

| Message dans Actions | Cause | Solution |
|---|---|---|
| `Secret manquant : S_HOST` | step 6 pas fait | ajouter les 4 secrets |
| `Permission denied (publickey)` | clé publique non autorisée | refaire étape 2 |
| `rsync: change_dir failed` | `WP_ROOT` faux | refaire étape 3 (`pwd` en SSH) |
| `Connection refused` | mauvais port | `SSH_PORT` = `65002` (pas 22, pas 21) |
| `/sante` répond 404 | thème non activé | étape 7, activer TUC Market |
| `/sante` répond 500 | permaliens | `Réglages > Permaliens > Enregistrer` |
| `bash: wp: command not found` | WP-CLI absent | **non bloquant**, le workflow continue |

---

## Ce que le CI fait à chaque push

```
push develop
  └─ 1. php -l  sur 7 fichiers PHP          → échoue si une virgule manque
  └─ 2. accolades CSS équilibrées           → échoue si une accolade est ouverte
  └─ 3. node --check sur les JS             → échoue si une parenthèse manque
  └─ 4. présence des 5 secrets              → échoue en te disant lequel manque
  └─ 5. rsync --delete du thème             → miroir exact, pas de fichier fantôme
  └─ 6. purge cache + permaliens            → si wp-cli existe
  └─ 7. GET /wp-json/tuc/v1/sante           → échoue si le site ne répond pas
```

**Le point important** : le déploiement est **rouge** si le site est cassé.
Pas de « c'est vert mais la page est blanche ».

---

## Récapitulatif des branches

| Branche | Environnement GitHub | Cible |
|---|---|---|
| `develop` | `dev` | sous-domaine de test |
| `release-0.2.0` | `prd` | `tuc.ma` |
| `main` | *aucun déploiement* | vitrine du code |
