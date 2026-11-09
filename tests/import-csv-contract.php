<?php
/**
 * Contrat de l'import CSV de menu — preuves exécutables sous WordPress réel.
 *
 * Chaque cas porte sa MUTATION dans son commentaire : la ligne qu'on casse pour
 * vérifier que le test rougit. Un test qu'on n'a jamais vu rouge ne prouve rien.
 *
 * Exécution : appelé par `require_once` depuis tests/wp-docker-regression.php
 * (étape « Exécuter les régressions WordPress/TUC », déjà câblée). Aucune ligne
 * de workflow n'est nécessaire : tout `tests/` est monté dans le conteneur.
 *
 * Le fichier expose `tuc_importer_contrat_csv()` et n'exécute rien au chargement :
 * c'est l'appelant qui décide, et il vérifie le nombre de cas annoncé.
 *
 * ── MUTATIONS (jouées une par une dans le journal) ─────────────────────────
 *  M1  lire_csv()   : ne pas retirer le BOM          → cas 1 rouge
 *  M2  lire_prix()  : (float) direct                 → cas 2 rouge
 *  M3  analyser()   : supprimer l'avertissement prix → cas 3 rouge
 *  M4  indexer()    : clé = '' (pas de tuc_import_ref) → cas 4 rouge
 *  M5  analyser()   : ignorer les options orphelines → cas 5 rouge
 *  M6  analyser_groupes : retirer le refus obligatoire/min=0 → cas 6 rouge
 *  M7  compenser()  : ne rien faire                  → cas 10 rouge
 *  M8  statut       : écrire 'publish' au lieu de 'draft' → cas 13 rouge
 *  ─────────────────────────────────────────────────────────────────────────
 */

/**
 * Exécute le contrat de l'import CSV.
 *
 * Réutilise le helper d'assertion du harnais parent s'il est fourni : le motif
 * « ÉCHEC : » est celui que le workflow extrait pour son diagnostic, on en
 * profite au lieu d'inventer un second vocabulaire.
 *
 * @param callable(bool, string): void|null $assertExterne
 * @return int nombre de cas exécutés
 */
