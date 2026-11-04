# ✅ DÉPLOYÉ — https://chocolate-gazelle-457691.hostingersite.com

## État réel au 4 novembre 2026

| Élément | Valeur |
|---|---|
| Site provisoire | `chocolate-gazelle-457691.hostingersite.com` |
| Thème actif | **TUC Market 0.1.0** |
| WordPress | 7.1.4 |
| CI/CD | **vert** — run `41910279842` |
| `/wp-json/tuc/v1/sante` | `{"ok":true,"theme":"0.1.0","restos":25}` |
| Poids de la page | **72 Ko** HTML · 1,5 Mo de thème |
| Temps de réponse | **0,26 s** |

### Secrets configurés dans l'environnement `dev`

| Nom | Valeur |
|---|---|
| `SSH_HOST` | `145.223.38.175` |
| `SSH_USER` | `u987613820` |
| `SSH_PRIVATE_KEY` | clé ed25519 `arena-agent@tuc.ma` ✅ autorisée sur hPanel |
| `WP_ROOT` | `domains/chocolate-gazelle-457691.hostingersite.com/public_html` |
| `SITE_URL` | `https://chocolate-gazelle-457691.hostingersite.com` |
| `SSH_PORT` *(variable)* | `65002` |

### Pour passer en production

1. Créer `tuc.ma` dans hPanel (ou pointer le domaine vers le compte)
2. Installer WordPress dessus
3. Remplir `WP_ROOT` + `SITE_URL` (+ recoller `SSH_PRIVATE_KEY`) dans l'environnement **`prd`**
4. `git checkout -b release-0.2.0 && git push origin release-0.2.0`

---

# Guide de référence (SSH)


---

# Les 3 environnements (règle définitive)

**L'étiquette de la branche décide de la cible. Rien d'autre.**

| Branche | Environnement GitHub | Site | Chemin |
|---|---|---|---|
| `develop` | **dev** | https://chocolate-gazelle-457691.hostingersite.com | `domains/chocolate-gazelle…/public_html` |
| `pre-release-…` | **uat** | https://chocolate-gazelle-457691.hostingersite.com**/uat** | `…/public_html/uat` |
| `release-…` | **prd** | `https://tuc.ma` *(à créer)* | `domains/tuc.ma/public_html` |

Le lien GitHub → WordPress = **la clé SSH + `deploy.yml`**. Aucun bouton dans wp-admin ne déclenche un déploiement.

## Le job `cible`

```yaml
case "$BRANCH" in
  develop)        E=dev ;;
  pre-release-*)  E=uat ;;
  release-*)      E=prd ;;
  *) echo "::error::Branche sans cible"; exit 1 ;;
esac
```

Une branche `feature/…` **n'échoue pas silencieusement** : elle est refusée avec un message clair.

## Ce qui tourne à chaque déploiement

```
1. php -l · CSS · JS                          → syntaxe
2. index.php + en-tête Version présents       → thème activable
3. résolution de la cible                     → dev | uat | prd
4. contrôle des 5 secrets                     → dit lequel manque
5. rsync --delete du thème                    → miroir exact
6. rsync du plugin tuc-market-core (si là)    → automatique le jour où il existe
7. wp theme activate + permaliens + purge     → le site sert le nouveau code
8. GET /wp-json/tuc/v1/sante (repli ?rest_route=)  → échoue si le site est cassé
```

## Variables (niveau repo, pas environnement)

⚠️ **Leçon apprise** : les variables d'environnement ne sont **pas lisibles** dans un job qui ne déclare pas `environment:`. Comme la cible est résolue **avant** que l'environnement soit connu, elles sont au niveau du repo.

| Variable | Valeur |
|---|---|
| `URL_DEV` / `ROOT_DEV` | site dev |
| `URL_UAT` / `ROOT_UAT` | `/uat` |
| `URL_PRD` / `ROOT_PRD` | `tuc.ma` |
| `SSH_PORT` | `65002` |

Les **secrets** restent par environnement : `SSH_HOST`, `SSH_USERNAME`, `SSH_PRIVATE_KEY`.

## L'UAT est en SQLite

Le compte n'a **pas le droit de créer une base MySQL** (`Access denied … to database`).
L'UAT tourne donc sur le drop-in officiel **SQLite Database Integration** :

- dossier isolé `public_html/uat/`
- base dans `uat/wp-content/database/`
- `.htaccess` avec `RewriteBase /uat/`
- aucun impact sur dev ni sur les autres sites du compte

**Pour migrer l'UAT en MySQL plus tard** : hPanel → Bases de données → créer
`tuc_uat`, puis remplacer dans `uat/wp-config.php` les constantes `DB_ENGINE`/`DB_DIR`
par `DB_NAME`/`DB_USER`/`DB_PASSWORD`/`DB_HOST`.

---

# Les 3 plugins installés

| Plugin | Statut | Où |
|---|---|---|
| WooCommerce 11.2.1 | actif | dev + uat |
| LiteSpeed Cache 7.9.3 | actif | dev + uat |
| WP Mail SMTP 4.10.3 | actif | dev + uat |
| Two Factor 0.17.0 | actif | dev + uat |
| UpdraftPlus 1.26.14 | actif | dev + uat |
