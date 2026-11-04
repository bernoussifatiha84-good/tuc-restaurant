<?php
/**
 * Données de démonstration — site provisoire.
 *
 * ⚠️ Ce fichier est TEMPORAIRE. En production, les restaurants viennent de la
 * base WordPress (custom post type `tuc_resto`) remplie par l'OCR des menus.
 * La structure des champs est identique : c'est ce qui permet de basculer
 * sans toucher aux templates.
 */

defined('ABSPATH') || exit;

function tuc_restos(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [
        // ---------------------------------------------------- restaurants
        ['n' => 'Restaurant Alhayba', 'cu' => 'Marocain · Libanais', 'k' => 'restaurant', 'r' => 4.9, 't' => '20–35', 'd' => 2.1, 'fee' => null, 'img' => 'tagine'],
        ['n' => 'Bouchaib Grillades', 'cu' => 'Grillades · Brochettes', 'k' => 'restaurant', 'r' => 4.9, 't' => '20–30', 'd' => 1.4, 'fee' => 12, 'img' => 'grillades'],
        ['n' => 'Dar Zitoun', 'cu' => 'Marocain · Couscous', 'k' => 'restaurant', 'r' => 4.8, 't' => '25–40', 'd' => 3.2, 'fee' => null, 'img' => 'p-couscous'],
        ['n' => 'La Sqala', 'cu' => 'Marocain · Poisson', 'k' => 'restaurant', 'r' => 4.8, 't' => '25–40', 'd' => 4.0, 'fee' => 20, 'img' => 'p-friture'],
        ['n' => 'Océan Marina', 'cu' => 'Poisson frais · Friture', 'k' => 'restaurant', 'r' => 4.7, 't' => '20–35', 'd' => 2.8, 'fee' => 18, 'img' => 'fish'],
        ['n' => 'Sushi Club Anfa', 'cu' => 'Japonais · Sushi', 'k' => 'restaurant', 'r' => 4.7, 't' => '30–45', 'd' => 3.6, 'fee' => 25, 'img' => 'sushi'],
        // ---------------------------------------------------- mahlabas
        ['n' => 'Mahlaba Al Baraka', 'cu' => 'Jben · Raib · Lait cru', 'k' => 'mahlaba', 'r' => 4.9, 't' => '10–20', 'd' => 0.8, 'fee' => null, 'img' => 'p-jben'],
        ['n' => 'Mahlaba Sidi Maârouf', 'cu' => 'Fromage frais · Beurre', 'k' => 'mahlaba', 'r' => 4.8, 't' => '15–25', 'd' => 2.4, 'fee' => 10, 'img' => 'p-raib'],
        ['n' => 'Mahlaba Lalla Zahra', 'cu' => 'Raib · Amlou · Miel', 'k' => 'mahlaba', 'r' => 4.8, 't' => '10–20', 'd' => 1.1, 'fee' => null, 'img' => 'p-amlou'],
        ['n' => 'Mahlaba Al Amal', 'cu' => 'Jben · Msemen · Briouat', 'k' => 'mahlaba', 'r' => 4.7, 't' => '10–20', 'd' => 2.0, 'fee' => null, 'img' => 'p-briouat'],
        ['n' => 'Laiterie Chaabi', 'cu' => 'Lait · Œufs · Yaourt', 'k' => 'mahlaba', 'r' => 4.7, 't' => '15–25', 'd' => 1.6, 'fee' => 10, 'img' => 'p-raib'],
        // ---------------------------------------------------- snacks
        ['n' => "O'Tacos Maârif", 'cu' => 'Tacos · Fast food', 'k' => 'snack', 'r' => 4.8, 't' => '15–25', 'd' => 1.2, 'fee' => null, 'img' => 'tacos'],
        ['n' => 'Snack Benna', 'cu' => 'Tacos · Burger', 'k' => 'snack', 'r' => 4.8, 't' => '15–25', 'd' => 1.8, 'fee' => null, 'img' => 'sandwich'],
        ['n' => 'Snack Rialto', 'cu' => 'Sandwichs · Panini', 'k' => 'snack', 'r' => 4.7, 't' => '10–20', 'd' => 0.6, 'fee' => 8, 'img' => 'sandwich'],
        ['n' => 'Cucina Napoli', 'cu' => 'Pizza napolitaine', 'k' => 'snack', 'r' => 4.7, 't' => '20–35', 'd' => 2.6, 'fee' => 15, 'img' => 'pizza'],
        ['n' => 'Ctr Chicken', 'cu' => 'Poulet frit', 'k' => 'snack', 'r' => 4.7, 't' => '15–30', 'd' => 2.2, 'fee' => 12, 'img' => 'chicken'],
        ['n' => 'Burger King', 'cu' => 'Burgers', 'k' => 'snack', 'r' => 4.6, 't' => '15–25', 'd' => 2.0, 'fee' => 15, 'img' => 'burger'],
        // ---------------------------------------------------- cafés
        ['n' => 'Café Al Boustane', 'cu' => 'Café · Thé · Petit-déj', 'k' => 'cafe', 'r' => 4.8, 't' => '10–20', 'd' => 0.9, 'fee' => null, 'img' => 'p-the'],
        ['n' => 'Café Riche', 'cu' => 'Café · Snacks', 'k' => 'cafe', 'r' => 4.7, 't' => '10–20', 'd' => 1.3, 'fee' => 8, 'img' => 'p-nousnous'],
        ['n' => 'Café Al Manar', 'cu' => 'Thé à la menthe · Ghriba', 'k' => 'cafe', 'r' => 4.7, 't' => '10–25', 'd' => 2.1, 'fee' => null, 'img' => 'p-the'],
        ['n' => 'Café Atlas', 'cu' => 'Café · Jus pressés', 'k' => 'cafe', 'r' => 4.6, 't' => '10–20', 'd' => 1.7, 'fee' => 8, 'img' => 'p-nousnous'],
        // ---------------------------------------------------- coffee shops
        ['n' => 'Patti & Co', 'cu' => 'Pâtisserie · Café', 'k' => 'coffee', 'r' => 4.9, 't' => '20–35', 'd' => 2.4, 'fee' => null, 'img' => 'p-croissant'],
        ['n' => 'Bloom Coffee', 'cu' => 'Specialty · Brunch', 'k' => 'coffee', 'r' => 4.8, 't' => '20–35', 'd' => 3.0, 'fee' => 15, 'img' => 'p-latte'],
        ['n' => 'Blacksmith Coffee', 'cu' => 'Torréfaction locale', 'k' => 'coffee', 'r' => 4.8, 't' => '15–30', 'd' => 2.2, 'fee' => 12, 'img' => 'p-latte'],
        ['n' => "L'Atelier Café", 'cu' => 'Brunch · Matcha', 'k' => 'coffee', 'r' => 4.7, 't' => '20–35', 'd' => 3.4, 'fee' => 15, 'img' => 'p-croissant'],
    ];

    return $cache;
}

