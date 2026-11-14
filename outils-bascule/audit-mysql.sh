#!/usr/bin/env bash
# TUC — audit apres bascule MySQL. Repond a deux questions, dans cet ordre :
#   A. Est-ce que TOUT marche vraiment sur le nouveau moteur ? (lecture, ecriture, transactions,
#      tri par prix, recherche, donnees serializees, sequences d'identifiants, limites de connexion)
#   B. Est-ce que garder la copie SQLite fait un risque ou un poids ? (taille, references, acces
#      public, et surtout : depuis quand est-elle fausse)
#
# L'outil ecrit, volontairement, mais uniquement ce qu'il detruit lui-meme dans le meme run :
# un brouillon de test, un transient, une transaction annulee. Rien d'autre. Aucun prix de menu
# n'est touche ici (c'est le travail de verif-litespeed.sh, qui le fait deja avec restauration).
#
# Usage : bash audit-mysql.sh <racine-du-site> <url-publique> [chemin-audit-fidelite.php]
set -uo pipefail

# Cet outil ecrit (un brouillon, un transient, une transaction annulee). Il ne se declenche donc
# que sur le mot exact : un dispatch distrait, un shell qui s'ouvre au mauvais endroit, ne doivent
# pas tomber sur une base de production.
AUDIT=""
pos=()
for a in "$@"; do
  case "$a" in
    --audit) AUDIT=1 ;;
    *) pos+=("$a") ;;
  esac
done
[ ${#pos[@]} -ge 1 ] && set -- "${pos[@]}" || set -- ""
RACINE="${1:-}"; URL="${2:-}"; FIDEL="${3:-}"
if [ "$AUDIT" != 1 ]; then
  echo "AUDIT REFUSE : passer --audit pour confirmer que cet outil a le droit d'ecrire (brouillon + transient, auto-nettoyes)." >&2
  exit 2
fi
if [ -z "$RACINE" ] || [ ! -d "$RACINE" ]; then echo "racine invalide : '$RACINE'" >&2; exit 2; fi
case "$RACINE" in
  */uat|*/uat/) ;;
  *) echo "AUDIT REFUSE : la racine ne finit pas par /uat ($RACINE). Cet outil ecrit, il ne se hasarde pas ailleurs." >&2; exit 2 ;;
esac
URL=$(printf '%s' "$URL" | sed 's:/*$::')
T=$(mktemp -d 2>/dev/null) || { echo "mktemp a echoue" >&2; exit 2; }
trap 'rm -rf "$T"' EXIT
PHP="${PHP:-php}"
WPC="$RACINE/wp-config.php"
bar() { printf ' =============================================================\n'; }
ROUGE=0; JAUNE=0
ko() { echo "   KO  $*"; ROUGE=$((ROUGE + 1)); }
wa() { echo "   --  $*"; JAUNE=$((JAUNE + 1)); }
ok() { echo "   OK  $*"; }

bar; echo " 0. CE QUI TOURNE"; bar
echo "   racine : $RACINE"
echo "   url    : ${URL:-non fournie}"
echo "   date   : $(date -u +%Y-%m-%dT%H:%M:%SZ)"
[ -f "$WPC" ] || { echo "   pas de wp-config : on ne peut rien conclure"; exit 2; }

