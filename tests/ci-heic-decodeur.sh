#!/usr/bin/env bash
# HEIC — conversion RÉELLE exigée dans un conteneur qui a un VRAI décodeur.
# (revue n°18, point 4 : « un job avec décodeur HEIC réellement présent où la
# conversion est obligatoire ; décodeur manquant ou conversion en échec = rouge »)
#
# Why this file lives in `tests/` and not inside the workflow: the CI step then
# contains no nested quoting at all, and the whole logic below can be rehearsed
# outside CI (it was, in the disposable bench). `tests/` is mounted read-only at
# /opt/tuc-tests in the `wpcli` container (see compose.yaml), so the workflow
# only has to run this script there.
#
# Attendu en conteneur (variables fournies par l'image et le montage) :
#   TUC_WP_ROOT     racine WordPress           (défaut : /var/www/html)
#   TUC_TESTS_DIR   dossier des tests monté    (défaut : /opt/tuc-tests)
#   TUC_APT_INSTALL 1 = installer le décodeur (image nue), 0 = déjà présent
set -uo pipefail

WP_ROOT="${TUC_WP_ROOT:-/var/www/html}"
TESTS_DIR="${TUC_TESTS_DIR:-/opt/tuc-tests}"
FIXTURE="${TUC_HEIC_FIXTURE:-$TESTS_DIR/fixtures/heic-mire-1600x1200.heic}"
APT_INSTALL="${TUC_APT_INSTALL:-0}"
PHP_BIN="${TUC_PHP_BIN:-php}"
TEST="$TESTS_DIR/heic-decodeur-requis.php"
KO=0

# Le seul critère qui vaille : CE binaire PHP, dans CE conteneur, sait-il décoder
# du HEIC ? On ne se fie ni au code de retour d'apt-get, ni à la présence d'un
# paquet — un paquet peut être installé pour un autre interpréteur.
a_decodeur() {
    "$PHP_BIN" -r 'if (!class_exists("Imagick")) { exit(1); } $i = new Imagick(); exit(count($i->queryFormats("HEIC")) > 0 ? 0 : 1);' \
        >/dev/null 2>&1
}

echec() { echo "ECHEC — $1"; KO=$((KO + 1)); }
ok()    { echo "OK    — $1"; }

echo "=== Contexte ==="
echo "  racine WordPress : $WP_ROOT"
echo "  dossier tests    : $TESTS_DIR"
echo "  fixture          : $FIXTURE"
echo "  install apt      : $APT_INSTALL"
[ -f "$TEST" ]    || { echec "script de test introuvable : $TEST"; exit 1; }
[ -f "$FIXTURE" ] || { echec "fixture HEIC introuvable : $FIXTURE"; exit 1; }
[ -f "$WP_ROOT/wp-load.php" ] || { echec "pas une installation WordPress : $WP_ROOT"; exit 1; }

