<?php
/**
 * Jeu de démonstration — de VRAIS articles WordPress, clairement marqués.
 *
 * Pourquoi ce script existe : la page d'accueil lisait autrefois 25 restaurants
 * codés en dur dans le thème. Sans article WordPress derrière, ils n'avaient pas
 * d'URL : cartes non cliquables, aucune boutique consultable. Ce script crée le
 * même genre de vitrine sous forme d'articles `tuc_resto` réels, avec permalien,
 * fiche restaurant et menu — et le méta `tuc_demo = 1` qui déclenche le badge
 * « Démo » à l'affichage.
 *
 * Aucun nom de marque réelle : l'ancien jeu citait des enseignes existantes, ce
 * qui faisait passer de la fiction pour de vrais commerces.
 *
 * Usage :
 *   wp eval-file tools/seed-demo.php            # crée ou met à jour (idempotent)
 *   wp eval-file tools/seed-demo.php supprimer  # retire tout le contenu démo
 *
 * Attention à la forme : WP-CLI 2.x n'accepte PAS `-- --supprimer` (il repond
 * « unknown --supprimer parameter »). L'argument se passe NU, sans tirets.
 *
 * Refuse de s'exécuter si WP_ENVIRONMENT_TYPE vaut 'production'. Le contenu de
 * démonstration n'a rien à faire sur un site ouvert au public.
 */

if (!class_exists('WP_CLI')) {
    fwrite(STDERR, "Ce script doit être exécuté avec WP-CLI.\n");
    exit(1);
}

if (!class_exists('TUC_CPT')) {
    WP_CLI::error('Le plugin tuc-market-core doit être actif.');
}

$environnement = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
if ($environnement === 'production') {
    WP_CLI::error('Refus : WP_ENVIRONMENT_TYPE=production. Le contenu de démonstration ne va pas sur un site public.');
}

$supprimer = in_array('supprimer', (array) ($args ?? []), true)
    || in_array('--supprimer', (array) ($args ?? []), true); // ancienne forme, toleree

/** Tout le contenu créé ici porte ce marqueur, pour pouvoir être retiré d'un coup. */
const TUC_DEMO_FLAG = 'tuc_demo';

if ($supprimer) {
    // ---- ce que la purge va laisser derriere elle, dit AVANT d'ecrire ----
    // `tuc_demo` est une META, pas une parente : supprimer un resto ne supprime
    // pas ses commandes. Elles restent en base avec un `tuc_resto` qui pointe
    // vers un article disparu. Le suivi client affiche alors « Restaurant TUC »
    // au lieu du nom, et l'espace pro ne les voit plus. C'est acceptable pour
    // des commandes de test — a condition de le savoir AVANT.
    $restosDemo = get_posts(['post_type' => TUC_CPT::RESTO, 'post_status' => 'any',
        'posts_per_page' => -1, 'fields' => 'ids',
        'meta_query' => [['key' => TUC_DEMO_FLAG, 'value' => '1']]]);
    $total = 0;
    if (class_exists('TUC_Commande')) {
        foreach ($restosDemo as $rid) {
            $cmd = get_posts(['post_type' => TUC_Commande::CPT, 'post_status' => 'any',
                'posts_per_page' => -1, 'fields' => 'ids',
                'meta_query' => [['key' => 'tuc_resto', 'value' => $rid]]]);
            $total += count($cmd);
        }
    }
    WP_CLI::warning(sprintf(
        'La purge va laisser %d commande(s) orpheline(s) en base : elles restent lisibles, '
        . 'mais lues dans un restaurant qui n\'existe plus. Aucune ne sera supprimee.',
        $total
    ));

    $cibles = get_posts([
        'post_type'      => [TUC_CPT::RESTO, TUC_CPT::ITEM],
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [['key' => TUC_DEMO_FLAG, 'value' => '1']],
    ]);
    // Les articles d'abord, pour ne pas laisser d'orphelins pointant vers un resto supprimé.
    foreach ($cibles as $cible) {
        wp_delete_post((int) $cible, true);
    }
    WP_CLI::success(count($cibles) . ' élément(s) de démonstration supprimé(s).');
    return;
}

/**
 * Vitrine de démonstration. Noms inventés, aucune enseigne réelle.
 * `kind` alimente les sections de la page d'accueil, `img` le visuel de repli.
 */
