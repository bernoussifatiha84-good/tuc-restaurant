# Versions, tags et garde-fou de cohérence

## Règle

Un tag de déploiement porte la version qu'il publie :

| Référence | Cible | Contrainte |
|---|---|---|
| branche `develop` | `dev` | en-tête plugin == constante `TUC_CORE_VERSION` |
| `pre-release-X.Y.Z[-suffixe]` | `uat` | idem **+** version plugin == `X.Y.Z` |
| `release-X.Y.Z[-suffixe]` | `prd` | idem **+** version plugin == `X.Y.Z` |

Le suffixe est permis : `pre-release-0.8.5-uat.1` déployait légitimement le plugin
`0.8.5`. Un tag hors convention (`v1.2.3`) n'est pas un tag de déploiement et n'est
pas soumis à la règle de version.

**Trois valeurs à garder alignées** dans `wordpress/tuc-market-core/tuc-market-core.php`
et `wordpress/tuc-theme/style.css` :

1. l'en-tête `* Version:` du plugin — lu par WordPress et `wp plugin get` ;
2. la constante `TUC_CORE_VERSION` — lue par `/sante` et par le contrôle de santé du déploiement ;
3. le nom du tag poussé.

Le thème a sa propre version (`Version:` dans `style.css`), vérifiée séparément par
le contrôle de santé. Elle n'est pas liée au nom du tag.

## Anomalies connues — documentées, pas effacées

Ces tags sont publiés et ont été déployés. **On ne les supprime pas et on ne les
renumérote pas** : ils sont la trace de ce qui a réellement tourné sur l'UAT.

| Tag | En-tête | Constante | Thème | Anomalie |
|---|---|---|---|---|
| `pre-release-0.8.8` | 0.8.8 | 0.8.8 | 0.1.0 | cohérent, mais **ne contient pas** le merge de `develop` |
| `pre-release-0.8.9` | **0.8.8** | **0.8.9** | 0.2.0 | **incohérent en interne** : deux versions publiées pour un même déploiement |
| `pre-release-0.8.10` | 0.8.9 | 0.8.9 | 0.2.0 | cohérent en interne, mais **le nom du tag ne correspond pas** au contenu (0.8.9) |

Conséquence : aucune version `0.8.10` n'existe dans le code, et la version `0.8.9`
n'a d'abord existé que sous une forme incohérente. L'état actuellement déployé sur
l'UAT est **plugin 0.8.9 / thème 0.2.0**, publié par le tag `pre-release-0.8.10`
(run [42488078757](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42488078757)).

Les tags antérieurs à `0.8.0` suivaient une autre convention (plugin `0.1.0` pour
des tags `0.2.x` à `0.7.x`). Ils ne sont pas re-validés : le garde-fou s'applique à
la référence en cours de déploiement, jamais rétroactivement.

## Pourquoi le health check ne suffisait pas

Le contrôle de santé compare la version renvoyée par `/sante` à la **constante**.
Il ne lit jamais l'en-tête. Un déploiement pouvait donc être vert en publiant un
plugin que WordPress identifie sous une autre version. C'est exactement ce qui
s'est produit pour `pre-release-0.8.9`, et le job a journalisé `plugin 0.8.9` pour
le tag `0.8.10`.

## Garde-fou

`tests/version-contract.py` vérifie l'en-tête, la constante, la version du thème et
la correspondance tag ↔ version. Il est appelé à trois endroits :

1. **CI de la PR** (`wordpress-docker-smoke.yml`) : l’incohérence est détectée avant
   qu'un tag existe.
2. **Job `qualite` de `deploy.yml`** : ce job est un `needs` du déploiement, donc
   il bloque en amont.
3. **Step « Lire les versions » du job `deploiement`** : dernier verrou, exécuté
   **avant toute écriture distante** — avant la sauvegarde et avant le `rsync`.

Chaque exécution rejoue aussi `--self-test`, qui rejoue le contenu exact du tag
fautif `pre-release-0.8.9` et celui du tag `0.8.10`. Un garde-fou qui n'a pas
prouvé qu'il rejette son propre cas d'origine n'est pas un garde-fou.

`tests/deploy-contract.py` contient en plus une méta-garde : il échoue si les steps
de version disparaissent de `deploy.yml`.

## Procédure de release

```bash
# 1. Aligner les trois valeurs AVANT de tagger
#    - wordpress/tuc-market-core/tuc-market-core.php : * Version: et TUC_CORE_VERSION
#    - wordpress/tuc-theme/style.css : Version: (si le thème change)
python3 tests/version-contract.py --self-test
python3 tests/version-contract.py --ref-name pre-release-0.8.11 --ref-type tag

# 2. Commiter, pousser, attendre la CI verte sur ce commit exact
# 3. Seulement alors créer le tag
git tag pre-release-0.8.11 && git push origin pre-release-0.8.11
```

Prochain tag cohérent possible : `pre-release-0.8.11` avec plugin `0.8.11`, ou
`pre-release-0.9.0` avec plugin `0.9.0`. Le choix de numérotation appartient au
propriétaire de la release.
