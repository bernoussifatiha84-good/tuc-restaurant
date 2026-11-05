<?php
/**
 * Module 6 — Web Push natif (aucun plugin, aucune dépendance, 0 MAD).
 *
 * POURQUOI CE MODULE EXISTE
 * -------------------------
 * Le calcul de WHATSAPP-COUTS.md §7 est sans appel : à 1 000 restos × 20
 * commandes/jour, prévenir quelqu'un par message coûte 420 000 MAD/mois.
 * Le Web Push, lui, ne dépend que du nombre d'appareils abonnés — pas du
 * nombre de commandes. C'est le seul canal dont le coût ne bouffe pas la marge.
 *
 * CE QUI EST IMPLÉMENTÉ ICI
 * -------------------------
 *  - chiffrement `aes128gcm` conforme RFC 8188 + RFC 8291 (testé octet pour
 *    octet contre le vecteur de test officiel du RFC 8291 §5) ;
 *  - signature VAPID ES256 (RFC 8292) avec conversion DER → r||s ;
 *  - stockage des abonnements en user_meta (pas de table, donc rien à migrer
 *    et ça marche tel quel sur SQLite) ;
 *  - purge automatique des abonnements morts (404 / 410).
 *
 * CE QUI N'EST PAS ICI
 * --------------------
 * Aucun service tiers, aucun abonnement, aucun SDK. Le navigateur parle
 * directement au service de push du navigateur (FCM, Mozilla, Apple).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_Push
{
    /** Abonnements enregistrés, 5 max par compte (téléphone + tablette + secours). */
    public const META = 'tuc_push_abonnements';
    public const MAX_PAR_COMPTE = 5;

    /** Durée de vie d'un message poussé : 1 h. Au-delà, l'info est périmée. */
    private const TTL = 3600;

    public static function init(): void
    {
        // Les clés VAPID doivent exister AVANT le premier abonnement : un
        // navigateur abonné avec une clé, puis une clé régénérée, c'est un
        // abonnement mort silencieux. On les crée donc à la première visite.
        // (lecture d'option autoloadée : coût nul)
        if ((string) get_option('tuc_vapid_priv', '') === '') {
            self::generer_cles();
        }
    }

    // =====================================================================
    // 1. Clés VAPID
    // =====================================================================

    /**
     * Clé publique au format brut non compressé (65 octets, 0x04 || X || Y),
     * encodée base64url : c'est exactement ce que `pushManager.subscribe()`
     * attend dans `applicationServerKey`.
     */
    public static function cle_publique(): string
    {
        $pub = (string) get_option('tuc_vapid_pub', '');
        if ($pub === '') {
            self::generer_cles();
            $pub = (string) get_option('tuc_vapid_pub', '');
        }
        return $pub;
    }

    /**
     * Génère la paire VAPID une seule fois et la garde en option.
     * La clé privée ne quitte jamais le serveur ; elle n'est jamais renvoyée
     * à l'extérieur, même à un utilisateur connecté.
     */
    public static function generer_cles(): void
    {
        if ((string) get_option('tuc_vapid_priv', '') !== '') {
            return;
        }

        $res = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($res === false) {
            return;
        }

        $pem = '';
        openssl_pkey_export($res, $pem);

        $details = openssl_pkey_get_details($res);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            return;
        }

        // point non compressé : 0x04 || X(32) || Y(32)
        $point = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
                       . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        update_option('tuc_vapid_priv', $pem, false);
        update_option('tuc_vapid_pub', self::b64url_encode($point), false);
    }

    // =====================================================================
    // 2. Abonnements
    // =====================================================================

    /** Enregistre (ou rafraîchit) un abonnement pour un compte. */
    public static function abonner(int $user_id, string $endpoint, string $p256dh, string $auth): bool
    {
        if ($user_id <= 0 || $endpoint === '' || $p256dh === '' || $auth === '') {
            return false;
        }
        // validation : p256dh = 65 octets, auth = 16 octets
        if (strlen((string) self::b64url_decode($p256dh)) !== 65 || strlen((string) self::b64url_decode($auth)) !== 16) {
            return false;
        }

        $liste = self::abonnements($user_id);

        // on déduplique par endpoint : ré-abonner le même appareil ne crée pas
        // une deuxième notification
        $liste = array_values(array_filter($liste, static fn(array $a): bool => ($a['endpoint'] ?? '') !== $endpoint));
        $liste[] = ['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth, 'cree' => time()];

        // on garde les plus récents
        if (count($liste) > self::MAX_PAR_COMPTE) {
            $liste = array_slice($liste, -self::MAX_PAR_COMPTE);
        }

        update_user_meta($user_id, self::META, $liste);
        return true;
    }

    public static function desabonner(int $user_id, string $endpoint): void
    {
        $liste = array_values(array_filter(
            self::abonnements($user_id),
            static fn(array $a): bool => ($a['endpoint'] ?? '') !== $endpoint
        ));
        update_user_meta($user_id, self::META, $liste);
    }

    public static function abonnements(int $user_id): array
    {
        $l = get_user_meta($user_id, self::META, true);
        return is_array($l) ? $l : [];
    }

    /** Nombre d'appareils abonnés — sert au diagnostic de la caisse. */
    public static function nb_appareils(int $user_id): int
    {
        return count(self::abonnements($user_id));
    }

    // =====================================================================
    // 3. Envoi
    // =====================================================================

    /**
     * Envoie une notification à un compte. Ne lève jamais d'exception :
     * une notification qui échoue ne doit pas faire échouer une commande.
     *
     * @return array{envoyes:int,morts:int,erreurs:array<int,string>}
     */
    public static function envoyer(int $user_id, array $charge): array
    {
        $bilan = ['envoyes' => 0, 'morts' => 0, 'erreurs' => []];
        $abos = self::abonnements($user_id);
        if ($abos === []) {
            return $bilan;
        }

        $payload = (string) wp_json_encode($charge);
        if (strlen($payload) > 3000) {
            // au-delà on tronque : le service de push refuse au-delà de 4 Ko,
            // et une notification n'a pas besoin d'un roman
            $payload = (string) wp_json_encode(['titre' => $charge['titre'] ?? 'TUC', 'texte' => '', 'url' => $charge['url'] ?? '/']);
        }

        $morts = [];
        foreach ($abos as $abo) {
            $code = self::pousser((string) $abo['endpoint'], (string) $abo['p256dh'], (string) $abo['auth'], $payload);

            if ($code === 404 || $code === 410) {
                $morts[] = (string) $abo['endpoint'];
                $bilan['morts']++;
                continue;
            }
            if ($code >= 200 && $code < 300) {
                $bilan['envoyes']++;
            } else {
                $bilan['erreurs'][] = $code;
            }
        }

        // nettoyage : un abonnement mort ne doit pas être retenté indéfiniment
        if ($morts !== []) {
            $restants = array_values(array_filter(
                $abos,
                static fn(array $a): bool => !in_array((string) $a['endpoint'], $morts, true)
            ));
            update_user_meta($user_id, self::META, $restants);
        }

        return $bilan;
    }

    /** Un envoi bas niveau. Retourne le code HTTP (0 = échec réseau). */
    private static function pousser(string $endpoint, string $p256dh, string $auth, string $payload): int
    {
        $chiffre = self::chiffrer($payload, $p256dh, $auth);
        if ($chiffre === null) {
            return 0;
        }

        $jwt = self::jwt_vapid($endpoint);
        if ($jwt === '') {
            return 0;
        }

        $rep = wp_remote_post($endpoint, [
            'timeout'     => 6,
            'redirection' => 0,
            'blocking'    => true,
            'headers'     => [
                'Authorization'    => 'vapid t=' . $jwt . ', k=' . self::cle_publique(),
                'Content-Encoding' => 'aes128gcm',
                'Content-Type'     => 'application/octet-stream',
                'TTL'              => (string) self::TTL,
                'Urgency'          => 'high',
            ],
            'body'        => $chiffre,
        ]);

        if (is_wp_error($rep)) {
            return 0;
        }
        return (int) wp_remote_retrieve_response_code($rep);
    }

    // =====================================================================
    // 4. Chiffrement RFC 8188 / RFC 8291
    // =====================================================================

    /**
     * Chiffre une charge utile pour UN abonnement.
     *
     * En-tête du corps (aes128gcm) : salt(16) || rs(4, big-endian) || idlen(1) || keyid
     * où keyid = clé publique éphémère du serveur (65 octets).
     */
    public static function chiffrer(string $payload, string $p256dh_b64, string $auth_b64): ?string
    {
        $ua_pub = self::b64url_decode($p256dh_b64);
        $auth   = self::b64url_decode($auth_b64);
        if ($ua_pub === null || $auth === null || strlen($ua_pub) !== 65) {
            return null;
        }

        // --- paire éphémère du serveur ---
        $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($res === false) {
            return null;
        }
        $d = openssl_pkey_get_details($res);
        if (!is_array($d) || !isset($d['ec']['x'], $d['ec']['y'])) {
            return null;
        }
        $as_pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
                        . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);

        // --- ECDH ---
        $peer = openssl_pkey_get_public(self::pem_public($ua_pub));
        if ($peer === false) {
            return null;
        }
        $ecdh = openssl_pkey_derive($peer, $res, 32);
        if (!is_string($ecdh) || strlen($ecdh) !== 32) {
            return null;
        }

        // --- HKDF (RFC 8291 §3.4) ---
        $salt      = random_bytes(16);
        $prk_key   = hash_hmac('sha256', $ecdh, $auth, true);
        $key_info  = "WebPush: info\0" . $ua_pub . $as_pub;
        $ikm       = hash_hmac('sha256', $key_info . "\x01", $prk_key, true);
        $prk       = hash_hmac('sha256', $ikm, $salt, true);
        $cek       = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce     = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        // --- AES-128-GCM, un seul enregistrement, délimiteur de padding 0x02 ---
        $tag = '';
        $ct  = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ct === false) {
            return null;
        }

        return $salt . pack('N', 4096) . chr(strlen($as_pub)) . $as_pub . $ct . $tag;
    }

    // =====================================================================
    // 5. VAPID (RFC 8292)
    // =====================================================================

    /**
     * JWT ES256 pour un endpoint donné.
     * `aud` = origine du service de push (pas l'URL complète).
     */
    private static function jwt_vapid(string $endpoint): string
    {
        $priv = (string) get_option('tuc_vapid_priv', '');
        if ($priv === '') {
            return '';
        }

        $p = parse_url($endpoint);
        if (!is_array($p) || empty($p['host'])) {
            return '';
        }
        $aud = ($p['scheme'] ?? 'https') . '://' . $p['host'];

        $entete  = self::b64url_encode((string) wp_json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $charge  = self::b64url_encode((string) wp_json_encode([
            'aud' => $aud,
            'exp' => time() + 12 * HOUR_IN_SECONDS,
            'sub' => 'mailto:contact@tuc.ma',
        ]));
        $a_signer = $entete . '.' . $charge;

        $cle = openssl_pkey_get_private($priv);
        if ($cle === false) {
            return '';
        }

        $der = '';
        if (!openssl_sign($a_signer, $der, $cle, OPENSSL_ALGO_SHA256)) {
            return '';
        }

        return $a_signer . '.' . self::b64url_encode(self::der_vers_rs((string) $der));
    }

    /** ECDSA : openssl renvoie une séquence DER, JWT exige r||s sur 64 octets. */
    private static function der_vers_rs(string $der): string
    {
        $pos = 0;
        if (($der[$pos] ?? '') !== "\x30") {
            return str_pad('', 64, "\0");
        }
        $pos++;
        $len = ord($der[$pos]);
        $pos++;
        if ($len > 0x80) {
            $pos += $len - 0x80;
        }

        $lire = static function () use ($der, &$pos): string {
            if (($der[$pos] ?? '') !== "\x02") {
                return '';
            }
            $pos++;
            $n = ord($der[$pos]);
            $pos++;
            $v = substr($der, $pos, $n);
            $pos += $n;
            return $v;
        };

        $r = ltrim($lire(), "\0");
        $s = ltrim($lire(), "\0");

        return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
    }

    // =====================================================================
    // 6. Utilitaires
    // =====================================================================

    /** Enveloppe un point brut dans un SubjectPublicKeyInfo DER (P-256). */
    private static function pem_public(string $point): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function b64url_encode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function b64url_decode(string $s): ?string
    {
        $r = base64_decode(strtr(trim($s), '-_', '+/'), true);
        return $r === false ? null : $r;
    }
}
