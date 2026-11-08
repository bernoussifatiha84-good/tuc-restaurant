<?php
/**
 * Lecture du catalogue depuis la base WordPress.
 *
 * Auparavant ce fichier contenait 25 restaurants codés en dur. Ils n'avaient
 * aucun article WordPress derrière eux, donc aucune URL : les cartes n'étaient
 * pas cliquables et aucune boutique n'était consultable. Le catalogue vit
 * désormais dans le custom post type `tuc_resto`.
 *
 * Le contenu de démonstration est créé par `tools/seed-demo.php` sous forme de
 * VRAIS articles marqués `tuc_demo = 1`. Ils ont donc un permalien, une fiche
 * restaurant et un menu, et ils portent un badge « Démo » à l'affichage. Aucun
 * faux restaurant n'est plus présenté comme réel, et rien n'est codé en dur ici.
 *
 * La forme du tableau renvoyé est inchangée par rapport à l'ancienne version
 * (n, cu, k, r, t, fee, img) pour ne pas casser les gabarits ; s'y ajoutent
 * `id`, `url`, `demo`, `thumb` et `livraison`.
 */

defined('ABSPATH') || exit;

/**
 * Catalogue des restaurants publiés.
 *
 * @return array<int,array<string,mixed>>
 */
function tuc_restos(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $articles = get_posts([
        'post_type'        => 'tuc_resto',
        'post_status'      => 'publish',
        'posts_per_page'   => 200,
        'no_found_rows'    => true,
        'orderby'          => 'title',
        'order'            => 'ASC',
        'suppress_filters' => false,
    ]);

    $cache = [];
    foreach ($articles as $article) {
        $id       = (int) $article->ID;
        $tmin     = (int) get_post_meta($id, 'tuc_temps_min', true);
        $tmax     = (int) get_post_meta($id, 'tuc_temps_max', true);
        $frais    = (int) get_post_meta($id, 'tuc_frais', true);
        $modes    = (array) get_post_meta($id, 'tuc_modes', true);
        $livre    = in_array('livraison', $modes, true);
        $kind     = (string) get_post_meta($id, 'tuc_kind', true);
        $image    = (string) get_post_meta($id, 'tuc_img', true);

        $cache[] = [
            'id'        => $id,
            'url'       => (string) get_permalink($article),
            'demo'      => get_post_meta($id, 'tuc_demo', true) === '1',
            'n'         => (string) $article->post_title,
            'cu'        => (string) get_post_meta($id, 'tuc_cuisine', true),
            'k'         => $kind !== '' ? $kind : 'restaurant',
            'r'         => (float) get_post_meta($id, 'tuc_note', true),
            't'         => $tmin . '–' . max($tmax, $tmin),
            // null = livraison offerte. Un commerce qui ne livre pas ne doit pas
            // remonter dans « Livraison offerte » : drapeau séparé.
            'fee'       => $frais > 0 ? $frais : null,
            'livraison' => $livre,
            'img'       => $image !== '' ? $image : 'hero-banner',
            'thumb'     => has_post_thumbnail($id)
                ? (string) get_the_post_thumbnail($id, 'tuc-card', ['loading' => 'lazy', 'alt' => ''])
                : '',
        ];
    }

    return $cache;
}

/**
 * Promotions réelles : articles publiés avec un pourcentage de remise, rattachés
 * à un restaurant publié. Chaque promotion pointe vers la boutique.
 *
 * @return array<int,array<string,mixed>>
 */
function tuc_promos(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $articles = get_posts([
        'post_type'      => 'tuc_menu_item',
        'post_status'    => 'publish',
        'posts_per_page' => 24,
        'no_found_rows'  => true,
        'meta_query'     => [
            ['key' => 'tuc_promo_pct', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC'],
            ['key' => 'tuc_dispo', 'value' => '0', 'compare' => '!='],
        ],
    ]);

    $cache = [];
    foreach ($articles as $article) {
        $resto_id = (int) get_post_meta($article->ID, 'tuc_resto_id', true);
        $resto    = $resto_id > 0 ? get_post($resto_id) : null;
        if (!$resto instanceof WP_Post || $resto->post_status !== 'publish') {
            continue;
        }
        $pct  = (int) get_post_meta($article->ID, 'tuc_promo_pct', true);
        $tmin = (int) get_post_meta($resto_id, 'tuc_temps_min', true);
        $tmax = (int) get_post_meta($resto_id, 'tuc_temps_max', true);

        $cache[] = [
            'big'  => '−' . $pct . ' %',
            'nm'   => (string) $article->post_title,
            'shop' => (string) $resto->post_title,
            'r'    => (float) get_post_meta($resto_id, 'tuc_note', true),
            't'    => $tmin . '–' . max($tmax, $tmin) . ' min',
            'url'  => (string) get_permalink($resto),
            'demo' => get_post_meta($resto_id, 'tuc_demo', true) === '1',
            'img'  => (string) get_post_meta($resto_id, 'tuc_img', true) ?: 'hero-banner',
            'thumb'=> has_post_thumbnail($article->ID)
                ? (string) get_the_post_thumbnail($article->ID, 'tuc-card', ['loading' => 'lazy', 'alt' => ''])
                : '',
        ];
    }

    return $cache;
}

/** Les sections de la page d'accueil, dans l'ordre. */
function tuc_sections(): array
{
    return [
        ['id' => 'promo',  'fr' => 'Promos du jour', 'ar' => 'عروض اليوم', 'type' => 'promos'],
        ['id' => 'rest',   'fr' => 'Restaurants les mieux notés', 'ar' => 'المطاعم الأعلى تقييماً', 'type' => 'k', 'k' => 'restaurant'],
        ['id' => 'maha',   'fr' => 'Mahlabas les mieux notées', 'ar' => 'الملبنات الأعلى تقييماً', 'type' => 'k', 'k' => 'mahlaba'],
        ['id' => 'snack',  'fr' => 'Snacks les mieux notés', 'ar' => 'السناكات الأعلى تقييماً', 'type' => 'k', 'k' => 'snack'],
        ['id' => 'cafe',   'fr' => 'Cafés les mieux notés', 'ar' => 'القهاوي الأعلى تقييماً', 'type' => 'k', 'k' => 'cafe'],
        ['id' => 'coffee', 'fr' => 'Coffee shops les mieux notés', 'ar' => 'الكافي شوب الأعلى تقييماً', 'type' => 'k', 'k' => 'coffee'],
        ['id' => 'free',   'fr' => 'Livraison offerte', 'ar' => 'التوصيل مجاني', 'type' => 'free'],
        ['id' => 'fast',   'fr' => 'Les plus rapides', 'ar' => 'الأسرع', 'type' => 'fast'],
    ];
}

/** Filtre une liste de restos selon le type de section. */
function tuc_pick(array $all, array $sec): array
{
    switch ($sec['type']) {
        case 'k':
            $out = array_values(array_filter($all, fn($x) => $x['k'] === $sec['k']));
            usort($out, fn($a, $b) => $b['r'] <=> $a['r']);
            return $out;
        case 'free':
            // Ne livrer pas du tout n'est pas « livraison offerte ».
            $out = array_values(array_filter(
                $all,
                fn($x) => ($x['livraison'] ?? false) && $x['fee'] === null
            ));
            usort($out, fn($a, $b) => $b['r'] <=> $a['r']);
            return array_slice($out, 0, 8);
        case 'fast':
            $out = $all;
            usort($out, fn($a, $b) => (int) $a['t'] <=> (int) $b['t']);
            return array_slice($out, 0, 8);
        default:
            return array_slice($all, 0, 8);
    }
}