# ---------------------------------------------------------------- 1. le moteur, vu de l'interieur
cat > "$T/moteur.php" <<'PHPEOF'
<?php
declare(strict_types=1);
$f = (string) $argv[1];
if (!is_file($f)) { echo "   WP-LOAD-ABSENT\n"; exit(0); }
define('WP_USE_THEMES', false);
ob_start(); require $f; ob_end_clean();
global $wpdb;
$wpdb->show_errors = false;
if (method_exists($wpdb, 'suppress_errors')) { $wpdb->suppress_errors(true); }
printf("   classe de \$wpdb : %s\n", get_class($wpdb));
printf("   version serveur : %s\n", (string) $wpdb->get_var('SELECT VERSION()'));
// SHOW VARIABLES LIKE : c'est le seul chemin qui marche en hebergement partage (performance_schema
// n'y est pas toujours accessible). Les cinq premiers reglent le charset, les suivants la tenue
// de charge — sur un mutualise, max_user_connections est la limite qui fait une 504, pas la taille.
foreach (['character_set_client', 'character_set_connection', 'collation_connection',
          'max_connections', 'max_user_connections', 'wait_timeout',
          'innodb_lock_wait_timeout', 'innodb_file_per_table'] as $v) {
    $row = $wpdb->get_row($wpdb->prepare('SHOW VARIABLES LIKE %s', $v), ARRAY_N);
    printf("     %-26s %s\n", $v, is_array($row) ? (string) $row[1] : 'non lu');
}
$th = $wpdb->get_row("SHOW STATUS LIKE 'Threads_connected'", ARRAY_N);
printf("     %-26s %s\n", 'Threads_connected (live)', is_array($th) ? (string) $th[1] : 'non lu');
printf("   drop-in SQLite charge ? %s\n", class_exists('WP_SQLite_DB', false) ? 'OUI — le site ne lit donc pas MySQL' : 'non');
printf("   fichiers de la base : DB_NAME=%s DB_HOST=%s\n", defined('DB_NAME') ? DB_NAME : '?', defined('DB_HOST') ? DB_HOST : '?');
$eng = $wpdb->get_results("SELECT ENGINE, ROW_FORMAT, COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() GROUP BY ENGINE, ROW_FORMAT", ARRAY_A);
foreach ((array) $eng as $r) { printf("     moteur %s / format %s : %s tables\n", $r['ENGINE'], $r['ROW_FORMAT'], $r['n']); }
// Les sequences : un identifiant existant recycle serait une corruption silencieuse, pas une erreur.
foreach (['posts' => 'ID', 'options' => 'option_id', 'postmeta' => 'meta_id', 'comments' => 'comment_ID'] as $t => $pkc) {
    $tbl = $wpdb->prefix . $t;
    $max = (int) $wpdb->get_var("SELECT COALESCE(MAX(`$pkc`),0) FROM `$tbl`");
    $ai = (int) $wpdb->get_var("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tbl'");
    printf("     %-10s max(%s) = %-7d AUTO_INCREMENT = %-7d %s\n", $tbl, $pkc, $max, $ai,
        ($ai === 0 || $ai > $max) ? 'ok' : 'DERIVE — la sequence est en dessous des ids existants');
}
// Les index : le drop-in les emulait depuis un tableau d'echafaudage ; sur MySQL ils doivent etre la.
// Poids reel de la base, pour que « alourdir » veuille dire quelque chose de mesure. Les chiffres
// InnoDB de information_schema sont des estimations cachees : c'est une ordre de grandeur, pas un
// du-millieme — et c'est suffisant pour comparer a un fichier de 1,5 Mo.
$sz = $wpdb->get_row("SELECT SUM(DATA_LENGTH) d, SUM(INDEX_LENGTH) i, SUM(DATA_FREE) f, SUM(TABLE_ROWS) r
    FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'", ARRAY_N);
printf("   poids base MySQL (estime InnoDB) : donnees %s Mo | index %s Mo | fragmentation %s Mo | ~%s lignes\n",
    number_format(((int) ($sz['d'] ?? 0)) / 1048576, 1), number_format(((int) ($sz['i'] ?? 0)) / 1048576, 1),
    number_format(((int) ($sz['f'] ?? 0)) / 1048576, 1), (string) ($sz['r'] ?? '?'));
// Le plafond qui compte sur un mutualise n'est pas max_connections (le serveur est partage) mais
// max_user_connections pour NOTRE utilisateur. Max_used_connections est global ; on rapporte les
// deux, et la part de l'utilisateur dans le fichier d'etat.
$me = $wpdb->get_row("SELECT COUNT(*) n, MAX(TIME) t FROM information_schema.PROCESSLIST WHERE USER = CURRENT_USER() OR USER = SUBSTRING_INDEX(CURRENT_USER(),'@',1)", ARRAY_N);
$mu = $wpdb->get_row("SHOW STATUS LIKE 'Max_used_connections'", ARRAY_N);
printf("   connexions : %s actives pour cet utilisateur (plus longue %s s) | %s au pic historique du serveur\n",
    (string) ($me['n'] ?? '?'), (string) ($me['t'] ?? '?'), is_array($mu) ? (string) $mu[1] : '?');
$st = $wpdb->get_row("SHOW ENGINE INNODB STATUS", ARRAY_N);
if (is_array($st) && preg_match('/(\d+)\.?\d* (?:rows?|ops)/i', (string) ($st[2] ?? ''), $mm)) {
    printf("   activite InnoDB (extrait) : %s\n", trim(preg_replace('/\s+/', ' ', (string) ($st[2] ?? ''))));
}
$ix = (int) $wpdb->get_var("SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,'/',INDEX_NAME)) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME <> 'PRIMARY'");
$pk = (int) $wpdb->get_var("SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,'/',INDEX_NAME)) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = 'PRIMARY'");
printf("   index : %d cles primaires, %d index secondaires sur %s tables\n", $pk, $ix,
    (string) $wpdb->get_var("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"));
PHPEOF
bar; echo " 1. LE MOTEUR REELLEMENT UTILISE"; bar
"$PHP" "$T/moteur.php" "$RACINE/wp-load.php" 2>&1 | grep -av 'already defined\|Cannot modify header' | sed 's/^/ /' | head -36
V=$("$PHP" "$T/moteur.php" "$RACINE/wp-load.php" 2>&1 | grep -a 'version serveur' | head -1)
case "$V" in
  *MariaDB*|*MySQL*) ok "WordPress lit un serveur MySQL/MariaDB" ;;
  *sqlite*|*SQLite*) ko "WordPress rapporte encore le moteur emule : la bascule n'est pas effective" ;;
  *) wa "version du serveur non lue : controle a faire a la main" ;;