$catalogue = [
    ['slug' => 'demo-table-du-port',   'n' => 'La Table du Port',      'cu' => 'Marocain · Poisson',   'k' => 'restaurant', 'r' => 4.8, 'tmin' => 25, 'tmax' => 40, 'fee' => 0,  'img' => 'fish',       'plats' => [['Tajine de poisson', 68, 0], ['Couscous du vendredi', 55, 20], ['Brochettes de la mer', 74, 0]]],
    ['slug' => 'demo-jardin-olive',    'n' => 'Le Jardin d\'Olive',    'cu' => 'Marocain · Tajines',   'k' => 'restaurant', 'r' => 4.7, 'tmin' => 20, 'tmax' => 35, 'fee' => 12, 'img' => 'tagine',     'plats' => [['Tajine poulet-citron', 62, 0], ['Tanjia marrakchie', 78, 15], ['Salade marocaine', 24, 0]]],
    ['slug' => 'demo-maison-grill',    'n' => 'Maison Grillades',      'cu' => 'Grillades · Brochettes', 'k' => 'restaurant', 'r' => 4.6, 'tmin' => 20, 'tmax' => 30, 'fee' => 15, 'img' => 'grillades', 'plats' => [['Brochettes kefta', 48, 0], ['Côtelettes d\'agneau', 92, 0], ['Poulet fermier grillé', 65, 25]]],
    ['slug' => 'demo-laiterie-atlas',  'n' => 'Laiterie de l\'Atlas',  'cu' => 'Jben · Raib · Lait',   'k' => 'mahlaba',    'r' => 4.9, 'tmin' => 10, 'tmax' => 20, 'fee' => 0,  'img' => 'p-jben',     'plats' => [['Jben frais 500 g', 28, 0], ['Raib nature 1 L', 18, 30], ['Beurre fermier', 34, 0]]],
    ['slug' => 'demo-source-lait',     'n' => 'La Source du Lait',     'cu' => 'Fromage frais · Amlou', 'k' => 'mahlaba',   'r' => 4.7, 'tmin' => 15, 'tmax' => 25, 'fee' => 10, 'img' => 'p-amlou',    'plats' => [['Amlou aux amandes', 42, 0], ['Msemen x6', 16, 0], ['Briouat au miel', 22, 20]]],
    ['slug' => 'demo-atelier-tacos',   'n' => 'L\'Atelier Tacos',      'cu' => 'Tacos · Fast food',    'k' => 'snack',      'r' => 4.6, 'tmin' => 15, 'tmax' => 25, 'fee' => 0,  'img' => 'tacos',      'plats' => [['Tacos 2 viandes', 45, 40], ['Tacos poulet', 38, 0], ['Frites maison', 15, 0]]],
    ['slug' => 'demo-comptoir-burger', 'n' => 'Le Comptoir Burger',    'cu' => 'Burgers · Sandwichs',  'k' => 'snack',      'r' => 4.5, 'tmin' => 15, 'tmax' => 30, 'fee' => 12, 'img' => 'burger',     'plats' => [['Burger signature', 52, 0], ['Double cheese', 64, 25], ['Sandwich escalope', 34, 0]]],
    ['slug' => 'demo-four-a-bois',     'n' => 'Le Four à Bois',        'cu' => 'Pizza napolitaine',    'k' => 'snack',      'r' => 4.7, 'tmin' => 20, 'tmax' => 35, 'fee' => 18, 'img' => 'pizza',      'plats' => [['Margherita', 48, 0], ['Quatre fromages', 68, 15], ['Végétarienne', 58, 0]]],
    ['slug' => 'demo-cafe-des-arts',   'n' => 'Café des Arts',         'cu' => 'Café · Petit-déj',     'k' => 'cafe',       'r' => 4.8, 'tmin' => 10, 'tmax' => 20, 'fee' => 0,  'img' => 'cafe',       'plats' => [['Nous-nous', 14, 0], ['Thé à la menthe', 12, 0], ['Petit-déj complet', 38, 20]]],
    ['slug' => 'demo-terrasse-the',    'n' => 'La Terrasse du Thé',    'cu' => 'Thé · Pâtisserie',     'k' => 'cafe',       'r' => 4.6, 'tmin' => 10, 'tmax' => 25, 'fee' => 8,  'img' => 'p-the',      'plats' => [['Thé vert safran', 16, 0], ['Corne de gazelle x6', 30, 0], ['Cake du jour', 20, 30]]],
    ['slug' => 'demo-torrefaction',    'n' => 'Torréfaction Nord',     'cu' => 'Specialty coffee',     'k' => 'coffee',     'r' => 4.9, 'tmin' => 15, 'tmax' => 30, 'fee' => 14, 'img' => 'p-latte',    'plats' => [['Flat white', 26, 0], ['Filtre du jour', 24, 0], ['Latte glacé', 28, 20]]],
    ['slug' => 'demo-brunch-club',     'n' => 'Brunch Club',           'cu' => 'Brunch · Viennoiserie', 'k' => 'coffee',    'r' => 4.7, 'tmin' => 20, 'tmax' => 35, 'fee' => 0,  'img' => 'p-croissant', 'plats' => [['Croissant beurre', 12, 0], ['Œufs bénédicte', 58, 0], ['Matcha latte', 30, 25]]],
];

