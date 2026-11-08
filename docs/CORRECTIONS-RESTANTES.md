# Ce qui reste à corriger — après le garde-fou de version

État constaté : HEAD `03b6a5d`, arbre propre, écart distant 0. CI
[42490846924](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42490846924)
verte. `develop` (`1fc4525`) est ancêtre de HEAD, qui porte 178 commits de plus.

## ① de son plan est fermé — ne pas le redemander

| Élément | État |
|---|---|
| Contrôle tag ↔ version dans le workflow | **fait**, `03b6a5d`, 3 barrières + auto-test |
| `0.8.9` / `0.8.10` documentés | **fait**, `docs/VERSIONS-ET-TAGS.md` |
| Supprimer le tag `0.8.9` | **refusé** — il a été déployé (run 42487451887), c'est une trace |

Il reste une **décision**, pas un correctif : le prochain numéro (`0.8.11` ou
`0.9.0`), avec en-tête **et** constante alignés avant de tagger.

## Deux défauts que j'ai trouvés en vérifiant ses points

Ce ne sont pas des remarques de plus sur son travail : ils sont dans le code que
**j'ai** contribué à câbler, et ils invalident une garantie sur laquelle on s'appuie.

### A. La sauvegarde d'avant-déploiement peut être vide et rester verte

`.github/workflows/deploy.yml`, step de sauvegarde :

```bash
mkdir -p "$backup"
if [ -d wp-content/themes/tuc-theme ];        then tar -czf "$backup/theme.tgz"  ...; fi
if [ -d wp-content/plugins/tuc-market-core ]; then tar -czf "$backup/plugin.tgz" ...; fi
echo "Sauvegarde créée dans $backup"
```

Si aucun des deux dossiers n'existe, les deux `if` sont sautés et le `echo`
annonce une sauvegarde. **Rejoué en isolation : code de sortie 0, message
« Sauvegarde créée », 0 fichier produit.**

Donc « sauvegarde vérifiée avant `rsync --delete` » n'est pas vérifiée par le
workflow. Correctif : exiger des `.tgz` non vides, échouer sinon, avant le rsync.

### B. Aucun dump de base — les commandes ne sont pas sauvegardées

`grep -c 'wp db export\|mysqldump' deploy.yml` → **0**.

La sauvegarde couvre le **code** (thème + plugin). Les commandes, les comptes et
les réglages vivent en **base**. Un `rsync --delete` ne les touche pas, mais un
retour arrière applicatif sans base cohérente ne restaure pas un service de
commande. Prérequis à confirmer avant de l'ajouter : `wp-cli` présent sur l'hôte et
espace disque suffisant.

## Ce qu'on demande, par qui peut le faire

### Lui seul — accès SSH

**② Élucider `dev`.** Son relevé (plugin 0.8.7 / thème 0.2.0 / WooCommerce actif /
aucune sauvegarde) reste un témoignage : je n'ai ni SSH ni lecture des variables
(`403`). Deux précisions pour rendre la vérification décisive :

- les sauvegardes ne sont **pas** à la racine en `tuc-*` mais dans
  **`wp-content/upgrade/tuc-predeploy-<horodatage UTC>/`**, avec `theme.tgz` et
  `plugin.tgz`. Son « aucune sauvegarde » dépend de l'endroit regardé — à refaire
  au bon chemin avant d'en conclure quoi que ce soit ;
- si le dossier existe mais est vide, c'est le défaut **A** ci-dessus, pas un
  déploiement manquant.

Attendu : chemin réel listé, contenu, et version lue par `wp plugin get`
**et** par `/sante` séparément.

**③ Parcours métier `/uat/caisse/` → commande → push.** Inchangé, personne d'autre
ne peut le faire. Rappel de piège : prendre une **commande à table avec opt-in
sonore**, onglet « nouvelles ». Voir D.

### Moi — code, sans accès serveur

**D. Régression d'alerte caisse — décision d'abord, correctif ensuite.**
`includes/vues/caisse.php`. Le commentaire de `develop` annonçait « onglet
nouvelles » mais le code ne filtrait pas le statut : il alertait sur **tous** les
onglets. À HEAD :