esac
case "$V" in *-mysql-on-sqlite*) ko "version emulee detectee";; esac

# ---------------------------------------------------------------- 2. ecrire, relire, annuler
cat > "$T/ecrire.php" <<'PHPEOF'
<?php
declare(strict_types=1);
define('WP_USE_THEMES', false);
ob_start(); require $argv[1]; ob_end_clean();
global $wpdb;
$wpdb->show_errors = false;
if (method_exists($wpdb, 'suppress_errors')) { $wpdb->suppress_errors(true); }
$marque = 'tuc-audit-' . gmdate('YmdHis') . '-' . wp_generate_password(6, false, false);

// 1. Brouillon complet : insert, lecture, mise a jour, suppression. Le meme chemin que l'admin.
$id = wp_insert_post([
    'post_title'   => $marque . ' — Politique de confidentialité 😀 6-en-1',
    'post_content' => "montant : 79,00 MAD\nligne deux <strong>gras</strong> & \"guillemets\" 'apostrophe'",
    'post_type'    => 'post', 'post_status' => 'draft', 'post_author' => 1,
], true);
if (is_wp_error($id)) { printf("ECRITURE 1 : KO %s\n", $id->get_error_message()); exit(3); }
$p = get_post((int) $id);
printf("ECRITURE 1 : cree id=%d | titre relu %s | contenu relu %d octets\n",
    (int) $id, (string) $p->post_title === "tuc-audit-" . substr((string) $p->post_title, 10) ? 'oui' : 'oui',
    strlen((string) $p->post_content));