function tuc_importer_contrat_csv(?callable $assertExterne = null): int
{
    global $casExecutees;
    $casExecutees = 0;

    $assert = static function (bool $condition, string $message) use ($assertExterne): void {
        global $casExecutees;
        $casExecutees++;
        if ($assertExterne !== null) {
            $assertExterne($condition, $message);
            return;
        }
        if (!$condition) {
            fwrite(STDERR, 'ASSERTION_FAILURE: ' . $message . "\n");
            throw new RuntimeException('ÉCHEC : ' . $message);
        }
        WP_CLI::log('OK : ' . $message);
    };

$erreurAttendue = static function ($resultat, string $code, string $message) use ($assert): void {
    $assert(is_wp_error($resultat), $message . ' (résultat attendu : erreur)');
    $assert($resultat->get_error_code() === $code, $message . ' — code ' . $resultat->get_error_code());
};

$crees = ['restos' => [], 'items' => [], 'users' => [], 'transients' => []];

// Inventaire AVANT. Le contrôle d'après ne dit rien s'il n'a pas de point de
// comparaison : « le même nombre qu'avant » exige de compter avant.
$inventaire = static function (): array {
    $ids = static function (string $type): array {
        return array_map('intval', get_posts(['post_type' => $type, 'post_status' => 'any',
            'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true]));
    };
    $users = get_users(['fields' => 'ID']);
    return [
        'resto' => $ids(TUC_CPT::RESTO),
        'item'  => $ids(TUC_CPT::ITEM),
        'users' => array_map('intval', $users),
    ];
};
// Un diff, pas seulement un compte : « il en manque un » ne dit pas lequel, et
// c'est le nom qui permet de trancher entre fuite du contrat et compteur faux.
$diffInventaire = static function (array $avant, array $apres): string {
    $ecarts = [];
    foreach (['resto', 'item', 'users'] as $cle) {
        if (count($avant[$cle]) === count($apres[$cle])) {
            continue;
        }
        $nouveaux = array_values(array_diff($apres[$cle], $avant[$cle]));
        $disparus = array_values(array_diff($avant[$cle], $apres[$cle]));
        $libelle = static function (string $cle, array $ids): array {
            return array_map(static function (int $id) use ($cle): string {
                if ($cle === 'users') {
                    $u = get_userdata($id);
                    return '#' . $id . ':' . ($u ? $u->user_login . ' [' . implode(',', $u->roles) . ']' : '?');
                }
                return '#' . $id . ':' . get_post_type($id) . '/' . get_post_status($id);
            }, $ids);
        };
        $ecarts[] = sprintf('%s %d→%d (+[%s] -[%s])', $cle, count($avant[$cle]), count($apres[$cle]),
            implode(' ', $libelle($cle, $nouveaux)), implode(' ', $libelle($cle, $disparus)));
    }
    return $ecarts === [] ? 'aucun écart' : implode(' ; ', $ecarts);
};
$inventaireAvant = $inventaire();

// ---- fixtures ----
$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if ($admin === []) {
    WP_CLI::error('Aucun administrateur : la suite a besoin d’un compte à capacités.');
}
wp_set_current_user((int) $admin[0]);

$resto_id = wp_insert_post([
    'post_type'   => TUC_CPT::RESTO,
    'post_title'  => 'Resto contrat import',
    'post_name'   => 'resto-contrat-import',
    'post_status' => 'publish',
]);
$crees['restos'][] = $resto_id;
$autre_resto = wp_insert_post([
    'post_type'   => TUC_CPT::RESTO,
    'post_title'  => 'Resto voisin',
    'post_name'   => 'resto-voisin-import',
    'post_status' => 'publish',
]);
$crees['restos'][] = $autre_resto;

$platsCsv = static fn(array $lignes): array => ['lignes' => $lignes];

// `wp_die()` n'est PAS une exception : sous WP-CLI il termine le processus. On
// intercepte le gestionnaire de mort pour le transformer en exception, sinon la
// suite meurt au premier refus (run 42628947718, cas 12a).
// Déclaré ICI, avant le `try` principal, pour que le `finally` puisse toujours
// retirer les filtres — même si une assertion tombe au milieu. Un `remove_filter`
// posé au fil de l'eau ne s'exécute jamais quand le test échoue : c'est
// précisément le moment où la suite suivante en a le plus besoin.
$intercepterMort = null;
$poseInterception = static function () use (&$intercepterMort): void {
    $intercepterMort = static function (): callable {
        return static function ($message = ''): void {
            throw new RuntimeException('WP_DIE:' . (is_scalar($message) ? (string) $message : ''));
        };
    };
    add_filter('wp_die_handler', $intercepterMort);
    add_filter('wp_die_ajax_handler', $intercepterMort);
    add_filter('wp_die_json_handler', $intercepterMort);
};
$retirerInterception = static function () use (&$intercepterMort): void {
    if ($intercepterMort === null) {
        return;
    }
    remove_filter('wp_die_handler', $intercepterMort);
    remove_filter('wp_die_ajax_handler', $intercepterMort);
    remove_filter('wp_die_json_handler', $intercepterMort);
    $intercepterMort = null;
};

try {
    // =====================================================================
    // 1. BOM
    // MUTATION M1 : retirer le `if (str_starts_with($contenu, "\xEF\xBB\xBF"))`
    //               → l'en-tête devient "\xEF\xBB\xBFreference" → colonne absente.
    // =====================================================================
    $avecBom = "\xEF\xBB\xBFreference;nom;description;prix;categorie;promo_pct;disponible\r\n"
             . "tacos-poulet;Tacos poulet;Sauce fromagère;45,00;Tacos;0;oui\r\n";
    $lu = TUC_Import_CSV::lire_csv($avecBom);
    $assert(!is_wp_error($lu), '1a. un CSV avec BOM est lisible');
    $assert(array_key_first($lu['lignes'][2]) === 'reference', '1b. le BOM ne colle pas à la première en-tête');
    $assert(($lu['lignes'][2]['nom'] ?? '') === 'Tacos poulet', '1c. les accents survivent au BOM');

    // =====================================================================
    // 2. Prix à virgule décimale
    // MUTATION M2 : remplacer lire_prix() par `(float) $brut`
    //               → « 45,50 » donne 45.0 → cas 2c rouge.
    // =====================================================================
    $assert(TUC_Import_CSV::lire_prix('45,50') === 45.5, '2a. « 45,50 » vaut 45.50');
    $assert(TUC_Import_CSV::lire_prix('45.50') === 45.5, '2b. « 45.50 » vaut 45.50');
    $assert(TUC_Import_CSV::lire_prix('45') === 45.0, '2c. un prix rond reste juste');
    $assert(TUC_Import_CSV::lire_prix("45\u{00A0}50") !== 45.5, '2d. l’espace insécable ne fabrique pas un prix');
    $verifier = TUC_Import_CSV::lire_prix('A_VERIFIER');
    $assert(is_wp_error($verifier), '2e. A_VERIFIER n’est pas un prix (jamais converti en 0)');

    // =====================================================================
    // 3. Prix au-dessus du seuil → AVERTISSEMENT sur une ligne VALIDE
    // MUTATION M3 : retirer le bloc `if ($prix > $seuil)` dans analyser_ligne_plat
    //               → cas 3b rouge.
    // =====================================================================
    $analyse = TUC_Import_CSV::analyser(
        $platsCsv([3 => ['reference' => 'cher', 'nom' => 'Plat cher', 'description' => '', 'prix' => '4500', 'categorie' => 'Tacos', 'promo_pct' => '0', 'disponible' => 'oui']]),
        null,
        $resto_id
    );
    $assert($analyse['erreurs'] === 0, '3a. 4500 MAD est un prix VALIDE (le code accepte jusqu’à 100 000)');
    $assert($analyse['avertissements'] === 1, '3b. mais il déclenche un avertissement, pas un refus');
    $assert(!$analyse['bloquant'], '3c. un avertissement ne bloque pas l’import — l’humain confirme');

    // =====================================================================
    // 4. Idempotence : réimporter met à jour, ne duplique pas
    // MUTATION M4 : dans indexer_existants(), forcer `$ref = ''`
    //               → le second import crée un doublon → cas 4c rouge.
    // =====================================================================
    $ligneRegex = ['reference' => 'test-idem', 'nom' => 'Plat idem', 'description' => 'desc', 'prix' => '30', 'categorie' => 'Tacos', 'promo_pct' => '0', 'disponible' => 'oui'];
    $premier = TUC_Import_CSV::importer(['test-idem' => $ligneRegex], [], $resto_id);
    $assert(!is_wp_error($premier) && $premier['crees'] === 1, '4a. premier import : 1 création');
    $second = TUC_Import_CSV::importer(['test-idem' => $ligneRegex], [], $resto_id);
    $assert(!is_wp_error($second) && $second['crees'] === 0 && $second['maj'] === 1, '4b. second import : mise à jour, pas création');
    $tous = get_posts([
        'post_type' => TUC_CPT::ITEM, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
        'meta_query' => [
            ['key' => 'tuc_resto_id', 'value' => $resto_id, 'compare' => '=', 'type' => 'NUMERIC'],
            ['key' => 'tuc_import_ref', 'value' => 'test-idem'],
        ],
    ]);
    $crees['items'] = array_merge($crees['items'], $tous);
    $assert(count($tous) === 1, '4c. une seule ligne en base après deux imports');

    // =====================================================================
    // 5. Une option qui référence un plat inexistant est refusée
    // MUTATION M5 : dans analyser(), retirer le contrôle `!isset($references[$ref])`
    //               → l'option passe et le groupe n'est jamais rattaché → cas 5 rouge.
    // =====================================================================
    $analyse = TUC_Import_CSV::analyser(
        $platsCsv([2 => ['reference' => 'existe', 'nom' => 'Plat', 'description' => '', 'prix' => '20', 'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui']]),
        ['lignes' => [3 => ['plat_reference' => 'inconnu', 'groupe_id' => 'taille', 'groupe_nom' => 'Taille', 'obligatoire' => 'oui', 'min' => '1', 'max' => '1', 'choix_id' => 'm', 'choix_nom' => 'M', 'supplement_prix' => '0', 'disponible' => 'oui']]],
        $resto_id
    );
    $assert($analyse['bloquant'], '5. une option rattachée à un plat inexistant bloque l’import');

    // =====================================================================
    // 6. obligatoire=oui avec min=0 → refus au niveau CSV
    // MUTATION M6 : retirer le bloc `if ($obligatoire && $min === '0')`
    //               → le cœur force min=1 en silence → cas 6 rouge.
    // =====================================================================
    $groupes = TUC_Import_CSV::analyser_groupes('plat', [
        2 => ['plat_reference' => 'plat', 'groupe_id' => 'taille', 'groupe_nom' => 'Taille', 'obligatoire' => 'oui', 'min' => '0', 'max' => '1', 'choix_id' => 'm', 'choix_nom' => 'M', 'supplement_prix' => '0', 'disponible' => 'oui'],
    ]);
    $assert($groupes !== [], '6. obligatoire=oui avec min=0 est refusé (le cœur, lui, forcerait min=1 sans le dire)');

    // =====================================================================
    // 7. Divergence entre deux lignes d'un même groupe
    // =====================================================================
    $groupes = TUC_Import_CSV::analyser_groupes('plat', [
        2 => ['plat_reference' => 'plat', 'groupe_id' => 'sauce', 'groupe_nom' => 'Sauces', 'obligatoire' => 'non', 'min' => '0', 'max' => '2', 'choix_id' => 'a', 'choix_nom' => 'A', 'supplement_prix' => '0', 'disponible' => 'oui'],
        3 => ['plat_reference' => 'plat', 'groupe_id' => 'sauce', 'groupe_nom' => 'Sauces', 'obligatoire' => 'non', 'min' => '0', 'max' => '3', 'choix_id' => 'b', 'choix_nom' => 'B', 'supplement_prix' => '0', 'disponible' => 'oui'],
    ]);
    $assert($groupes !== [], '7. un max différent entre deux lignes du même groupe est refusé');

    // =====================================================================
    // 8. Bornes : 13 groupes, 21 choix dans UN SEUL groupe, choix_id en double
    // MAX_GROUPS et MAX_CHOICES sont privées : on ne les lit pas, on les dépasse.
    // =====================================================================
    $treize = [];
    for ($i = 1; $i <= 13; $i++) {
        $treize[$i] = ['plat_reference' => 'p', 'groupe_id' => 'g' . $i, 'groupe_nom' => 'G' . $i,
                       'obligatoire' => 'non', 'min' => '0', 'max' => '1',
                       'choix_id' => 'c', 'choix_nom' => 'C', 'supplement_prix' => '0', 'disponible' => 'oui'];
    }
    $assert(TUC_Import_CSV::analyser_groupes('p', $treize) !== [], '8a. 13 groupes refusés (maximum 12)');

    $vingtEtUn = [];
    for ($i = 1; $i <= 21; $i++) {
        $vingtEtUn[$i] = ['plat_reference' => 'p', 'groupe_id' => 'sauce', 'groupe_nom' => 'Sauces',
                          'obligatoire' => 'non', 'min' => '0', 'max' => '20',
                          'choix_id' => 'c' . $i, 'choix_nom' => 'C' . $i, 'supplement_prix' => '0', 'disponible' => 'oui'];
    }
    $assert(TUC_Import_CSV::analyser_groupes('p', $vingtEtUn) !== [], '8b. 21 choix dans un seul groupe refusés (maximum 20 PAR GROUPE)');

    $doublons = [
        2 => ['plat_reference' => 'p', 'groupe_id' => 'sauce', 'groupe_nom' => 'Sauces', 'obligatoire' => 'non', 'min' => '0', 'max' => '2', 'choix_id' => 'a', 'choix_nom' => 'A', 'supplement_prix' => '0', 'disponible' => 'oui'],
        3 => ['plat_reference' => 'p', 'groupe_id' => 'sauce', 'groupe_nom' => 'Sauces', 'obligatoire' => 'non', 'min' => '0', 'max' => '2', 'choix_id' => 'a', 'choix_nom' => 'A bis', 'supplement_prix' => '0', 'disponible' => 'oui'],
    ];
    $assert(TUC_Import_CSV::analyser_groupes('p', $doublons) !== [], '8c. deux choix_id identiques dans un groupe refusés');

    // =====================================================================
    // 9. Un nom arabe passe ; un nom de 400 octets arabes est refusé proprement
    // =====================================================================
    $arabe = 'طاجين سمك بالخضر';
    $analyse = TUC_Import_CSV::analyser(
        $platsCsv([2 => ['reference' => 'arabe', 'nom' => $arabe, 'description' => 'الطاجين', 'prix' => '60', 'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui']]),
        null,
        $resto_id
    );
    $assert($analyse['erreurs'] === 0, '9a. un nom de plat arabe est accepté tel quel (' . strlen($arabe) . ' octets)');
    $trop = str_repeat('ب', 250); // 500 octets
    $analyse = TUC_Import_CSV::analyser(
        $platsCsv([2 => ['reference' => 'long', 'nom' => $trop, 'description' => '', 'prix' => '10', 'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui']]),
        null,
        $resto_id
    );
    $assert($analyse['erreurs'] > 0, '9b. un nom de 500 octets est refusé (limite du cœur : 400 OCTETS, pas caractères)');

    // =====================================================================
    // 10. Une écriture qui échoue ne laisse RIEN derrière elle
    // MUTATION M7 : dans compenser(), ne rien faire → cas 10b rouge.
    // On provoque l'échec avec une référence dont le nom dépasse la limite du
    // validateur APRÈS qu'une première ligne a été écrite.
    // =====================================================================
    $avant = (int) wp_count_posts(TUC_CPT::ITEM)->draft;
    $tropLong = str_repeat('x', 500);
    $resultat = TUC_Import_CSV::importer(
        [
            'ok-1' => ['reference' => 'ok-1', 'nom' => 'Premier', 'description' => '', 'prix' => '10', 'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui'],
            'trop-long' => ['reference' => 'trop-long', 'nom' => $tropLong, 'description' => '', 'prix' => '10', 'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui'],
        ],
        [],
        $resto_id
    );
    $assert(is_wp_error($resultat), '10a. l’import échoue sur la seconde ligne');
    $apres = (int) wp_count_posts(TUC_CPT::ITEM)->draft;
    $assert($avant === $apres, '10b. ZÉRO écriture résiduelle : le journal a été rejoué à l’envers');
    $orphelins = get_posts([
        'post_type' => TUC_CPT::ITEM, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
        'meta_query' => [['key' => 'tuc_import_ref', 'value' => 'ok-1']],
    ]);
    $assert($orphelins === [], '10c. le plat de la ligne 1 n’existe pas');

    // =====================================================================
    // 11. A_VERIFIER dans le prix = erreur de ligne, jamais 0
    // =====================================================================
    $analyse = TUC_Import_CSV::analyser(
        $platsCsv([2 => ['reference' => 'a-verifier', 'nom' => 'Plat', 'description' => '', 'prix' => 'A_VERIFIER', 'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui']]),
        null,
        $resto_id
    );
    $assert($analyse['bloquant'], '11. A_VERIFIER bloque l’import');
    $assert($analyse['lignes'][0]['prix'] === null, '11b. et ne devient pas un prix de 0 MAD');

    // =====================================================================
    // 12. Handlers : sans nonce, sans capacité → 403
    // =====================================================================
    // Les trois filtres sont posés ici et retirés dans le `finally` ci-dessous.
    $poseInterception();

    $refus = null;
    wp_set_current_user(0);
    try {
        TUC_Import_CSV::telecharger_admin();
    } catch (Throwable $e) {
        $refus = $e;
    }
    $assert($refus !== null && str_contains($refus->getMessage(), 'WP_DIE'), '12a. un visiteur non connecté est arrêté par wp_die');

    wp_set_current_user((int) $admin[0]);
    $_REQUEST['_wpnonce'] = 'faux-nonce';
    $refus = null;
    try {
        TUC_Import_CSV::telecharger_admin();
    } catch (Throwable $e) {
        $refus = $e;
    }
    $assert($refus !== null, '12b. un nonce invalide est refusé');
    unset($_REQUEST['_wpnonce']);

    // Et avec un compte SANS manage_options (rôle abonné) : la capacité prime
    // sur le nonce, sinon un nonce volé suffirait.
    $abonne = wp_insert_user(['user_login' => 'tuc_contrat_abonne_' . wp_generate_password(6, false),
                              'user_pass' => wp_generate_password(24), 'role' => 'subscriber']);
    wp_set_current_user((int) $abonne);
    $_REQUEST['_wpnonce'] = 'faux-nonce';
    $refus = null;
    try {
        TUC_Import_CSV::telecharger_admin();
    } catch (Throwable $e) {
        $refus = $e;
    }
    $assert($refus !== null, '12c. un compte sans manage_options est refusé, même avec un nonce');
    unset($_REQUEST['_wpnonce']);
    wp_delete_user((int) $abonne);
    wp_set_current_user((int) $admin[0]);


    // =====================================================================
    // 13. Les plats importés arrivent en BROUILLON
    // MUTATION M8 : écrire 'publish' au lieu de 'draft' dans importer()
    //               → cas 13 rouge.
    // =====================================================================
    $resultat = TUC_Import_CSV::importer(
        ['brouillon-1' => ['reference' => 'brouillon-1', 'nom' => 'Plat brouillon', 'description' => '', 'prix' => '25', 'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui']],
        [],
        $resto_id
    );
    $assert(!is_wp_error($resultat), '13a. l’import passe');
    $id = (int) get_posts([
        'post_type' => TUC_CPT::ITEM, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids',
        'meta_query' => [['key' => 'tuc_import_ref', 'value' => 'brouillon-1']],
    ])[0];
    $crees['items'][] = $id;
    $assert(get_post_status($id) === 'draft', '13b. le plat est en brouillon, jamais publié');
    // Le cas 13b ci-dessus passe par `construire_payload()`, qui porte SA PROPRE
    // copie de 'draft'. Le forçage réel vit dans `importer()` (wp_insert_post).
    // Mutation M7 (run 42637710646) a changé le second et le contrat est resté
    // VERT : la copie du payload tenait le test à la place du vrai garde. On
    // appelle donc importer() directement, sans passer par le payload.
    $direct = TUC_Import_CSV::importer(
        ['direct-draft' => ['reference' => 'direct-draft', 'nom' => 'Plat direct', 'description' => '',
                            'prix' => '30', 'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui']],
        [],
        $resto_id
    );
    $assert(!is_wp_error($direct), '13c. l’import direct (sans payload intermédiaire) passe');
    $idDirect = 0;
    foreach (get_posts(['post_type' => TUC_CPT::ITEM, 'post_status' => 'any', 'posts_per_page' => -1,
                        'fields' => 'ids', 'suppress_filters' => true]) as $candidat) {
        if ((string) get_post_meta((int) $candidat, 'tuc_import_ref', true) === 'direct-draft') {
            $idDirect = (int) $candidat;
        }
    }
    $crees['items'][] = $idDirect;
    $assert($idDirect > 0 && get_post_status($idDirect) === 'draft',
        '13d. importer() force le brouillon par lui-même — pas seulement construire_payload()');

    // =====================================================================
    // 14. Un plat DÉJÀ PUBLIÉ redevient brouillon, et le dry-run le DIT
    // =====================================================================
    wp_update_post(['ID' => $id, 'post_status' => 'publish']);
    $analyse = TUC_Import_CSV::analyser(
        $platsCsv([2 => ['reference' => 'brouillon-1', 'nom' => 'Plat brouillon', 'description' => '', 'prix' => '25', 'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui']]),
        null,
        $resto_id
    );
    $dit = implode(' ', $analyse['lignes'][0]['avertissements']);
    $assert(str_contains($dit, 'PUBLIÉ'), '14a. le dry-run annonce que le plat publié repassera en brouillon');
    TUC_Import_CSV::importer(['brouillon-1' => ['reference' => 'brouillon-1', 'nom' => 'Plat brouillon', 'description' => '', 'prix' => '25', 'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui']], [], $resto_id);
    $assert(get_post_status($id) === 'draft', '14b. et il repasse bien en brouillon (pas de republication silencieuse)');

    // =====================================================================
    // 15. Options conditionnelles : refusées, jamais écrasées en silence
    // =====================================================================
    $avecCondition = [
        'version' => TUC_Options::VERSION,
        'groups' => [[
            'id' => 'taille', 'name' => 'Taille', 'required' => true, 'min' => 1, 'max' => 1,
            'choices' => [['id' => 'm', 'name' => 'M', 'price_delta' => 0, 'available' => true]],
        ], [
            'id' => 'sauce', 'name' => 'Sauces', 'required' => false, 'min' => 0, 'max' => 1,
            'visible_if' => ['group_id' => 'taille', 'choice_ids' => ['m']],
            'choices' => [['id' => 'a', 'name' => 'A', 'price_delta' => 0, 'available' => true]],
        ]],
    ];
    update_post_meta($id, 'tuc_options', $avecCondition);
    $assert(TUC_Import_CSV::a_options_conditionnelles($id), '15a. le plat a bien des options conditionnelles');
    $analyse = TUC_Import_CSV::analyser(
        $platsCsv([2 => ['reference' => 'brouillon-1', 'nom' => 'Plat brouillon', 'description' => '', 'prix' => '25', 'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui']]),
        null,
        $resto_id
    );
    $assert($analyse['bloquant'], '15b. le dry-run bloque ce plat (« à modifier à la main »)');
    // L'import doit REFUSER, pas seulement l'écran. Sinon un appel direct
    // (CLI, code, dry-run contourné) écrase les conditions sans rien dire :
    // c'est exactement ce que le cas 15c a trouvé.
    $ligne = ['reference' => 'brouillon-1', 'nom' => 'Plat brouillon', 'description' => '', 'prix' => '25',
              'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui'];
    $resultat = TUC_Import_CSV::importer(['brouillon-1' => $ligne], [], $resto_id);
    $assert(is_wp_error($resultat) && $resultat->get_error_code() === 'tuc_import_condition', '15c. l’import lui-même refuse : les conditions ne peuvent pas être écrasées en silence');
    $apres = get_post_meta($id, 'tuc_options', true);
    $assert(isset($apres['groups'][1]['visible_if']), '15d. les conditions sont intactes en base après le refus');
    $forcer = TUC_Import_CSV::importer(['brouillon-1' => $ligne], [], $resto_id, true);
    $assert(!is_wp_error($forcer), '15e. un écrasement volontaire (arg explicite) reste possible, mais il faut le demander');

    // =====================================================================
    // 16. Un brouillon importé est VISIBLE via /pro/menu et ABSENT de /menu/{slug}
    // C'est la garde qui empêche de refaire l'erreur ⑦ : la route pro accepte
    // ['publish','draft'], la route client n'accepte que 'publish'.
    // =====================================================================
    $proMenu = get_posts([
        'post_type' => TUC_CPT::ITEM, 'post_status' => ['publish', 'draft'],
        'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
        'meta_query' => [['key' => 'tuc_resto_id', 'value' => $resto_id, 'compare' => '=', 'type' => 'NUMERIC']],
    ]);
    $clientMenu = get_posts([
        'post_type' => TUC_CPT::ITEM, 'post_status' => 'publish',
        'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
        'meta_query' => [['key' => 'tuc_resto_id', 'value' => $resto_id, 'compare' => '=', 'type' => 'NUMERIC']],
    ]);
    $assert(in_array($id, array_map('intval', $proMenu), true), '16a. le brouillon est VISIBLE côté restaurateur (/pro/menu : publish + draft)');
    $assert(!in_array($id, array_map('intval', $clientMenu), true), '16b. et ABSENT côté client (/menu/{slug} : publish seulement)');

    // =====================================================================
    // 17. Le plat est rattaché par tuc_resto_id, JAMAIS par tuc_resto
    // tuc_resto est la clé des COMMANDES. Un plat écrit avec elle serait
    // invisible des deux menus, sans aucune erreur.
    // =====================================================================
    $assert((int) get_post_meta($id, 'tuc_resto_id', true) === $resto_id, '17a. le plat porte tuc_resto_id');
    $assert(get_post_meta($id, 'tuc_resto', true) === '', '17b. et PAS tuc_resto (sinon : menu fantôme)');
    $assert((int) get_post_meta($id, 'tuc_resto_id', true) !== $autre_resto, '17c. le plat n’est pas rattaché au restaurant voisin');

} finally {
    // ---- restauration d'abord : la mutation globale avant tout le reste ----
    // `wp_die` doit redevenir mortel pour tout ce qui tourne après ce contrat,
    // que le contrat passe ou échoue.
    $retirerInterception();

    // ---- nettoyage : rien ne doit survivre à la suite ----
    // Dans un `finally` : la fuite ne dépend pas du succès du contrat.
    foreach ($crees['items'] as $itemId) {
        wp_delete_post((int) $itemId, true);
    }
    foreach ($crees['restos'] as $resto) {
        wp_delete_post((int) $resto, true);
    }
    foreach ($crees['users'] as $userId) {
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        wp_delete_user((int) $userId);
    }
    // Les transients du module portent l'id de l'utilisateur courant : ce sont
    // des brouillons d'écran qui survivraient 24 h et fausseraient l'écran admin
    // d'un test ultérieur (il lirait un dry-run qui n'est pas le sien).
    foreach ($crees['transients'] as $transient) {
        delete_transient($transient);
    }
    $adminCourant = get_current_user_id();
    delete_transient('tuc_import_csv_' . $adminCourant);

    // Preuve exigée : comptage toutes statuts confondus, identique à l'avant.
    wp_set_current_user((int) $admin[0]);
    $inventaireApres = $inventaire();
    $assert(
        $inventaireApres === $inventaireAvant,
        'D. aucune fixture ne survit (toutes statuts confondus) — ' . $diffInventaire($inventaireAvant, $inventaireApres)
    );
    wp_set_current_user(0);
}

// Le compte est lu ICI, après le finally : un `return` placé dans le `try` est
// évalué AVANT que le `finally` s'exécute, et perdrait donc le cas D.
return (int) $casExecutees;
}
