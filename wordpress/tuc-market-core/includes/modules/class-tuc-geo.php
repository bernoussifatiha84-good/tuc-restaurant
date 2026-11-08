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

    /** Centres de référence des dix quartiers actuellement pris en charge à Casablanca. */
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
        if ($lat1 < -90 || $lat1 > 90 || $lat2 < -90 || $lat2 > 90
            || $lng1 < -180 || $lng1 > 180 || $lng2 < -180 || $lng2 > 180) {
            return INF;
        }
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = $phi2 - $phi1;
        $dLambda = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        $distance = 6_371_000 * 2 * atan2(sqrt(min(1.0, $a)), sqrt(max(0.0, 1.0 - $a)));
        return $distance * $distance;
    }

    /** Trie une liste de restos par proximité, sans jamais appeler watchPosition. */
    public static function trier(array $restos, float $lat, float $lng): array
    {
        $restos = array_values($restos);
        usort($restos, static function (array $a, array $b) use ($lat, $lng): int {
            $coordinates = static function (array $resto): ?array {
                $rLat = $resto['lat'] ?? null;
                $rLng = $resto['lng'] ?? null;
                if (!is_numeric($rLat) || !is_numeric($rLng)
                    || (float) $rLat < -90 || (float) $rLat > 90
                    || (float) $rLng < -180 || (float) $rLng > 180) {
                    return null;
                }
                return [(float) $rLat, (float) $rLng];
            };
            $aCoordinates = $coordinates($a);
            $bCoordinates = $coordinates($b);
            if ($aCoordinates === null || $bCoordinates === null) {
                return $aCoordinates === $bCoordinates ? 0 : ($aCoordinates === null ? 1 : -1);
            }
            return self::distance2($lat, $lng, $aCoordinates[0], $aCoordinates[1])
                <=> self::distance2($lat, $lng, $bCoordinates[0], $bCoordinates[1]);
        });
        return $restos;
    }
}