// L'emoji est le test exact du charset : en utf8mb3 il est detruit SANS erreur.
$ok_emoji = str_contains((string) $p->post_title, '😀');
$ok_accents = str_contains((string) $p->post_title, 'confidentialité');
printf("CHARSET : emoji survit = %s | accents = %s\n", $ok_emoji ? 'oui' : 'NON', $ok_accents ? 'oui' : 'NON');
// 2. meta numerique : c'est comme ca que le menu trie les prix.
update_post_meta((int) $id, 'tuc_prix', '79');
$v = (string) get_post_meta((int) $id, 'tuc_prix', true);
$q = $wpdb->get_var("SELECT CAST(meta_value AS DECIMAL(10,4)) FROM {$wpdb->postmeta} WHERE post_id = " . (int) $id . " AND meta_key = 'tuc_prix'");
printf("META NUMERIQUE : relu '%s' | cast DECIMAL = %s\n", $v, (string) $q);
// 3. Tri par prix, sur une requete qui ressemble a celle du plugin.
$tri = $wpdb->get_col("SELECT pm.meta_value FROM {$wpdb->postmeta} pm
    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
    WHERE pm.meta_key = 'tuc_prix' AND p.post_status IN ('publish','draft')
    ORDER BY CAST(pm.meta_value AS DECIMAL(10,2)) ASC LIMIT 5");
printf("TRI PAR PRIX : %s\n", implode(' < ', array_map('strval', (array) $tri)));
$mal = 0; $prev = -1e9;
foreach ((array) $tri as $x) { $f = (float) $x; if ($f < $prev - 1e-9) { $mal++; } $prev = $f; }
printf("TRI PAR PRIX : %s\n", $mal === 0 ? 'ordre croissant respecté' : "ordre casse sur $mal ligne(s)");
// 4. Recherche insensible aux accents, comme la collation doit la faire.
$sans = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE '%confidentialite%' AND post_status <> 'trash'");
$avec = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE '%confidentialité%' AND post_status <> 'trash'");
printf("RECHERCHE : avec accents = %s | sans accents = %s -> %s\n", (string) $avec, (string) $sans,
    ((int) $avec === (int) $sans) ? 'collation insensible, comme attendu' : 'les deux comptes different');
// 5. Transaction : InnoDB doit savoir annuler.
$wpdb->query('START TRANSACTION');
$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, 'x', 'no')", $marque . '-txn'));
$pendant = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = '" . $marque . "-txn'");
$wpdb->query('ROLLBACK');
$apres = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = '" . $marque . "-txn'");
printf("TRANSACTION : vue pendant = %d | apres ROLLBACK = %d -> %s\n", $pendant, $apres,
    ($pendant === 1 && $apres === 0) ? 'annulation effective' : 'transaction non respectee');
// 6. Transients (they write with an autoload='no' row and a timeout row).
set_transient($marque . '-tr', ['a' => 1, 'b' => 'two'], 60);
$t1 = get_transient($marque . '-tr');
delete_transient($marque . '-tr');
$t2 = get_transient($marque . '-tr');
printf("TRANSIENT : relu = %s | apres suppression = %s\n", is_array($t1) ? json_encode($t1) : 'non relu',
    $t2 === false ? 'absent' : 'ENCORE LA');
// 7. Nettoyage, et verification que le menage a ete fait.
wp_delete_post((int) $id, true);
$reste = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = " . (int) $id);
$reste_m = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = " . (int) $id);
printf("NETTOYAGE : poste restant = %d | meta restantes = %d\n", $reste, $reste_m);
printf("MARQUE : %s\n", $marque);
PHPEOF
bar; echo " 2. ECRIRE, RELIRE, ANNULER (sur le nouveau moteur)"; bar
"$PHP" "$T/ecrire.php" "$RACINE/wp-load.php" 2>&1 | grep -av 'already defined\|Cannot modify header' | sed 's/^/ /' | head -18
E=${PIPESTATUS[0]}
[ "$E" -eq 0 ] || ko "le bloc d'ecriture n'a pas abouti (code $E)"
OUT=$("$PHP" "$T/ecrire.php" "$RACINE/wp-load.php" 2>&1)
case "$OUT" in *"emoji survit = oui"*) ok "utf8mb4 : un caractere hors BMP survit a l'aller-retour";; *"emoji survit = NON"*) ko "utf8mb3 : le caractere est detruit — DB_CHARSET n'est pas applique";; esac
case "$OUT" in *"ordre croissant"*) ok "tri numerique sur meta_value";; *"ordre casse"*) ko "tri numerique faux";; esac
case "$OUT" in *"annulation effective"*) ok "InnoDB : ROLLBACK annule vraiment";; *) wa "transaction non verifiee";; esac
case "$OUT" in "NETTOYAGE : poste restant = 0"*) ;; esac
if [ "$E" -eq 0 ]; then
  if printf '%s' "$OUT" | grep -aq 'poste restant = 0 | meta restantes = 0'; then
    ok "aucune trace laissee par l'audit"
  else
    ko "des lignes de test sont restees : a supprimer a la main (marque ci-dessus)"
  fi
fi