function tuc_promos(): array
{
    return [
        ['big' => '−44 %', 'nm' => '2 tacos + 2 boissons + 2 frites', 'shop' => "O'Tacos Maârif", 'r' => 4.8, 't' => '15–25 min', 'img' => 'tacos'],
        ['big' => '−50 %', 'nm' => 'Benna Épicé, 2e à moitié prix', 'shop' => 'Snack Benna', 'r' => 4.8, 't' => '15–25 min', 'img' => 'sandwich'],
        ['big' => '−25 %', 'nm' => '6 en 1 Box, 2 sauces offertes', 'shop' => 'Ctr Chicken', 'r' => 4.7, 't' => '15–30 min', 'img' => 'chicken'],
        ['big' => '−15 %', 'nm' => 'Toute la carte pizza', 'shop' => 'Cucina Napoli', 'r' => 4.7, 't' => '20–35 min', 'img' => 'pizza'],
        ['big' => '1+1', 'nm' => 'Whopper acheté = Whopper offert', 'shop' => 'Burger King', 'r' => 4.6, 't' => '15–25 min', 'img' => 'burger'],
        ['big' => '−30 %', 'nm' => '1 kg de jben frais du jour', 'shop' => 'Mahlaba Al Baraka', 'r' => 4.9, 't' => '10–20 min', 'img' => 'p-jben'],
        ['big' => '1+1', 'nm' => 'Brochettes de kefta', 'shop' => 'Bouchaib Grillades', 'r' => 4.9, 't' => '20–30 min', 'img' => 'grillades'],
        ['big' => '−20 %', 'nm' => 'Sur tous les cakes et tartes', 'shop' => 'Patti & Co', 'r' => 4.9, 't' => '20–35 min', 'img' => 'p-croissant'],
    ];
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
        ['id' => 'near',   'fr' => 'À moins de 2 km', 'ar' => 'أقل من 2 كم', 'type' => 'near'],
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
            $out = array_values(array_filter($all, fn($x) => $x['fee'] === null));
            usort($out, fn($a, $b) => $b['r'] <=> $a['r']);
            return array_slice($out, 0, 8);
        case 'fast':
            $out = $all;
            usort($out, fn($a, $b) => (int)$a['t'] <=> (int)$b['t']);
            return array_slice($out, 0, 8);
        case 'near':
            $out = array_values(array_filter($all, fn($x) => $x['d'] <= 2));
            usort($out, fn($a, $b) => $a['d'] <=> $b['d']);
            return array_slice($out, 0, 8);
        default:
            return array_slice($all, 0, 8);
    }
}
