# Git Flow — dev → uat → prd

> Réponse à la question : *« mon dev se trompe ou pas ? »* · 4 novembre 2026

---

## 1. Ta règle de branches est bonne. Elle est même standard.

C'est exactement le **Git Flow classique** :

```
feature/*  →  develop  →  pre-release-x.y.z  →  release-x.y.z
                 ↓              ↓                     ↓
                dev            uat                   prd
```

Et oui : **une mise à jour ne part jamais directement en prod.** Elle part en `develop`,
puis `uat` pour être testée, puis `prd`. C'est la bonne pratique, et c'est déjà ce que fait
`deploy.yml`. Rien à changer sur ce point.

---

## 2. MAIS il y a une erreur dans le récit, et je dois te la signaler

Tu dis : *« mon dev pour partikulier.ma a les 3 sur le même site »*.

**J'ai vérifié le serveur. Ce n'est pas ce qui est en place.**

| Ce que j'ai trouvé | Réalité |
|---|---|
| `partikulier.ma/public_html` | **un seul** WordPress (thème `wpresidence`) |
| Sous-dossiers `dev/`, `uat/`, `staging/` | **aucun** |
| Sous-domaines `dev.partikulier.ma`, `uat.partikulier.ma` | **n'existent pas** dans `~/domains` |
| Dépôts git sur le serveur | `.git_clones` **vide**, pas de `.git` dans `public_html` |

**Ce qui s'est réellement passé sur partikulier :**

```
srv/…/blanchedalmond-reindeer-376379.hostingersite.com/public_html/wp-content/themes/
  ├── partikulier-cdc-v18-final
  ├── partikulier-cdc-v18-final2
  ├── partikulier-cdc-v18-final3
  │   … 29 copies …
  └── partikulier-cdc-v18-final30
```

**41 thèmes**, dont **29 copies manuelles** de la même chose. C'est du **versionnage par
duplication**, pas du CI/CD. Il n'y a pas de dev/uat/prd — il y a un site et des copies
de secours dans un dossier.

Ce n'est pas un reproche. C'est juste que **si on reproduit ça pour TUC, on n'aura pas
d'environnements** — on aura 30 dossiers et une prod qu'on ne sait plus quelle version sert.

---

## 3. Ce qu'on va faire chez TUC : 3 environnements, 1 site

Tu as `tuc.ma`. Le compte est un **mutualisé avec 34 WordPress** mais **une seule IP**.
Donc **un seul compte peut héberger les 3 environnement** — c'est exactement ce qu'il faut,
il ne faut juste pas les mettre dans le **même dossier**.

### Architecture cible (dès que `tuc.ma` pointe sur l'hébergement)

```
~/domains/
  tuc.ma/public_html/               ← PRODUCTION      tuc.ma
  uat.tuc.ma/public_html/           ← ESSAI           uat.tuc.ma
  dev.tuc.ma/public_html/           ← DÉVELOPPEMENT   dev.tuc.ma
```

Trois sous-domaines, trois dossiers, **trois bases de données séparées**.
Un seul dépôt GitHub, trois branches, un seul `deploy.yml`.

> ⚠️ **Ne jamais mettre les 3 dans le même `public_html`.** Ils partageraient
> `wp-config.php`, la base et les uploads : une mauvaise requête en dev écraserait la prod.
> L'isolation, c'est le dossier + la base. Le "même site" veut dire *même hébergement*,
> pas *même dossier*.

### Aujourd'hui, en attendant que `tuc.ma` soit branché

`tuc.ma` **ne résout pas encore** (`DNS → rien`). L'hébergement n'est donc pas rattaché.
En attendant, les 3 environnements tournent déjà, dans **un** WordPress :

```
chocolate-gazelle-457691.hostingersite.com/        ← prd (en attente)  → dev aujourd'hui
chocolate-gazelle-457691.hostingersite.com/uat/    ← uat
```

Le **même dossier** sert de bac à sable. Le jour où `tuc.ma` pointe sur le compte,
on déplace : `/` devient la prod, `/uat` reste l'essai, et dev part sur `dev.tuc.ma`.

---

## 4. Les 3 plugins ne se mettent pas « sur le site »

Question posée : *« les 3 plugins là, c'est pour les 3 environnements ou pas ? »*

**Les plugins sont installés dans chaque environnement, séparément.** En WordPress, un
plugin n'existe pas au niveau de l'hébergement : il vit dans
`{dossier}/wp-content/plugins/`. Donc :

| Plugin | dev | uat | prd |
|---|---|---|---|
| WooCommerce | ✅ | ✅ | ⬜ *à l'activation de la prod* |
| LiteSpeed Cache | ✅ | ✅ | ⬜ |
| WP Mail SMTP | ✅ | ✅ | ⬜ |
| Two Factor | ✅ | ✅ | ⬜ |
| UpdraftPlus | ✅ | ✅ | ⬜ |

Aujourd'hui : installés et activés sur **dev** et **uat**.
La prod n'existe pas encore, donc rien à installer.

**Ce qui n'est PAS déployé par le CI** : les plugins tiers. Le `rsync` ne pousse que
le thème `tuc-theme` et le plugin `tuc-market-core`. Les plugins du répertoire WordPress
s'installent une fois, à la main ou par WP-CLI, dans chaque environnement.

> Exception : `tuc-market-core`, qu'**on** écrit, est dans le dépôt. Lui se déploie
> automatiquement sur les 3 environnements.

---

## 5. La protection de la prod (ajoutée à l'instant)

`release-…` → `prd` ne part plus tout seul. L'environnement `prd` exige maintenant :

- ✅ **une approbation manuelle** de `bernoussifatiha84-good`
- ✅ **branches autorisées : `release-*` uniquement**

Concrètement : tu pousses `release-0.3.0`, le pipeline s'arrête sur *« Waiting for approval »*,
tu cliques **Approve**, le rsync part. Si quelqu'un pousse `develop` en croyant viser la prod,
**c'est refusé**.

C'est la règle que tu demandais : *« on tape pas la prod direct »*. Elle est maintenant
techniquement impossible à contourner, pas juste une bonne intention.

---

## 6. Deux bugs d'outillage à corriger côté Hostinger

**① Les sous-domaines ne sont pas créables par API.**
`hPanel → Domaines → Sous-domaines` — il faut en créer 3 : `dev.tuc.ma`, `uat.tuc.ma`, `tuc.ma`.
Je ne peux pas le faire à ta place, il n'y a pas d'endpoint SSH pour ça.

**② Le compte MySQL ne peut pas être créé depuis SSH** (`Access denied`).
Il faut passer par `hPanel → Bases de données → Créer`. Trois bases : `tuc_dev`, `tuc_uat`, `tuc_prd`.
Chacune liée à son dossier.

---

## 7. Ordre des opérations, quand `tuc.ma` sera branché

1. hPanel → Domaines → pointer `tuc.ma` sur l'hébergement *(DNS, 24 h max)*
2. hPanel → Sous-domaines → créer `dev.tuc.ma`, `uat.tuc.ma`
3. hPanel → Bases de données → créer `tuc_dev`, `tuc_uat`, `tuc_prd`
4. Installer WordPress dans les 3 dossiers (moi)
5. Remplir les variables `URL_*` / `ROOT_*` et les secrets des 3 environnements (moi)
6. Lancer un `develop`, un `pre-release-0.1.0`, un `release-0.1.0` et vérifier les 3

Étapes 4 à 6 : **je les fais seul dès que 1–3 sont faits.**
