# Git Flow TUC — état réel

> 4 novembre 2026. Ce document décrit **ce qui existe**, pas ce que je voudrais qui existe.

---

## 1. Trois noms d'environnement GitHub. Ce ne sont pas trois sites.

| Environnement GitHub | Adresse | Branché ? | Ce qui l'atteint |
|---|---|---|---|
| `dev` | `chocolate-gazelle-457691.hostingersite.com` | ✅ | branche `develop` |
| `uat` | `chocolate-gazelle-457691.hostingersite.com/uat` | ✅ | tag `pre-release-*` |
| `prd` | *(aucune)* | ⛔ **non branché** | tag `release-*` |

**Le tag ne crée pas un WordPress.** Il choisit **quel nom d'environnement** lance le flux.
Aujourd'hui `develop` et `pre-release-…` déposent le code **sur le même hébergement** —
l'un à la racine, l'autre dans `/uat`. C'est la configuration actuelle, assumée.

`prd` s'activera quand **`tuc.ma`** sera rattaché à l'hébergement.

---

## 2. Les trois déclencheurs

| Déclencheur | Nature | Environnement | Commande |
|---|---|---|---|
| `develop` | branche | `dev` | `git push origin develop` |
| `pre-release-x.y.z` | **tag** | `uat` | `git tag -a pre-release-0.3.0 -m "…" && git push origin pre-release-0.3.0` |
| `release-x.y.z` | **tag** | `prd` | `git tag -a release-0.3.0 -m "…" && git push origin release-0.3.0` |

```yaml
on:
  push:
    branches: [develop]
    tags: ['pre-release-*', 'release-*']
```

⚠️ **Pousser une branche nommée `pre-release-…` ne déploie rien.** Il faut créer le tag
puis le pousser. Les branches homonymes ont été supprimées du dépôt.

---

## 3. Le flux, tel qu'il tourne

```
1. Vérifications      php -l · CSS · JS · index.php + Version
2. Quelle cible ?     develop→dev | tag pre-release-*→uat | tag release-*→prd
3. rsync              thème (--delete) + plugin tuc-market-core (--delete)
                      puis WP-CLI : activate plugin, activate thème,
                      permaliens, cache flush, rewrite flush, litespeed purge
4. Fumigène           GET /wp-json/tuc/v1/sante  → doit répondre {"ok":true}
```

**Rien n'est installé dans le runner.** GitHub pousse des fichiers par SSH, puis lance WP-CLI.

---

## 4. `prd` : ce qui se passe si on tague `release-*` aujourd'hui

Deux garde-fous, dans cet ordre :

**① L'environnement `prd` exige une approbation**
`typologie : required_reviewers` → le run s'arrête sur **« Waiting for approval »**.
Tu peux approuver, ou annuler.

**② Si on approuve, le contrôle de branchement intervient**
```
::error::La cible 'prd' n'est pas encore branchée.
  Aujourd'hui : dev et uat déposent le code sur le même hébergement.
  'prd' s'activera quand tuc.ma sera rattaché.
```

Rien ne part vers une adresse qui n'existe pas.

> ⚠️ Détail trouvé en testant : la protection de `prd` filtrait sur `type: branch`.
> Un **tag** `release-*` était donc refusé par GitHub lui-même
> (`Tag "release-0.2.1" is not allowed to deploy to prd`). La règle est maintenant
> **`type: tag`** — `release-*` accepté en tant qu'étiquette.

---

## 5. Quand `tuc.ma` sera prêt

Trois opérations, et `prd` devient fonctionnel **sans toucher au code** :

```bash
# 1. rattacher tuc.ma dans hPanel + installer WordPress dessus
# 2. régler les deux variables
gh variable set URL_PRD  -R bernoussifatiha84-good/tuc-restaurant --body "https://tuc.ma"
gh variable set ROOT_PRD -R bernoussifatiha84-good/tuc-restaurant \
   --body "domains/tuc.ma/public_html"
# 3. poser les secrets SSH dans l'environnement prd
gh secret set SSH_HOST     -R … --env prd --body "145.223.38.175"
gh secret set SSH_USERNAME -R … --env prd --body "u987613820"
gh secret set SSH_PRIVATE_KEY -R … --env prd < tuc_deploy
```

Puis `git tag -a release-1.0.0 && git push origin release-1.0.0` →
approbation → déploiement réel sur `tuc.ma`.

---

## 6. Ce qui n'est pas encore au niveau pep.ma

| | pep.ma | TUC aujourd'hui |
|---|---|---|
| 3 noms d'environnement | ✅ | ✅ |
| Déclencheur par étiquette | ✅ | ✅ |
| SSH + rsync + WP-CLI | ✅ | ✅ |
| Plugin métier + santé | ✅ `partikulier-core` | ✅ `tuc-market-core` |
| **3 sites séparés** | ✅ | ⛔ dev et uat partagent un hébergement |
| **prod branchée** | ✅ | ⛔ attend `tuc.ma` |

Les quatre premières lignes sont faites. Les deux dernières dépendent de toi — et la
seconde (`tuc.ma`) est celle qui compte : **elle débloque la prod**.

Quand `tuc.ma` sera là : `tuc.ma` = prd, et on décidera alors si `uat` devient
`uat.tuc.ma` (site entier) ou reste l'essai partagé actuel.