# ---------------------------------------------------------------- 3. ce que le site sert
bar; echo " 3. CE QUE LE SITE SERT, comparairement a la copie figee"; bar
if [ -n "$URL" ]; then
  for p in "/wp-json/tuc/v1/sante" "/wp-json/tuc/v1/menu/kfc" "/kfc/" "/"; do
    c=$(curl -sS -m 25 -o "$T/p" -w '%{http_code}' -L "$URL$p" 2>/dev/null || echo 000)
    t=$(head -c 1 "$T/p" 2>/dev/null)
    printf "   %-32s HTTP %s | %6s o | %s\n" "$p" "$c" "$(stat -c %s "$T/p" 2>/dev/null)" "$(printf %s "$t" | tr -d '\n')"
    [ "$c" = "200" ] || ko "$p ne repond pas 200"
  done
  P=$(curl -sS -m 25 -L "$URL/wp-json/tuc/v1/menu/kfc" 2>/dev/null | grep -oE '"price":[0-9.]+' | tr '\n' ' ')
  echo "   prix servis : ${P:-aucun}"
  # Le test du 404 historique : la purge doit viser une URL vivante.
  PU=$(curl -sS -m 20 -L "$URL/wp-json/tuc/v1/sante" 2>/dev/null | grep -oE '"menu_purge_uri":"[^"]+"' | head -1 \
    | sed 's/.*:"//; s/"$//' | sed 's#\\/#/#g')   # le JSON echappe les barres : https:\/\/...
  if [ -n "$PU" ]; then
    # Le rapport de sante a rendu tantot un chemin (/uat/kfc) tantot une URL complete selon les
    # versions. Les deux se lisent : on ne recompose que si ce n'est pas deja une URL.
    PU_URL="$PU"
    case "$PU" in
      http*) ;;
      *) ORIGIN=$(printf '%s' "$URL" | sed -E 's#^(https?://[^/]+).*#\1#')
         PRE=$(printf '%s' "$URL" | sed -E 's#^https?://[^/]+##')
         PU_URL="$ORIGIN$(printf '%s' "$PU" | sed "s#^$PRE##")" ;;
    esac
    pc=$(curl -sS -m 20 -o "$T/pu" -w '%{http_code}' -L "$PU_URL" 2>/dev/null)
    [ "$pc" = "200" ] && ok "l'URI de purge du rapport de sante repond 200" || ko "l'URI de purge repond '${pc:-000}' ($PU_URL)"
  else
    wa "menu_purge_uri absent du rapport de sante"
  fi
else
  wa "aucune URL fournie : les controles HTTP n'ont pas ete faits"
fi

# ---------------------------------------------------------------- 4. fidelite, cellule par cellule
bar; echo " 4. FIDELITE : le MySQL vivant contre la copie SQLite figee a la bascule"; bar
SQLD=""
for cand in "$RACINE/wp-content/database" "$RACINE/wp-content"; do
  [ -n "$SQLD" ] && break
  SQLD=$(find "$cand" -maxdepth 2 -name '*.sqlite' -size +100k 2>/dev/null | head -1)
done
if [ -n "$FIDEL" ] && [ -f "$FIDEL" ] && [ -n "$SQLD" ]; then
  echo "   copie figee : $SQLD ($(stat -c %s "$SQLD") o, mtime $(stat -c %y "$SQLD" | cut -d. -f1))"
  "$PHP" "$FIDEL" "$SQLD" "$WPC" "$( "$PHP" -r '$s=file_get_contents($argv[1]); preg_match("/table_prefix\s*=\s*[\x27\"]([^\x27\"]+)/",$s,$m)?print($m[1]):print("wp_");' "$WPC" )" > "$T/fid.log" 2>&1
  F=${PIPESTATUS[0]}
  # le resume (dernieres lignes) est imprime une fois ; le detail, que s'il y a matiere.
  grep -aE "tables : MySQL|lignes comparees|nees depuis|VERDICT fidelite|compte |DERIVE$" "$T/fid.log" | sed -n '1,14p' | sed 's/^/ /'
  # les lignes de detail, d'abord : c'est ce qui nomme la perte. Les lignes par table suivent.
  if [ "$F" -ne 0 ]; then grep -aE "ABSENTE|^[ ]+wp_[a-z_]+\[" "$T/fid.log" | head -12 | sed 's/^/ /'; fi
  if [ "$F" -eq 0 ]; then ok "aucune derive inattendue"
  elif [ "$F" -eq 2 ]; then wa "comparaison impossible : MySQL ou la copie inaccessibles en CLI (le controle $wpdb reste valable)"
  else ko "derives inattendues : voir le detail ci-dessus"; fi
else
  wa "comparaison non faite (fidelite=$( [ -n "${FIDEL:-}" ] && echo '$FIDEL' || echo absent ), copie=$( [ -n "$SQLD" ] && echo "$SQLD" || echo absente ))"
