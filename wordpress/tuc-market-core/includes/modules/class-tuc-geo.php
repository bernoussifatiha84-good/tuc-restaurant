<?php
/**
 * Module 8 — Quartiers et tri par distance.
 *
 * Règle imposée : getCurrentPosition UNE seule fois, jamais watchPosition.
 * `enableHighAccuracy:false` — le GPS précis réveille la puce et coûte cher en CPU
 * et en batterie ; à l'échelle d'un quartier, la position réseau suffit.
 *
 * Le tri se fait en PHP sur la distance au carré (pas de racine, pas de SQL spatial).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_Geo
{
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route('tuc/v1', '/quartiers', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'liste'],
        ]);
    }

    /** Table de référence des quartiers de Casablanca (20). */
    public static function quartiers(): array
    {
        return [
            ['slug' => 'maarif',      'fr' => 'Maârif',         'ar' => 'المعاريف',   'lat' => 33.5850, 'lng' => -7.6320],
            ['slug' => 'gauthier',    'fr' => 'Gauthier',       'ar' => 'غوتيي',      'lat' => 33.5900, 'lng' => -7.6280],
            ['slug' => 'racine',      'fr' => 'Racine',         'ar' => 'راسين',      'lat' => 33.5880, 'lng' => -7.6420],
            ['slug' => 'anfa',        'fr' => 'Anfa',           'ar' => 'أنفا',       'lat' => 33.5950, 'lng' => -7.6600],
            ['slug' => 'bourgogne',   'fr' => 'Bourgogne',      'ar' => 'بورغونيا',   'lat' => 33.5970, 'lng' => -7.6250],
            ['slug' => 'derb-ghallef', 'fr' => 'Derb Ghallef',  'ar' => 'درب غلف',    'lat' => 33.5770, 'lng' => -7.6380],
            ['slug' => 'sidinaarouf', 'fr' => 'Sidi Maârouf',   'ar' => 'سيدي معروف', 'lat' => 33.5350, 'lng' => -7.6600],
            ['slug' => 'hay-hassani', 'fr' => 'Hay Hassani',    'ar' => 'الحي الحسني', 'lat' => 33.5600, 'lng' => -7.6800],
            ['slug' => 'ain-diab',    'fr' => 'Aïn Diab',       'ar' => 'عين الذياب', 'lat' => 33.6000, 'lng' => -7.6900],
            ['slug' => 'oulfa',       'fr' => 'Oulfa',          'ar' => 'الألفة',     'lat' => 33.5450, 'lng' => -7.6800],
        ];
    }

    public static function liste(): WP_REST_Response
    {
        return new WP_REST_Response(self::quartiers(), 200);
    }

    /**
     * Distance au carré — suffit pour comparer, évite sqrt() sur 36 fiches.
     */
    public static function distance2(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = $lat2 - $lat1;
        $dLng = ($lng2 - $lng1) * 0.8; // approximation à la latitude du Maroc
        return $dLat * $dLat + $dLng * $dLng;
    }

    /** Trie une liste de restos par proximité, sans jamais appeler watchPosition. */
    public static function trier(array $restos, float $lat, float $lng): array
    {
        usort($restos, static function (array $a, array $b) use ($lat, $lng): int {
            $da = self::distance2($lat, $lng, (float) ($a['lat'] ?? 0), (float) ($a['lng'] ?? 0));
            $db = self::distance2($lat, $lng, (float) ($b['lat'] ?? 0), (float) ($b['lng'] ?? 0));
            return $da <=> $db;
        });
        return $restos;
    }
}