| Cas | develop (`1fc4525`) | HEAD |
|---|---|---|
| filtre onglet | aucun (le commentaire mentait) | `etat.statut === 'nouvelle'` |
| table + opt-in sonore | vibrate `[90,50,90]` + toast | vibrate `[120,60,120,60,220]` + bip + « Table N » |
| **livraison / emporter** | vibrate + toast | **toast seul, vibration supprimée** |
| **onglet « préparation » / « prête » / « toutes »** | alerte | **plus aucune alerte** |

Le `vibrate` de la ligne 444 n'est **pas** un chemin d'alerte : c'est le signal de
test envoyé par `basculerSon()` quand on active le son. Il ne couvre donc pas les
cas perdus. Le seul chemin d'alerte est ligne 618, conditionné à `mode === 'table'`.

Sa conclusion « renforcée, pas perdue » est vraie **pour les commandes à table
uniquement**. Question au propriétaire : **voulu ou régression ?** Si c'est voulu,
on l'écrit dans la doc et on ajoute un test qui le fixe. Sinon je rétablis une
alerte pour les autres modes et les autres onglets, avec test de non-régression.

**A + B.** Correctifs de sauvegarde décrits ci-dessus. **Attention : ils touchent
`deploy.yml`.** Je viens de rendre la main sur ce fichier ; il faut me la
repasser explicitement, sinon on retombe dans les deux writers.

**Résidus `edit_posts`.** `class-tuc-cpt.php` lignes **116** et **125**,
`auth_callback` de metaboxes. Hors chemin caisse, donc faible risque, mais le
durcissement n'est pas complet. Deux lignes.

### Toi — décisions, pas du code

1. **Alerte caisse** : voulu ou régression (bloque D).
2. **Numéro WhatsApp réel** : `get_option('tuc_wa_numero', '')` à trois endroits
   (`class-tuc-compte.php` 272 et 479, `class-tuc-onboarding-restaurant.php` 139).
   Sans valeur, les liens `wa.me` sont dégradés. Ce n'est pas un bug à corriger,
   c'est une valeur à fournir.
3. **WooCommerce en CI** : confirmé absent — la CI n'active que
   `tuc-market-core`, et `woocommerce` n'apparaît que dans `deploy.yml`
   (inventaire). `dev` l'a d'actif. **La CI ne teste donc pas l'environnement
   réel.** L'ajouter est un chantier, pas une ligne : à décider.
4. **④ Fusion vers `main` puis resynchronisation `develop`** : `main` est toujours
   `f5b9fa5`. Pas de merge sans ton accord. La resynchronisation de `develop`
   (178 commits de retard) se fait après, sinon `dev` redeviendra divergent.

## Ordre proposé

1. Toi : décision alerte caisse + numéro WhatsApp.
2. Moi : A, B, D, résidus — **si tu me rends la main sur `deploy.yml`**.
3. Lui : ② au bon chemin de sauvegarde, puis ③ parcours métier.
4. Toi : feu vert merge, puis resynchroniser `develop`, puis tag aligné.

Le garde-fou de version ne dispense d'aucun de ces points : il empêche de
**republier** un tag mensonger, il ne répare ni l'alerte caisse, ni la sauvegarde
vide, ni l'écart d'environnement.

---

## Mise à jour après le correctif A (`76a97fb`)

