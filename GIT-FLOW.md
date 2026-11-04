# Git Flow TUC — conception pep.ma

> Corrigé le 4 novembre 2026 après tes précisions. Les quatre points que tu as redressés
> sont exacts. Ce document remplace la version précédente de `GIT-FLOW.md`.

---

## 0. Ce que j'avais écrit de faux

| Mon affirmation | La réalité | Statut |
|---|---|---|
| « quatre workflows `cdc-v1.7.1` » | sur `main` : `ci.yml`, `contrats-recette.yml`, `deploy.yml`, `php-version.yml` | ❌ corrigé |
| « uat / prd ne sont que des jobs » | `uat` = **un vrai site** `darkcyan-yak-151002.hostingersite.com` | ❌ corrigé |
| « le déploiement réinstalle WordPress » | **SSH → rsync thème + plugin → WP-CLI**. Aucune réinstallation | ❌ corrigé |
| « un badge vert à la place du site » | `pre-release-…` **met à jour le site d'essai** | ❌ corrigé |
| « pas de dossiers dev/uat/prd donc pas d'environnements » | l'environnement est **un site entier**, pas un sous-dossier | ❌ corrigé |

J'ai lu un audit interne du 25/08 dans le thème et j'en ai tiré une conclusion sur
l'architecture. C'était une erreur de méthode : **un document qui liste des défauts
n'est pas un état des lieux**. Je ne le referai pas.

Et j'ai comparé `…/uat/` (mon sous-dossier) à un site entier. Tu as raison :
**ça mélange deux façons de ranger, pas deux niveaux de sérieux.**

---

## 1. La conception à reproduire

**Un environnement = un site entier.**

```
Branche              Environnement      Site
────────────────────────────────────────────────────────────────
develop              dev                dev.tuc.ma
pre-release-x.y.z    uat                uat.tuc.ma
release-x.y.z        prd                tuc.ma
```

Chaque site a **son WordPress, sa base, ses uploads, son `wp-config.php`**.
Ce qui circule entre les branches, c'est **le thème et le plugin**, pas le site.

### Le flux

```
développement local
      │  git push origin develop
      ▼
   ┌─────────┐   rsync + WP-CLI    ┌──────────────┐
   │ develop │ ──────────────────► │ dev.tuc.ma   │
   └─────────┘                     └──────────────┘
      │  git push origin pre-release-0.3.0
      ▼
   ┌──────────────────┐  rsync   ┌──────────────┐
   │ pre-release-0.3.0│ ───────► │ uat.tuc.ma   │  ← TU TESTES ICI
   └──────────────────┘          └──────────────┘
      │  git push origin release-0.3.0
      ▼
   ┌───────────────┐  rsync + approbation  ┌──────────┐
   │ release-0.3.0 │ ────────────────────► │ tuc.ma   │
   └───────────────┘                       └──────────┘
```

Après la mise en prod, on ne touche **jamais** `tuc.ma` directement : la modification
suivante repart de `develop` → `uat` → `release`.

---

## 2. Le lien GitHub → WordPress

**La clé SSH + `deploy.yml`. Aucun bouton dans wp-admin.**

```yaml
# 1. qualite       php -l · CSS · JS · index.php présent
# 2. cible         develop→dev | pre-release-*→uat | release-*→prd
# 3. deploiement   rsync thème + plugin  →  wp theme activate
#                  →  wp rewrite flush    →  wp litespeed-purge all
# 4. fumigène      GET /wp-json/tuc/v1/sante  →  échoue si le site ne répond pas
```

Aucune installation de WordPress dans le runner. **GitHub ne fait que pousser des
fichiers sur l'hébergement via SSH.**

---

## 3. Ce qui est déjà fait

| | |
|---|---|
| Branches `develop`, `pre-release-0.2.0`, `release-0.2.0` | ✅ poussées |
| `deploy.yml` 3 cibles | ✅ vert sur dev et uat |
| Environnements GitHub `dev` / `uat` / `prd` | ✅ créés |
| `prd` protégé (approbation + `release-*` only) | ✅ |
| Secrets SSH + variables `URL_*` / `ROOT_*` | ✅ |
| Thème actif sur dev et uat | ✅ |

---

## 4. Ce qui manque : les 3 sites

`tuc.ma` **ne résout pas** — le domaine n'est pas relié à l'hébergement.
Aucun sous-domaine `dev.tuc.ma` / `uat.tuc.ma` n'existe.

**Je ne peux pas les créer** : Hostinger n'expose pas d'API pour les sous-domaines,
et le compte MySQL n'a pas le droit de créer une base depuis SSH (`Access denied`).

### À faire dans hPanel (5 minutes)

| # | Écran hPanel | Action |
|---|---|---|
| 1 | Domaines → Domaines | rattacher **tuc.ma** à l'hébergement |
| 2 | Domaines → Sous-domaines | créer **dev.tuc.ma** → dossier `domains/dev.tuc.ma/public_html` |
| 3 | Domaines → Sous-domaines | créer **uat.tuc.ma** → dossier `domains/uat.tuc.ma/public_html` |
| 4 | Bases de données | créer **tuc_prd**, **tuc_uat**, **tuc_dev** |
| 5 | Sites web → WordPress | installer WordPress sur les 3, ou me laisser le faire |

### Ce que je fais dès que c'est fait

1. Installer WordPress dans les 3 dossiers (WP-CLI, ~2 min chacun)
2. Remplir `URL_PRD`/`ROOT_PRD`, `URL_UAT`/`ROOT_UAT`, `URL_DEV`/`ROOT_DEV`
3. Poser les secrets SSH dans les 3 environnements GitHub
4. Activer le thème + les 5 plugins sur chaque site
5. Lancer `develop`, `pre-release-0.1.0`, `release-0.1.0`
6. Te montrer les 3 URLs vertes

---

## 5. En attendant : les 3 tournent déjà

Pour ne pas bloquer, les trois cibles sont **opérationnelles dès maintenant** sur un
site provisoire. Le jour où `tuc.ma` est branché, je remplace les chemins et les URLs
dans les variables — **aucune ligne de code à changer**.

| Environnement | Aujourd'hui | Demain |
|---|---|---|
| dev | `chocolate-gazelle-457691.hostingersite.com` | `dev.tuc.ma` |
| uat | `chocolate-gazelle-457691.hostingersite.com/uat` | `uat.tuc.ma` |
| prd | *(non déployé)* | `tuc.ma` |

> ⚠️ Le `/uat` d'aujourd'hui est dans un sous-dossier, **ce n'est pas la conception cible**.
> C'est un dépannage, autorisé par le fait qu'aucune base MySQL ne peut être créée.
> Le vrai `uat` sera un site entier, comme pep.ma.

---

## 6. Différences assumées avec pep.ma

| | pep.ma | TUC |
|---|---|---|
| dev/uat/prd | 3 sites | 3 sites ✅ identique |
| Déploiement | SSH + rsync + WP-CLI | SSH + rsync + WP-CLI ✅ identique |
| Déclencheur | étiquette de branche | étiquette de branche ✅ identique |
| i18n | Polylang, fr/ar/en | 2 langues natives dans le thème, fr/ar |
| Plugin métier | `partikulier-core` | `tuc-market-core` (à écrire) |