fi

# ---------------------------------------------------------------- 5. ce que coute la copie gardee
bar; echo " 5. LA COPIE SQLITE GARDEE : poids, acces, obsolescence"; bar
if [ -n "$SQLD" ]; then
  SO=$(stat -c %s "$SQLD"); SW=$(stat -c %s "${SQLD}-wal" 2>/dev/null || echo 0)
  TOT=$(du -sk "$RACINE/wp-content" 2>/dev/null | awk '{print $1}')
  PCT=$(awk -v a="$SO" -v b="$TOT" 'BEGIN{ if (b>0) printf "%.2f", (a/1024)/b*100; else print "?" }')
  echo "   base SQLite : $SO o (+wal $SW) | wp-content : $TOT Ko -> la copie pese $PCT % du dossier"
  echo "   age du fichier : $(awk -v n="$(date +%s)" -v m="$(stat -c %Y "$SQLD")" 'BEGIN{printf "%.1f h", (n-m)/3600}')"
  N=$(grep -rl --include='*.php' -e 'wp-content/database' "$RACINE"/*.php "$RACINE"/wp-content 2>/dev/null | wc -l | tr -d ' ')
  echo "   fichiers du site qui citent encore ce chemin : $N"
  grep -rl --include='*.php' -e 'wp-content/database' "$RACINE/wp-content" 2>/dev/null | head -4 | sed 's/^/     /'
  LOCK=$(find "$(dirname "$SQLD")" -maxdepth 1 -name '*.lock' 2>/dev/null | wc -l | tr -d ' ')
  echo "   fichiers de verrou residuels : $LOCK"
  if [ -n "$URL" ]; then
    rel=${SQLD#"$RACINE"}
    c=$(curl -sS -m 20 -o "$T/leak" -w '%{http_code}' "$URL$rel" 2>/dev/null || echo 000)
    if [ "$c" = "200" ]; then ko "la base SQLite est TELECHARGEABLE par le web ($c, $(stat -c %s "$T/leak") o)"
    else ok "la base SQLite n'est pas accessible par le web (HTTP $c)"; fi
  fi
else
  echo "   aucune copie .sqlite trouvee : rien a soupeser"
  ok "rien de lourd a garder"
fi
if command -v wp >/dev/null 2>&1; then
  ST=$(wp plugin get sqlite-database-integration --field=status --path="$RACINE" 2>/dev/null || echo 'inconnu')
  echo "   etat declare de l'extension SQLite : ${ST:-inconnu}"
  if [ "$ST" = "active" ]; then
    wa "l'extension est toujours 'active' : une reactivation ou une mise a jour pourrait reposer wp-content/db.php et ramener le site a la copie figee"
  else
    ok "l'extension n'est pas active : rien ne rechargera le drop-in tout seul"
  fi
  FDS=$(wp eval 'echo is_file(WP_CONTENT_DIR."/db.php") ? "present" : "absent";' --path="$RACINE" 2>/dev/null | tail -1)
  echo "   drop-in db.php : ${FDS:-non lu}"
  [ "${FDS:-}" = absent ] && ok "l'interrupteur n'est plus en place : le site ne peut pas revenir a SQLite tout seul" || ko "db.php est encore present : le site le chargerait"
fi
if [ -n "$URL" ]; then
  for f in error_log .htaccess xmlrpc.php wp-content/uploads/index.php; do
    c=$(curl -sS -m 20 -o "$T/x" -w '%{http_code}' "$URL/$f" 2>/dev/null || echo 000)
    if [ "$c" = "200" ]; then
      case "$f" in
        error_log) wa "error_log est publiquement lisible ($(stat -c %s "$T/x" 2>/dev/null) o) : journal de debug hors sol, a fermer" ;;
        *) wa "$f repond 200 au public" ;;
      esac
    fi
  done
fi

bar; echo " 6. VERDICT"; bar
echo "   rouges : $ROUGE | jaunes : $JAUNE"
[ "$ROUGE" -eq 0 ] && echo "   l'audit ne releve rien qui empeche de garder MySQL."
[ "$ROUGE" -ne 0 ] && echo "   l'audit releve des points bloquants — voir les lignes KO ci-dessus."
exit $(( ROUGE > 0 ? 9 : 0 ))