if [ "$APT_INSTALL" = "1" ]; then
    echo "=== Installation du décodeur (image nue) ==="
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq || echec "apt-get update a échoué"
    apt-get install -y -qq --no-install-recommends libheif1 libde265-0 libaom3 \
        || echo "  note : paquets libheif déjà là ou indisponibles — on vérifie malgré tout"
    # `php8.2-imagick` est le nom servi par le dépôt Sury de l'image officielle ;
    # `php-imagick` est le méta-paquet Debian. Les deux peuvent échouer : ce
    # n'est PAS un motif d'indulgence, la phase 1 ci-dessous sera rouge.
    apt-get install -y -qq --no-install-recommends php8.2-imagick \
        || apt-get install -y -qq --no-install-recommends php-imagick \
        || echo "  note : paquet imagick non installé — la phase 1 doit être rouge"
    php -m | grep -qi imagick && ok "extension imagick chargée" || echo "  note : imagick absent de php -m"

    if a_decodeur; then
        ok "décodeur HEIC disponible pour $PHP_BIN (voie paquet distribué)"
    else
        # Mesuré et attendu : les images Docker officielles « wordpress:cli-php8.2 »
        # compilent PHP depuis les sources ; le paquet distribué php(-|8.2-)imagick
        # dépose son .so dans l'extension_dir du PHP du DISTRIBUTEUR, que ce binaire
        # ne charge pas. D' où le repli PECL, qui installe dans CE PHP-là.
        echo "  note : paquet installé mais $PHP_BIN ne déclare pas HEIC -> repli PECL (image à PHP compilé)"
        export DEBIAN_FRONTEND=noninteractive
        apt-get install -y -qq --no-install-recommends libmagickwand-dev libheif-dev pkg-config             >/dev/null 2>&1 || echo "  note : dépendances de build indisponibles"
        if command -v pecl >/dev/null 2>&1; then
            if pecl install imagick >/tmp/pecl-imagick.log 2>&1; then
                ok "pecl install imagick terminé"
            else
                echo "  note : pecl install imagick a échoué — 5 dernières lignes : $(tr '\n' ' ' < /tmp/pecl-imagick.log | tail -c 300)"
            fi
            # docker-php-ext-enable existe dans les images PHP officielles ; ailleurs,
            # on écrit l'option dans le ini scanné par ce binaire.
            if command -v docker-php-ext-enable >/dev/null 2>&1; then
                docker-php-ext-enable imagick >/dev/null 2>&1 || echo "  note : docker-php-ext-enable a échoué"
            else
                INI_DIR="$("$PHP_BIN" --ini | sed -n 's/^Scan for additional .ini files in: //p')"
                if [ -n "${INI_DIR:-}" ] && [ -d "$INI_DIR" ]; then
                    echo "extension=imagick" > "$INI_DIR/99-imagick.ini" && ok "extension activée dans $INI_DIR/99-imagick.ini"
                fi
            fi
        else
            echo "  note : pas de binaire pecl dans cette image — rien sur quoi replier"
        fi
        if a_decodeur; then
            ok "décodeur HEIC disponible pour $PHP_BIN (voie PECL)"
        else
            echo "  note : toujours pas de décodeur — la phase 1/3 sera ROUGE, et c'est le comportement exigé"
        fi
    fi
fi

echo "=== 1/3  conversion réelle exigée (décodeur absent = rouge, échec de conversion = rouge) ==="
if "$PHP_BIN" "$TEST" "$WP_ROOT" "$FIXTURE"; then
    ok "un vrai HEIC est converti en JPEG par le module"
else
    echec "le job à décodeur requis est rouge : décodeur absent ou conversion en échec (c'est le comportement voulu, mais le run doit être corrigé)"
fi

echo "=== 2/3  contrôle négatif : un HEIC tronqué ne doit PAS passer pour converti ==="
TRONQUE="$(mktemp /tmp/heic-tronque-XXXXXX.heic)"
head -c 1200 "$FIXTURE" > "$TRONQUE"
if "$PHP_BIN" "$TEST" "$WP_ROOT" "$TRONQUE"; then
    echec "un HEIC tronqué a été accepté comme converti : le test ne distingue plus succès et déchets"
else
    ok "HEIC tronqué refusé (rouge obtenu, et c'est voulu)"
fi
rm -f "$TRONQUE"

echo "=== 3/3  contrôle négatif : un fichier illisible portant le nom .heic ==="
FAUX="$(mktemp /tmp/pas-un-heic-XXXXXX.heic)"
printf 'ceci ne contient aucune image\n' > "$FAUX"
if "$PHP_BIN" "$TEST" "$WP_ROOT" "$FAUX"; then
    echec "un fichier sans image a été accepté comme converti"
else
    ok "fichier illisible refusé (rouge obtenu, et c'est voulu)"
fi
rm -f "$FAUX"

echo
if [ "$KO" -eq 0 ]; then
    echo "bilan : TOUT VERT — conversion réelle vérifiée, et les deux contrôles négatifs rougissent bien"
    exit 0
fi
echo "bilan : $KO ECHEC(S)"
exit 1