TUC_Roles::synchroniser();

$restos_crees = 0;
$plats_crees = 0;

foreach ($catalogue as $entree) {
    $existant = get_page_by_path($entree['slug'], OBJECT, TUC_CPT::RESTO);
    $resto_id = $existant instanceof WP_Post ? (int) $existant->ID : 0;

    $champs = [
        'post_type'    => TUC_CPT::RESTO,
        'post_status'  => 'publish',
        'post_name'    => $entree['slug'],
        'post_title'   => $entree['n'],
        'post_content' => 'Établissement de démonstration. Aucune commande réelle ne doit être passée ici.',
        'meta_input'   => [
            TUC_DEMO_FLAG   => '1',
            'tuc_kind'      => $entree['k'],
            'tuc_img'       => $entree['img'],
            'tuc_cuisine'   => $entree['cu'],
            'tuc_note'      => $entree['r'],
            'tuc_temps_min' => $entree['tmin'],
            'tuc_temps_max' => $entree['tmax'],
            'tuc_frais'     => $entree['fee'],
            'tuc_minimum'   => 0,
            'tuc_modes'     => ['livraison', 'emporter', 'table'],
            'tuc_tables'    => 8,
            'tuc_ferme'     => '0',
        ],
    ];

    if ($resto_id > 0) {
        $champs['ID'] = $resto_id;
        $resultat = wp_update_post(wp_slash($champs), true);
    } else {
        $resultat = wp_insert_post(wp_slash($champs), true);
    }
    if (is_wp_error($resultat)) {
        WP_CLI::error('Restaurant ' . $entree['slug'] . ' : ' . $resultat->get_error_message());
    }
    $resto_id = (int) $resultat;
    $restos_crees++;

    // Les métas sont reposées explicitement : wp_update_post n'écrase pas
    // toujours meta_input de façon fiable selon la version de WordPress.
    foreach ($champs['meta_input'] as $cle => $valeur) {
        update_post_meta($resto_id, $cle, $valeur);
    }

    foreach ($entree['plats'] as $index => [$nom, $prix, $promo]) {
        $slug_plat = $entree['slug'] . '-plat-' . ($index + 1);
        $plat_existant = get_page_by_path($slug_plat, OBJECT, TUC_CPT::ITEM);
        $plat_champs = [
            'post_type'    => TUC_CPT::ITEM,
            'post_status'  => 'publish',
            'post_name'    => $slug_plat,
            'post_title'   => $nom,
            'post_content' => 'Plat de démonstration.',
        ];
        if ($plat_existant instanceof WP_Post) {
            $plat_champs['ID'] = (int) $plat_existant->ID;
            $plat_id = wp_update_post(wp_slash($plat_champs), true);
        } else {
            $plat_id = wp_insert_post(wp_slash($plat_champs), true);
        }
        if (is_wp_error($plat_id)) {
            WP_CLI::error('Plat ' . $slug_plat . ' : ' . $plat_id->get_error_message());
        }
        update_post_meta((int) $plat_id, TUC_DEMO_FLAG, '1');
        update_post_meta((int) $plat_id, 'tuc_resto_id', $resto_id);
        update_post_meta((int) $plat_id, 'tuc_prix', $prix);
        update_post_meta((int) $plat_id, 'tuc_promo_pct', $promo);
        update_post_meta((int) $plat_id, 'tuc_dispo', '1');
        $plats_crees++;
    }
}

WP_CLI::success(sprintf(
    'Démonstration prête : %d restaurants, %d plats. Tous marqués %s=1, donc badgés « Démo » et retirables par --supprimer.',
    $restos_crees,
    $plats_crees,
    TUC_DEMO_FLAG
));
WP_CLI::log('Chaque restaurant a un permalien : les cartes sont cliquables et la fiche boutique répond.');
WP_CLI::log('Aucun numéro WhatsApp ni compte n\'est créé ici : ce sont des décisions distinctes.');