A est corrigé par la session serveur : chaque archive est vérifiée après création
(`test -s` + nombre d'entrées), avec avertissement explicite si aucune source
n'existe. `tests/deploy-contract.py` vérifie désormais ce garde-fou par son
**comportement** : le heredoc distant est extrait de `deploy.yml` et exécuté sur
des arborescences fabriquées. Confronté à l'ancien code (`83dba16`), ce test
produit **2 échecs** — il détecte donc la régression, il ne se contente pas de
chercher une chaîne.

### Trou résiduel du garde-fou A

Le seuil « au moins 2 entrées » laisse passer un dossier qui ne contient **qu'un
sous-dossier vide** : l'archive a 2 entrées (le dossier et son enfant), donc 0
fichier utile, et le step réussit. Vérifié en isolation. Ce n'est pas le cas
d'origine et c'est peu probable en production, mais le garde-fou compte des
entrées, pas des fichiers. À durcir si on veut la garantie « au moins un fichier
régulier ».

### Risque SQLite : correction du diagnostic

Le risque n'est **pas** le tri sur métas numériques. Constaté dans le plugin :

```
meta_value_num                : 0 occurrence
orderby                      : ID, date, title, display_name, menu_order
SQL brut ($wpdb->query/...)   : 0 occurrence
```

Aucun tri par note ni par promo n'existe dans ce code. Le construit réellement à
risque est **`'type' => 'NUMERIC'` dans les `meta_query`**, à quatre endroits :

| Fichier | Ligne | Rôle |
|---|---|---|
| `class-tuc-caisse.php` | 418 | **liste des commandes de la caisse** |
| `class-tuc-espace-pro.php` | 413, 473, 688 | filtrage par restaurant |

WordPress traduit `type => NUMERIC` en `CAST(meta_value AS SIGNED)`, et ce n'est
pas un tri cosmétique — c'est le **filtre d'isolation par restaurant**
(`tuc_resto`).

**Correction de ma propre affirmation.** J'avais écrit que `SIGNED` n'existait pas
en SQLite. **C'est faux**, mesuré sur SQLite 3.40.1 :

```
CAST('11' AS SIGNED)   -> 11    typeof=integer   aucune erreur
CAST('11.9' AS SIGNED) -> 11.9  typeof=real      (MySQL donnerait 12)
```

SQLite applique ses règles d'affinité à un nom de type arbitraire : `SIGNED` ne
contient aucun des marqueurs `INT`/`CHAR`/`REAL`, donc affinité NUMERIC, donc
conversion réussie. La session serveur avait raison sur ce point.

**Mais le `type` change bien le SQL.** `WP_Meta_Query` (cœur WordPress,
`class-wp-meta-query.php:776-782`) :

```php
if ( 'CHAR' === $meta_type ) {          // défaut quand 'type' est absent
    "$alias.meta_value $compare $where";
} else {                                 // 'NUMERIC' -> 'SIGNED'
    "CAST($alias.meta_value AS $meta_type) $compare $where";
}
```

Et les deux ne sélectionnent pas les mêmes lignes. Mesuré sur SQLite :

| Clause | Lignes retenues pour `= '11'` |
|---|---|
| avec `type => NUMERIC` → `CAST(… AS SIGNED)` | `11`, `011`, `11 ` |
| sans `type` → pas de CAST | `11` seulement |

Donc « `type => NUMERIC` n'est jamais appliqué, SQL identique avec et sans » est
inexact. La conclusion « pas de faille actuelle » reste juste, mais pour une autre
raison : **les valeurs stockées sont des entiers propres** (`update_post_meta` avec
un `int`), jamais `011` ni `11 `. C'est la propreté des données qui protège, pas
l'inertie du paramètre.

Conséquence à retenir : ne pas conclure « `type => NUMERIC` est inoffensif ». Il
élargit l'égalité. Un jour où un identifiant arrive comme chaîne depuis une
saisie ou un import, le filtre d'isolation par restaurant devient plus permissif
que prévu.

C'est là qu'un job CI SQLite aurait de la valeur, pas sur un tri de notes.

### Trou ouvert dans le correctif `021a01b` — archive corrompue acceptée

Le comptage de fichiers utilise `fichiers=$(tar -tzf "$archive" | grep -vc '/$' || true)`.
Le `|| true` neutralise l'échec du **pipeline entier**, donc celui de `tar`. Avec
`set -euo pipefail`, une archive tronquée n'arrête plus le step. Mesuré :

| Archive | `tar -tzf` seul | Logique du step |
|---|---|---|
| saine, 700 o | exit 0 | code 0 — « 30 fichier(s) » |
| **tronquée à 350 o** | **exit 2** | **code 0 — « 11 fichier(s) »** |

Une sauvegarde non restaurable est donc acceptée. C'est la classe de faux vert que
le correctif chasse, réintroduite par le `|| true` — celui-là même qu'il fallait
éviter. Correctif d'une ligne : laisser `tar` échouer (ou tester sa sortie
séparément) au lieu d'avaler le code de retour, puis vérifier le nombre de fichiers.

Le trou du sous-dossier vide est en revanche **bien fermé** par `021a01b` : vérifié,
code 1, et la méta-garde comportementale reste 4/4 sur ce code.
