# Git Flow TUC — conception pep.ma

> Version du 4 novembre 2026, corrigée deux fois. `pre-release-…` et `release-…`
> ne sont pas des branches : **ce sont des étiquettes (tags)**.

---

## 1. La règle

| Déclencheur | Nature | Cible | Commande |
|---|---|---|---|
| branche `develop` | **branche** | dev | `git push origin develop` |
| étiquette `pre-release-x.y.z` | **tag** | uat | `git tag -a pre-release-0.3.0 -m "…" && git push origin pre-release-0.3.0` |
| étiquette `release-x.y.z` | **tag** | prd | `git tag -a release-0.3.0 -m "…" && git push origin release-0.3.0` |

⚠️ **Pousser une branche nommée `pre-release-…` ne déploie rien.** Le workflow déclare :

```yaml
on:
  push:
    branches: [develop]
    tags:
      - 'pre-release-*'
      - 'release-*'
```

Un tag ne peut pas être poussé par `git push origin develop` : il faut le créer puis
le pousser explicitement.

---

## 2. Un environnement = un site entier

```
develop            →  dev.tuc.ma      site entier
tag pre-release-*  →  uat.tuc.ma      site entier   ← on teste ici
tag release-*      →  tuc.ma          site entier   ← approbation manuelle
```

Chaque site a son WordPress, sa base, ses uploads, son `wp-config.php`.
**Ce qui circule entre les branches, c'est le thème et le plugin — pas le site.**

---

## 3. Le déploiement, étape par étape

```
1. qualite        php -l · CSS · JS · index.php + Version présents
2. cible          develop→dev | tag pre-release-*→uat | tag release-*→prd
3. deploiement    SSH → rsync thème (--delete)
                  SSH → rsync plugin tuc-market-core (--delete)
                  SSH → wp plugin activate tuc-market-core
                        wp theme activate tuc-theme
                        wp option update permalink_structure
                        wp cache flush
                        wp rewrite flush --hard
                        wp litespeed-purge all
4. fumigène       GET /wp-json/tuc/v1/sante   → doit répondre {"ok":true}
```

Aucune installation de WordPress dans le runner. **GitHub pousse des fichiers, rien de plus.**

### Le module de santé

`/wp-json/tuc/v1/sante` répond depuis le **plugin**, pas depuis le thème :

```json
{"ok":true,"plugin":"0.1.0","theme":"TUC Market","theme_ver":"0.1.0",
 "wp":"7.1.4","php":"8.3.36","langues":["fr_FR","ltr"],"plugins_ko":[]}
```

- `ok` = thème **TUC Market** actif **et** plugin présent **et** aucun plugin requis manquant
- si le thème est cassé, le plugin répond quand même → le CI distingue
  « thème cassé » de « site mort »
- réponse **503** quand `ok:false`, pour que le déploiement échoue proprement

---

## 4. État réel au 4 novembre 2026

| | |
|---|---|
| Branches | `develop`, `main` |
| Étiquettes | `pre-release-0.2.0`, `pre-release-0.2.1`, `release-0.2.0` |
| Dernier déploiement uat | **tag `pre-release-0.2.1` → vert** |
| Étapes | 9/9 · thème + plugin + activation + purge + fumigène |
| Santé | `{"ok":true,…,"plugins_ko":[]}` |

---

## 5. Ce qui reste, et qui n'est pas technique

`tuc.ma`, `dev.tuc.ma`, `uat.tuc.ma` **ne résolvent pas**. Le domaine n'est pas rattaché
à l'hébergement, et Hostinger n'a pas d'API pour les sous-domaines ni pour créer
une base MySQL (`Access denied` en SSH).

**Tant que ces trois sites n'existent pas, le flux TUC n'est pas encore celui de pep.ma.**
Il en a la mécanique, pas les trois environnements.

### 5 actions dans hPanel

1. `Domaines` → rattacher **tuc.ma**
2. `Sous-domaines` → **dev.tuc.ma** → `domains/dev.tuc.ma/public_html`
3. `Sous-domaines` → **uat.tuc.ma** → `domains/uat.tuc.ma/public_html`
4. `Bases de données` → **tuc_prd**, **tuc_uat**, **tuc_dev**
5. Prévenir — j'installe les 3 WordPress et je câble le reste

### Ce que je fais ensuite

Installer WordPress × 3 · remplir `URL_*` / `ROOT_*` · poser les secrets dans les
3 environnements · activer thème + 5 plugins · pousser `develop`, taguer
`pre-release-0.1.0` puis `release-0.1.0` · te montrer les 3 URLs vertes.

---

## 6. En attendant : le provisoire, assumé

| Environnement | Site provisoire | Site cible |
|---|---|---|
| dev | `chocolate-gazelle-457691.hostingersite.com` | `dev.tuc.ma` |
| uat | `…chocolate-gazelle…/uat` | `uat.tuc.ma` |
| prd | *(non déployé)* | `tuc.ma` |

Le `/uat` dans un sous-dossier **n'est pas le modèle** : c'est un dépannage rendu
nécessaire par l'impossibilité de créer une base MySQL. Le jour où les sous-domaines
existent, je change **deux variables** (`URL_UAT`, `ROOT_UAT`) — **zéro ligne de code**.
