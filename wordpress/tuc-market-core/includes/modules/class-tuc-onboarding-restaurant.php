<?php
/** Assisted restaurant onboarding: short public form, WhatsApp handoff, staff provisioning. */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_Onboarding_Restaurant
{
    public const CPT = 'tuc_demande_resto';
    private const MAX_PHOTO_BYTES = 5_242_880;

    /**
     * PHOTOS iPhone : HEIC/HEIF.
     *
     * Mesuré sur l'hébergement AVANT d'écrire ce code, pas déduit de
     * `queryFormats()` : un vrai fichier HEIC de 718 Ko (« ISO Media, HEIF
     * Image ») décodé par Imagick 7.1.2-34 en 1280x854, converti en JPEG.
     * GD ne sait pas lire le HEIC ; Imagick si. Si Imagick disparaît de
     * l'hébergement, le code retombe sur un refus explicite, jamais sur un
     * fatal.
     *
     * Bornes : 40 MP (au-delà, c'est une bombe de décompression, pas une photo),
     * 128 Mo de mémoire et de map pour le décodeur, 20 s de calcul.
     */
    private const MAX_PIXELS = 40_000_000;
    private const MAX_COTE_JPEG = 1600;
    private const QUALITE_JPEG = 85;
    private const PAGE_SLUG = 'devenir-restaurateur';

    public static function init(): void
    {
        add_action('init', [self::class, 'register_cpt'], 3);
        add_action('init', [self::class, 'ensure_page'], 25);
        add_shortcode('tuc_demande_restaurant', [self::class, 'render_form']);
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_tuc_provisionner_restaurant', [self::class, 'provisionner_admin']);
        add_action('admin_post_tuc_renvoyer_acces_restaurant', [self::class, 'renvoyer_acces_admin']);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
    }

    public static function register_cpt(): void
    {
        register_post_type(self::CPT, [
            'labels' => ['name' => 'Demandes restaurants', 'singular_name' => 'Demande restaurant'],
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    /** Create the public form page once; it contains no account or contact fields. */
    public static function ensure_page(): void
    {
        $pageId = (int) get_option('tuc_onboarding_page_id', 0);
        if ($pageId > 0 && get_post_status($pageId) === 'publish') {
            return;
        }
        $existing = get_page_by_path(self::PAGE_SLUG, OBJECT, 'page');
        if ($existing instanceof WP_Post) {
            update_option('tuc_onboarding_page_id', $existing->ID, false);
            return;
        }
        $created = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Rejoindre TUC comme restaurant',
            'post_name' => self::PAGE_SLUG,
            'post_content' => '[tuc_demande_restaurant]',
            'comment_status' => 'closed',
        ], true);
        if (!is_wp_error($created) && $created > 0) {
            update_option('tuc_onboarding_page_id', (int) $created, false);
        }
    }

    public static function routes(): void
    {
        register_rest_route('tuc/v1', '/restaurant-applications', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'soumettre'],
        ]);
    }

    public static function assets(): void
    {
        if (!is_singular('page') || !has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'tuc_demande_restaurant')) {
            return;
        }
        $base = TUC_CORE_URL . 'assets/public/';
        wp_enqueue_style('tuc-onboarding-restaurant', $base . 'restaurant-onboarding.css', [], TUC_CORE_VERSION);
        wp_enqueue_script('tuc-onboarding-restaurant', $base . 'restaurant-onboarding.js', [], TUC_CORE_VERSION, true);
    }

    public static function render_form(): string
    {
        ob_start(); ?>
        <div class="tuc-onboarding" lang="fr">
          <section class="tuc-onboarding-card" aria-labelledby="tuc-onboarding-title">
            <p class="tuc-onboarding-kicker">TUC · Casablanca</p>
            <h1 id="tuc-onboarding-title">Votre restaurant, accompagné par notre équipe</h1>
            <p class="tuc-onboarding-intro">Commencez simplement. Envoyez le nom de votre restaurant et une photo de votre menu. Notre équipe vous contactera sur WhatsApp pour préparer votre espace et vos accès.</p>
            <form id="tuc-restaurant-onboarding-form" enctype="multipart/form-data" data-rest="<?php echo esc_url(rest_url('tuc/v1/')); ?>">
              <div class="tuc-onboarding-field">
                <label for="tuc-onboarding-name">Nom du restaurant</label>
                <input id="tuc-onboarding-name" name="restaurant_name" type="text" maxlength="120" minlength="2" autocomplete="organization" dir="auto" lang="fr" required aria-describedby="tuc-onboarding-name-help">
                <small id="tuc-onboarding-name-help">En français ou en arabe.</small>
              </div>
              <div class="tuc-onboarding-field">
                <label for="tuc-onboarding-menu-photo">Photo du menu</label>
                <input id="tuc-onboarding-menu-photo" name="menu_photo" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" required aria-describedby="tuc-onboarding-photo-help">
                <small id="tuc-onboarding-photo-help">JPEG, PNG, WebP ou photo d’iPhone (HEIC) · 5 Mo maximum · une photo nette suffit.</small>
              </div>
              <button class="tuc-onboarding-submit" type="submit">Demander l’accompagnement</button>
            </form>
            <p id="tuc-onboarding-status" class="tuc-onboarding-status" role="status" aria-live="polite"></p>
            <div id="tuc-onboarding-whatsapp" class="tuc-onboarding-whatsapp" hidden>
              <p>Votre demande est enregistrée. Appuyez sur le bouton, puis envoyez le message prérempli dans WhatsApp pour que notre équipe puisse vous répondre.</p>
              <a id="tuc-onboarding-wa-link" class="tuc-onboarding-submit" href="#" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" aria-label="Continuer dans WhatsApp (s’ouvre dans un nouvel onglet)">Continuer dans WhatsApp</a>
              <p id="tuc-onboarding-wa-confirmed" class="tuc-onboarding-confirmed" hidden>WhatsApp confirmé. Notre équipe vous accompagnera pour créer votre espace et vous transmettre un lien d’accès personnel.</p>
            </div>
            <p class="tuc-onboarding-privacy">Aucun mot de passe, e-mail ou numéro n’est demandé dans ce formulaire. Votre message WhatsApp est envoyé par vous; TUC ne l’envoie pas automatiquement.</p>
          </section>
        </div>
        <?php return (string) ob_get_clean();
    }

    /**
     * Convertit un HEIC/HEIF en JPEG, ou rend le chemin d'origine inchangé.
     *
     * Rend ['chemin' => …, 'nom' => …, 'temporaire' => …] ou un WP_Error.
     * `temporaire` vaut '' quand aucun fichier n'a été créé : l'appelant ne doit
     * supprimer que ce qu'il a créé, jamais le fichier téléversé par PHP.
     *
     * Détection par le CONTENU (ftyp…heic/heif/mif1), pas par l'extension : un
     * fichier renommé .heic qui n'en est pas un repart vers la validation
     * normale et sera refusé là-bas, comme avant.
     */
    private static function convertir_en_jpeg(string $chemin, string $nom): array|WP_Error
    {
        // Aiguillage + dépendance `wp_tempnam()` : déplacés dans
        // tuc_heic_aiguillage() (includes/class-tuc-heic.php) pour être
        // vérifiables même sur une image sans décodeur HEIC. Le contrat
        // parcours-restaurateur (cas 46-48 d'origine) les couvre toujours.
        $aiguillage = tuc_heic_aiguillage($chemin, $nom);
        if (is_wp_error($aiguillage) || empty($aiguillage['heif'])) {
            return $aiguillage;
        }

        try {
            $im = new Imagick();
            $im->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 128 * 1024 * 1024);
            $im->setResourceLimit(Imagick::RESOURCETYPE_MAP, 128 * 1024 * 1024);
            $im->setResourceLimit(Imagick::RESOURCETYPE_AREA, self::MAX_PIXELS);
            $im->setResourceLimit(Imagick::RESOURCETYPE_TIME, 20);

            $im->readImage($chemin . '[0]'); // première image seulement

            $geo = $im->getImageGeometry();
            if ((int) $geo['width'] * (int) $geo['height'] > self::MAX_PIXELS) {
                $im->clear();
                return new WP_Error(
                    'tuc_onboarding_photo_dimensions',
                    __('Cette photo est trop grande. Envoyez une photo de moins de 40 mégapixels.', 'tuc-core'),
                    ['status' => 413]
                );
            }

            // EXIF traité dans l'ordre : orientation d'abord (sinon une photo prise
            // en portrait ressort couchée), puis strip — qui efface AUSSI la
            // position GPS. Une carte de menu n'a aucune raison de porter les
            // coordonnées du restaurant.
            $im->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $im->autoOrient();
            $im->stripImage();
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(self::QUALITE_JPEG);
            // `thumbnailImage()` AGRANDIT quand la source est plus petite que la
            // boîte (mesuré : 1200x900 ressortait en 1600x1200 puis 1600x1600
            // avec `fill`). On ne l'appelle donc QUE pour réduire : une carte de
            // menu ne doit jamais être agrandie, c'est de la taille de fichier
            // pour rien et de la qualité perdue.
            if ((int) $geo['width'] > self::MAX_COTE_JPEG || (int) $geo['height'] > self::MAX_COTE_JPEG) {
                $im->thumbnailImage(self::MAX_COTE_JPEG, self::MAX_COTE_JPEG, true, false);
            }

            // `wp_tempnam()` est chargé par tuc_heic_aiguillage() AVANT d'arriver
            // ici (le front-end REST ne charge pas wp-admin/includes/file.php).
            // Sans lui : « Call to undefined function wp_tempnam() », attrapé par
            // le catch, et un 415 trompeur alors qu'Imagick convertit très bien.
            $temporaire = wp_tempnam('tuc-heic-');
            if (!is_string($temporaire) || $temporaire === '') {
                $im->clear();
                return new WP_Error('tuc_onboarding_photo_heic', __('La conversion de la photo a échoué.', 'tuc-core'), ['status' => 500]);
            }
            // PIÈGE MESURÉ : `wp_tempnam()` CRÉE un fichier vide, et `writeImage()`
            // sur un fichier vide préexistant écrit 0 octet sans lever d'erreur
            // (vérifié : « writeImage sur fichier vide existant -> OK (0 o) »).
            // On supprime donc le placeholder avant d'écrire, sinon la conversion
            // « réussit » en produisant un fichier vide.
            if (is_file($temporaire)) {
                @unlink($temporaire);
            }
            $im->writeImage($temporaire);
            $im->clear();
            $im->destroy();

            if (!is_file($temporaire) || filesize($temporaire) === 0) {
                @unlink($temporaire);
                return new WP_Error('tuc_onboarding_photo_heic', __('La conversion de la photo a échoué.', 'tuc-core'), ['status' => 500]);
            }

            $nomJpeg = (string) preg_replace('/\.[a-z0-9]+$/i', '', $nom) . '.jpg';
            return ['chemin' => $temporaire, 'nom' => $nomJpeg, 'temporaire' => $temporaire];
        } catch (Throwable $e) {
            error_log('TUC onboarding : conversion HEIC échouée — ' . $e->getMessage());
            return new WP_Error(
                'tuc_onboarding_photo_heic',
                __('Cette photo HEIC n’a pas pu être convertie. Essayez une autre photo, ou partagez-la en JPEG depuis votre iPhone.', 'tuc-core'),
                ['status' => 415]
            );
        }
    }

    /**
     * DEUX compteurs, pas un.
     *
     * POURQUOI. L'ancienne version n'avait qu'un compteur, incrémenté AVANT toute
     * validation : une photo refusée ou un nom trop court consommait un des trois
     * crédits horaires, et un restaurateur qui se trompait deux fois était bloqué
     * une heure avec le message d'un spammeur.
     *
     * Ce qui est séparé ici :
     *   - TENTATIVES : toute requête qui atteint la route, valide ou non. Large,
     *     anti-abus. Ne donne aucun droit, ne bloque que l'acharnement.
     *   - DEMANDES   : seulement les soumissions qui ont passé TOUTES les
     *     validations et créé une demande. Strict.
     *
     * CONSÉQUENCE : trois erreurs de saisie ne mangent plus les places des vraies
     * demandes ; et une erreur ne peut pas non plus servir d'arme (le compteur de
     * tentatives la borne).
     *
     * CE QUI RESTE NON RÉSOLU, ET IL FAUT LE DIRE. La clé est `REMOTE_ADDR`, qui
     * derrière le CDN Hostinger est l'adresse de sortie du client (mesuré : deux
     * sources réseau distinctes ont été vues avec deux adresses distinctes). Ce
     * n'est donc PAS « tout Internet partage une adresse ». Mais des clients
     * derrière un même NAT partagent la leur, et `REMOTE_ADDR` peut être celle du
     * proxy si la configuration du CDN change. `X-Forwarded-For` n'est JAMAIS lu
     * ici : il est falsifiable par le client (mesuré) et aucune liste de proxys de
     * confiance n'est déclarée sur ce site.
     */

    /** Tentatives par heure et par adresse. Large : c'est l'anti-abus. */
    private const MAX_TENTATIVES_PAR_HEURE = 20;

    /**
     * Rétention des lignes de compteur et de verrou (secondes).
     * Une ligne périmée depuis plus de 24 h ne protège plus rien : on la
     * supprime. Sans ce balayage, chaque nouvelle IP laisserait une ligne
     * pour toujours (elles ne sont que « logiquement » expirées).
     */
    private const RETENTION_COMPTEUR = 86_400;

    /** Probabilité de balayage par requête (1 sur 20), pour ne pas payer à chaque fois. */
    private const PURGE_UN_SUR = 20;


    /** Demandes créées par heure et par adresse. Strict : c'est l'anti-spam. */
    private const MAX_DEMANDES_PAR_HEURE = 3;

    /**
     * Lit un compteur à fenêtre fixe. Rend [compte, secondes_restantes].
     *
     * En cas d'ERREUR DE LECTURE, rend [0, 0] : l'appelant ne peut alors pas
     * distinguer « compteur vide » d'« erreur ». C'est volontaire et c'est
     * l'appelant qui tranche, via `derniere_erreur_sql()` : un quota calculé sur
     * une lecture ratée refuserait ou laisserait passer au hasard.
     *
     * @return array{0:int,1:int}
     */
    private static function lire_compteur(string $cle): array
    {
        // SQL direct, comme le verrou : un transient passe par le cache objet,
        // et un cache persistant peut servir un compte périmé.
        return self::lire_valeur(self::lire_brut($cle));
    }

    /** Décode une valeur de compteur. Rend [compte, secondes_restantes].
     *
     * Accepte les deux formats : « compte|fin|génération|séquence » (courant) et
     * l'ancien « compte|fin|séquence » (trois champs, lu comme : génération =
     * fin - HOUR_IN_SECONDS). Voir `composantes()`.
     */
    private static function lire_valeur(?string $valeur): array
    {
        if ($valeur === null) {
            return [0, 0];
        }
        $c = self::composantes($valeur);
        $restant = $c['fin'] - time();
        if ($c['compte'] <= 0 || $restant <= 0) {
            return [0, 0];
        }
        return [$c['compte'], $restant];
    }

    /**
     * Composantes d'une valeur de compteur.
     *
     * FORMAT COURANT : « compte|fin|génération|séquence ».
     *
     * POURQUOI LA GÉNÉRATION (revue n°6, point 1). Une ligne supprimée puis
     * recréée repartait à « 1|<même fin>|1 » : la valeur complète pouvait donc
     * redevenir IDENTIQUE, et une écriture conditionnelle préparée AVANT la
     * suppression redevenait acceptable — un ABA par recréation. Un horodatage
     * ne suffit pas (deux créations dans la même seconde donnent la même
     * valeur, le test l'a montré). La valeur porte donc une GÉNÉRATION issue
     * d'un compteur global strictement croissant, qui ne recule ni ne se
     * recycle : deux créations de la même clé n'ont jamais la même génération,
     * même séparées d'une milliseconde ou après suppression.
     */
    private static function composantes(string $valeur): array
    {
        $p = explode('|', $valeur);
        if (count($p) === 4) {
            return [
                'compte'     => (int) ($p[0] ?? 0),
                'fin'        => (int) ($p[1] ?? 0),
                'generation' => (int) ($p[2] ?? 0),
                'sequence'   => (int) ($p[3] ?? 0),
            ];
        }
        return [
            'compte'     => (int) ($p[0] ?? 0),
            'fin'        => (int) ($p[1] ?? 0),
            'generation' => (int) ($p[1] ?? 0) - HOUR_IN_SECONDS, // ancien format
            'sequence'   => (int) ($p[2] ?? 0),
        ];
    }

    /** Génération portée par une valeur de compteur. */
    private static function generation_de(string $valeur): int
    {
        return self::composantes($valeur)['generation'];
    }

    /** Ligne du compteur global de générations : une seule pour tout le site. */
    private const OPTION_GENERATION = 'tuc_purge_generation';

    /** Tentatives d'allocation du compteur global avant de REFUSER (aucun repli). */
    // 10 reprises perdaient trop souvent la ligne unique sous la rafale mesurée
    // en CI (25 refus sur 144 réservations). 20 reprises avec jitter, ça reste
    // borné (une requête de plus, jamais une écriture aveugle).
    private const GENERATION_TENTATIVES = 20;

    /** Reprises d'écriture d'un compteur quand l'erreur est un conflit récupérable. */
    private const COMPTEUR_TENTATIVES = 3;

    /** Reprises de la SECTION CRITIQUE ENTIÈRE quand un deadlock a tué la transaction. */
    private const CRITIQUE_TENTATIVES = 5;

    /**
     * Rend la génération suivante. Strictement croissante, jamais réutilisée,
     * y compris après suppression de la ligne du compteur : c'est ce qui rend
     * le retour à une valeur identique impossible.
     *
     * Écriture conditionnelle en boucle bornée (portable MariaDB/SQLite, pas de
     * LAST_INSERT_ID ni de séquence propre à un moteur).
     */
    private static function prochaine_generation(?int $tentatives = null): ?int
    {
        global $wpdb;
        $tentatives = $tentatives ?? self::GENERATION_TENTATIVES;
        self::$generationConflit = false;

        // Création de la ligne du compteur global (une fois). Son échec faute de
        // place ou de droits doit être VU, pas transformé en identité de secours.
        $creation = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::OPTION_GENERATION,
            '0'
        ));
        if ($creation === false) {
            // SQL refusé : on refuse l'opération, on n'invente rien. Reste à
            // savoir SI la base invite à rejouer : « Deadlock found … try
            // restarting » ou « Record has changed » ne sont pas une panne.
            self::$generationConflit = self::erreur_recuperable((string) $wpdb->last_error);
            return null;
        }

        for ($essai = 0; $essai < $tentatives; $essai++) {
            $brut = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
                self::OPTION_GENERATION
            ));
            if ($brut === null) {
                // Ligne disparue entre-temps (ou SELECT refusé) : on ne fabrique
                // pas de valeur, on échoue. Mais « disparue entre-temps » est une
                // fenêtre de concurrence avec la purge, pas une panne : la
                // passe suivante relignera le compteur.
                self::$generationConflit = true;
                return null;
            }
            $actuel = (int) $brut;
            $suivant = $actuel + 1;
            $touche = $wpdb->query($wpdb->prepare(
                "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND option_value = %s",
                (string) $suivant,
                self::OPTION_GENERATION,
                (string) $brut
            ));
            if ((int) $touche === 1) {
                self::purger_cache_option(self::OPTION_GENERATION);
                return $suivant;
            }

            if ($touche === false) {
                // -----------------------------------------------------------------
                // DÉFAUT MESURÉ les 2026-11-03 et 2026-11-10 (bac + CI, 12 IP
                // distinctes en rafale) : cette branche rendait `null`, donc un
                // 503 « service indisponible » à l'utilisateur. Or `false` ici
                // n'est PAS une panne : c'est MariaDB qui signale une collision
                // de mise à jour optimiste, ou un DEADLOCK entre deux
                // transactions concurrentes sur la même ligne.
                //   « Record has changed since last read in table 'wp_options' »
                //   « Deadlock found when trying to get lock; try restarting »
                // Le message SQL dit lui-même « try restarting transaction ».
                // Ce sont des conflits RÉCUPÉRABLES : on relit et on réessaie,
                // dans la limite de GENERATION_TENTATIVES (10, déjà en place).
                // -----------------------------------------------------------------
                if (!self::erreur_recuperable((string) $wpdb->last_error)) {
                    self::$generationConflit = false;
                    return null; // vraie panne (droits, table absente...) : refus
                }
                // -----------------------------------------------------------------
                // NE JAMAIS RÉESSAYER DANS UNE TRANSACTION MORTE.
                // Mesuré (bac, 2026-11-03) : après un deadlock, `@@in_transaction`
                // vaut 0 et tout s'exécute en AUTOCOMMIT. Réessayer ici validait
                // donc immédiatement l'écriture du compteur, puis la reprise de la
                // section critique en écrivait une seconde : DEUX crédits pour une
                // seule demande (133 compteurs à 2 sur 300, constatés).
                // On signale la perte et on laisse l'appelant rejouer la section
                // ENTIÈRE dans une transaction neuve.
                // -----------------------------------------------------------------
                if (!self::dans_une_transaction()) {
                    self::$generationConflit = true;
                    self::$transactionPerdue = true;
                    return null;
                }
                self::oublier_erreur_sql(); // ne pas laisser une trace périmée
                usleep(rand(2, 12) * 1000); // désynchronise les reprises (jitter)
                continue;
            }
            // 0 ligne touchée : un autre processus a pris ce numéro. On relit.
        }
        // Épuisement des tentatives : AUCUN repli horodaté (deux créations dans
        // la même seconde partageraient la même valeur — mesuré). null = refus.
        // Nature du refus : 20 collisions perdues sur la LIGNE UNIQUE du
        // compteur global, par une rafale de processus qui veulent tous un
        // numéro. C'est de l'affluence, pas une panne — à rejouer.
        self::$generationConflit = true;
        return null;
    }

    /** Deadlock (ou attente de verrou expirée) : la seule erreur qui justifie une reprise immédiate. */
    private static function erreur_deadlock(string $message): bool
    {
        return $message !== ''
            && (stripos($message, 'Deadlock found') !== false
                || stripos($message, 'try restarting transaction') !== false
                || stripos($message, 'Lock wait timeout exceeded') !== false);
    }

    /**
     * Cette erreur SQL est-elle un conflit de concurrence RÉCUPÉRABLE ?
     *
     * Volontairement restrictif : trois motifs connus, rien d'autre. Une vraie
     * panne (table absente, droits refusés, disque plein, « MySQL server has
     * gone away ») n'est PAS récupérable : elle doit continuer à produire un
     * refus propre, jamais une boucle de reprise qui masquerait la panne.
     */
    private static function erreur_recuperable(string $message): bool
    {
        if ($message === '') {
            return false;
        }
        foreach ([
            'Record has changed since last read', // CAS : ligne modifiée entre SELECT et UPDATE
            'Deadlock found',                      // deux transactions qui se croisent
            'try restarting transaction',          // formulation MariaDB du deadlock
            'Lock wait timeout exceeded',          // attente de verrou trop longue
        ] as $motif) {
            if (stripos($message, $motif) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Écrit un compteur. Le résultat est VÉRIFIÉ en base : on ne suppose jamais
     * qu'un UPDATE a fait ce qu'on lui demandait.
     *
     * FORMAT DE LA VALEUR : « compte|fin|génération|séquence ». La séquence est incrémentée
     * à CHAQUE écriture et fait partie de la valeur comparée par le CAS. C'est
     * ce qui règle le cas ABA (retour à la même valeur après d'autres écritures)
     * SANS seconde vérification après coup.
     *
     * TROIS MODES, et aucun ne peut écraser une écriture concurrente :
     *
     * 1. `$attendu === null` (création) : **un seul INSERT IGNORE**, jamais
     *    suivi d'un UPDATE. Si la ligne existait déjà, on rend false : « je n'ai
     *    pas créé ». Un créateur concurrent ne peut donc pas écraser celui qui a
     *    gagné — c'était le défaut signalé par la revue n°5 (INSERT puis UPDATE
     *    sans condition).
     *
     * 2. `$attendu !== null` : UPDATE CONDITIONNEL sur la valeur complète. Si la
     *    ligne a disparu, **aucun INSERT** n'est tenté : on rend false.
     *
     * REND `null` QUAND LA GÉNÉRATION N'A PAS PU ÊTRE ALLOUÉE (revue n°7) :
     * c'est un refus, pas un échec de course. Les appelants le traitent comme
     * une erreur de stockage (503) et n'acceptent aucune demande.
     */
    private static function ecrire_compteur(string $cle, int $compte, int $fin, ?string $attendu = null, ?int $generation = null, ?callable $annuler = null): ?bool
    {
        $etat = self::tenter_ecrire_compteur($cle, $compte, $fin, $attendu, $generation, $annuler);
        if ($etat === 'conflit') {
            // Contrat (cas 44) : une écriture refusée par la base rend false,
            // et l'appelant rejoue la section critique.
            //
            // DÉFAUT MESURÉ le 2026-11-10 (CI, run sur 660aa2c) : cette branche
            // posait `$refusParPanne = true`, donc un DEADLOCK — conflit
            // transitoire par excellence, que la base invite elle-même à
            // rejouer — remontait en 503 `tuc_onboarding_storage`. 23 refus
            // 503 sur 96 réservations d'IP distinctes, 27 sur 144 en rafale,
            // alors qu'AUCUNE unité de quota n'était consommée à tort.
            // Un conflit transitoire n'est pas une panne : il doit inviter à
            // réessayer (`tuc_onboarding_occupe`), jamais annoncer une
            // indisponibilité. Invariant rappelé par le donneur d'ordre depuis
            // la revue n°5, et violé ici.
            self::$refusParPanne = false;
            self::$causeRefus = 'conflits';
            return false;
        }
        if ($etat === null) {
            self::$refusParPanne = self::$causeRefus !== 'generation';
            return self::$causeRefus === 'generation' ? null : false;
        }
        return $etat;
    }

    /** @return bool|string|null true, false (course), null (refus), 'conflit' (réessayable) */
    private static function tenter_ecrire_compteur(string $cle, int $compte, int $fin, ?string $attendu = null, ?int $generation = null, ?callable $annuler = null): bool|string|null
    {
        global $wpdb;

        if ($attendu === null) {
            // Création : un seul INSERT, atomique. Rien d'autre.
            // GÉNÉRATION : identité neuve à chaque création, même après
            // suppression (ABA par recréation, revue n°6). Si elle ne peut pas
            // être allouée DE FAÇON UNIQUE, on rend null : l'appelant refuse
            // l'opération. Aucun repli horodaté (revue n°7 : deux créations dans
            // la même seconde partageraient la même valeur).
            if ($generation === null) {
                $generation = self::prochaine_generation();
                if ($generation === null) {
                    // REFUS PROPRE : on ne crée rien, et on annule la réservation
                    // faite plus tôt dans la même section critique (le compteur
                    // de demandes ne doit pas être consommé par un refus).
                    if ($annuler !== null) {
                        $annuler();
                    }
                    if (self::$generationConflit) {
                        // Collision passagère : l'appelant rejoue, et le client
                        // reçoit « momentanément occupé » avec `retry`, jamais
                        // « service indisponible ».
                        self::$causeRefus = 'conflits';
                        return 'conflit';
                    }
                    self::$causeRefus = 'generation';
                    return null;
                }
            }
            $valeur = $compte . '|' . $fin . '|' . $generation . '|1';
            $insert = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $cle,
                $valeur
            ));
            self::purger_cache_option($cle);
            if ($insert === false) {
                $message = (string) $wpdb->last_error;
                self::oublier_erreur_sql();
                self::$derniereErreurSql = $message;
                self::$causeRefus = 'sql';
                return self::erreur_recuperable($message) ? 'conflit' : null;
            }
            if ((int) $insert !== 1) {
                return false; // la ligne existe déjà : ce n'est pas nous qui l'avons créée
            }
            return self::relire_egal($cle, $valeur);
        }

        // Conditionnel : aucun INSERT, jamais. Si la ligne n'existe plus, échec.
        // La GÉNÉRATION est RECOPIÉE telle quelle : une écriture ne peut pas
        // « rajeunir » le compteur, elle atteste au contraire de son identité.
        $valeur = $compte . '|' . $fin . '|' . self::generation_de($attendu) . '|' . (self::sequence_dans($attendu) + 1);
        $touche = $wpdb->query($wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $valeur,
            $cle,
            $attendu
        ));
        self::purger_cache_option($cle);
        if ($touche === false) {
            // Erreur SQL. DEUX cas très différents :
            //   - conflit récupérable (deadlock, collision optimiste) : on rend
            //     'conflit', l'appelant relit et recommence ;
            //   - vraie panne (droits, table, disque) : on rend NULL, donc refus.
            // Dans les deux cas on efface le message : sinon la barrière
            // appelante (`derniere_erreur_sql()`) le relirait comme une panne.
            $message = (string) $wpdb->last_error;
            self::oublier_erreur_sql();
            self::$derniereErreurSql = $message;
            self::$causeRefus = 'sql';
            return self::erreur_recuperable($message) ? 'conflit' : null;
        }
        if ((int) $touche !== 1) {
            return false; // ligne disparue ou état changé : on n'écrit rien
        }
        return self::relire_egal($cle, $valeur);
    }

    /** Séquence portée par une valeur de compteur (dernier champ). */
    private static function sequence_dans(string $valeur): int
    {
        return self::composantes($valeur)['sequence'];
    }

    /** Séquence de la ligne en base pour ce compteur (0 si absente ou illisible). */
    private static function sequence_de(string $cle): int
    {
        $brut = self::lire_brut($cle);
        return $brut === null ? 0 : self::sequence_dans($brut);
    }

    /**
     * Valeur brute d'une ligne d'option, sans cache.
     *
     */
    private static function lire_brut(string $cle): ?string
    {
        global $wpdb;
        $valeur = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
            $cle
        ));
        return $valeur === null ? null : (string) $valeur;
    }



    private static function ligne_existe(string $cle): bool
    {
        return self::lire_brut($cle) !== null;
    }

    private static function relire_egal(string $cle, string $valeur): bool
    {
        // Comparaison sur la ligne de CETTE clé : une valeur héritée ne doit pas
        // faire croire que notre écriture a réussi.
        return self::lire_brut($cle) === $valeur;
    }

    /** Remet un compteur à zéro (SQL direct + purge). Rend true si la ligne a disparu. */
    private static function supprimer_compteur(string $cle): bool
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->options}` WHERE option_name = %s",
            $cle
        ));
        self::purger_cache_option($cle);
        return !self::ligne_existe($cle);
    }

    /**
     * La dernière opération SQL a-t-elle échoué ? On remet `last_error` à zéro
     * AVANT chaque opération protégée, sinon un `$wpdb->last_error` laissé par
     * une requête précédente ferait échouer du travail correct.
     */
    private static function derniere_erreur_sql(): string
    {
        global $wpdb;
        return trim((string) $wpdb->last_error);
    }

    private static function oublier_erreur_sql(): void
    {
        global $wpdb;
        $wpdb->last_error = '';
    }

    /**
     * VERROU — et pourquoi il est écrit en SQL direct.
     *
     * Trois défauts trouvés en testant ce que la revue demandait :
     *
     * 1. La LECTURE du compteur et la DÉCISION étaient hors du verrou : seule
     *    l'écriture était protégée. Deux requêtes pouvaient lire « 2 », décider
     *    toutes les deux « 2 < 3, j'y vais », et n'être sérialisées qu'au moment
     *    d'écrire. Il faut une SECTION CRITIQUE qui englobe lire + décider +
     *    écrire — c'est ce que fait `sous_verrou()`.
     *
     * 2. `delete_option($nom)` supprime PAR NOM : un preneur qui avait perdu son
     *    verrou (repris après expiration) effaçait celui du nouveau preneur.
     *    D'où un JETON unique par prise et des opérations COMPARE-AND-SET :
     *    on ne libère ou ne reprend que si la valeur en base est encore la nôtre.
     *
     * 3. `add_option()`/`get_option()` passent par le cache objet. Avec un cache
     *    persistant (Redis, Memcached), deux processus peuvent lire un état
     *    périmé sans jamais toucher la base : le verrou devient décoratif.
     *    D'où le SQL direct ci-dessous — `INSERT IGNORE` sur l'index UNIQUE de
     *    `option_name`, et `UPDATE ... WHERE option_value = <notre jeton>`.
     *    Ces opérations sont atomiques en InnoDB comme en SQLite, avec ou sans
     *    cache objet persistant.
     *
     * PORTÉE ASSUMÉE : verrou par processus sur UN serveur de base. Ce n'est pas
     * un verrou distribué et il ne prétend pas l'être.
     */

    /** Durée au-delà de laquelle un verrou est considéré orphelin (process tué). */
    private const VERROU_TTL = 5;

    /** Une transaction est-elle ouverte par `sous_verrou()` ? */
    private static bool $transactionOuverte = false;

    /** Le dernier `sous_verrou()` a-t-il perdu sa transaction (deadlock) ? */
    private static bool $transactionPerdue = false;

    /**
     * L'allocation d'une GÉNÉRATION a-t-elle échoué sur une COLLISION passagère
     * (ligne du compteur global disparue, 20 reprises toutes perdues, base qui
     * invite elle-même à rejouer) ? Dans ce cas le refus n'est pas une panne du
     * service et doit être classé « à rejouer ». Fanion remis à false à chaque
     * appel, donc jamais hérité d'une section précédente.
     *
     * Mesuré en CI (run sur 544b5e9) : 12 refus 503 sur 96 IP distinctes et 25
     * sur 144 en rafale, tous publiés `cause=generation perdue=0 panne=1` avec
     * un message SQL VIDE — le motif exact de l'épuisement des reprises, que les
     * quatre corrections précédentes ne couvraient pas.
     */
    private static bool $generationConflit = false;

    /** Dernier message SQL vu par les écritures de compteur (pour la reprise). */
    private static string $derniereErreurSql = '';

    /**
     * Cause du dernier refus `null` : true = VRAIE PANNE (SQL, allocation
     * impossible), false = reprises épuisées sur des conflits transitoires.
     * C'est ce qui permet de rendre 503 `storage` dans un cas et l'invitation
     * à réessayer (`occupe`) dans l'autre, sans jamais confondre les deux.
     */
    private static bool $refusParPanne = false;

    /** Cause du dernier refus : 'generation' (identité non allouable), 'sql' (panne), 'conflits' (reprises épuisées). */
    private static string $causeRefus = '';

    /**
     * Exécute `$action` en section critique. Rend null si le verrou n'a pas pu
     * être pris — l'appelant décide alors de refuser plutôt que de laisser passer.
     *
     * @template T
     * @param callable():T $action
     * @return array{0:bool,1:mixed} [verrou_pris, résultat]
     */
    private static function sous_verrou(string $cle, callable $action): array
    {
        $nom = $cle . '_verrou';
        $jeton = self::prendre_verrou($nom);
        if ($jeton === null) {
            return [false, null, false];
        }
        $resultat = null;
        $garde = false;
        $annuler = false; // l'action demande un ROLLBACK (refus sans trace)
        self::$transactionPerdue = false;
        $transaction = self::ouvrir_transaction();
        try {
            // Sous transaction, la ligne du verrou est verrouillée (FOR UPDATE en
            // MariaDB/InnoDB) : un autre processus ne peut pas nous reprendre au
            // milieu. Vérifier puis écrire ne peut plus être séparé par une reprise.
            self::verrouiller_ligne($nom);
            if (!self::verrou_est_a_nous($nom, $jeton)) {
                return [false, null, false];
            }
            // État observé AVANT l'action : sert à détecter qu'un deadlock nous a
            // fait PERDRE la transaction en cours de route.
            $dansTransactionAuDepart = self::dans_une_transaction();
            $gardeAction = static function () use ($nom, $jeton, $transaction): bool {
                if (!self::verrou_est_a_nous($nom, $jeton)) {
                    return false;
                }
                // Si nous avions une transaction et qu'elle a disparu (deadlock),
                // toute écriture partirait en AUTOCOMMIT : on refuse d'écrire.
                return $transaction !== 'transaction' || self::dans_une_transaction();
            };
            $resultat = $action($gardeAction, static function () use (&$annuler): void {
                $annuler = true;
            });

            // -----------------------------------------------------------------
            // MESURÉ (bac, MariaDB 11.8, 21 deadlocks) : après un deadlock,
            // `SELECT @@in_transaction` rend **0**. MariaDB a annulé TOUTE la
            // transaction, y compris le verrou et la réservation faite avant.
            // Les reprises internes, elles, s'exécutent alors en autocommit :
            // elles aboutissent, mais le verrou n'est plus tenu et rien n'est
            // plus atomique.
            //
            // La seule reprise SÛRE est donc au niveau de la section critique
            // entière : on rend le verrou tel quel, on REFERME la transaction
            // morte, puis l'appelant relance TOUT (verrou + compteur) dans une
            // transaction neuve.
            // -----------------------------------------------------------------
            if ($transaction === 'transaction'
                && $dansTransactionAuDepart
                && !self::dans_une_transaction()) {
                self::$transactionPerdue = true;
            }
            // Après l'action : si le verrou n'est plus à nous, le travail n'est
            // pas fiable et on le signale à l'appelant.
            $garde = self::verrou_est_a_nous($nom, $jeton);
            if ($transaction === 'transaction') {
                if ($annuler) {
                    self::annuler_transaction(); // l'action a tout annulé
                } else {
                    self::valider_transaction();
                }
            }
        } catch (Throwable $e) {
            if (self::$transactionOuverte) {
                self::annuler_transaction();
            }
            throw $e;
        } finally {
            if (self::$transactionOuverte) {
                self::annuler_transaction();
            }
            self::rendre_verrou($nom, $jeton);
        }
        return [$garde, $resultat, self::$transactionPerdue];
    }

    /**
     * Ouvre une transaction. Rend 'transaction' si la base l'a acceptée,
     * 'aucune' sinon — et dans ce cas la protection repose uniquement sur les
     * mutations conditionnelles, ce que le code dit au lieu de le taire.
     */
    private static function ouvrir_transaction(): string
    {
        global $wpdb;
        if (self::$transactionOuverte) {
            return 'transaction';
        }
        foreach (['START TRANSACTION', 'BEGIN IMMEDIATE', 'BEGIN'] as $ordre) {
            $wpdb->last_error = '';
            if ($wpdb->query($ordre) !== false && $wpdb->last_error === '') {
                self::$transactionOuverte = true;
                return 'transaction';
            }
        }
        $wpdb->last_error = '';
        return 'aucune';
    }

    /** Verrouille la ligne du verrou pour la durée de la transaction (si la base sait le faire). */
    private static function verrouiller_ligne(string $nom): bool
    {
        global $wpdb;
        if (!self::$transactionOuverte) {
            return false;
        }
        $wpdb->last_error = '';
        $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s FOR UPDATE",
            $nom
        ));
        if ($wpdb->last_error === '') {
            return true;
        }
        // SQLite ne connaît pas FOR UPDATE : sa transaction en écriture
        // (BEGIN IMMEDIATE) verrouille déjà la base entière.
        $wpdb->last_error = '';
        return false;
    }

    /**
     * La session est-elle encore DANS une transaction ?
     *
     * `SELECT @@in_transaction` répond directement (MariaDB/MySQL). Si le SGBD
     * ne connaît pas la variable, on renvoie true : « je ne sais pas » ne doit
     * jamais être interprété comme « transaction perdue » (sinon on relancerait
     * à tort).
     */
    private static function dans_une_transaction(): bool
    {
        global $wpdb;
        $avant = (string) $wpdb->last_error;
        $valeur = $wpdb->get_var('SELECT @@in_transaction');
        $erreur = (string) $wpdb->last_error;
        $wpdb->last_error = $avant; // on ne pollue pas l'état de l'appelant
        if ($erreur !== '') {
            return true; // inconnu : on ne conclut pas à une perte
        }
        if (is_null($valeur)) {
            return false;
        }
        return (int) $valeur === 1;
    }

    private static function valider_transaction(): void
    {
        global $wpdb;
        $wpdb->query('COMMIT');
        self::$transactionOuverte = false;
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private static function annuler_transaction(): void
    {
        global $wpdb;
        if (!self::$transactionOuverte) {
            return;
        }
        $wpdb->query('ROLLBACK');
        self::$transactionOuverte = false;
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /**
     * Le verrou `$nom` est-il encore le nôtre ET pas expiré ?
     * Une seule requête, pas de cache : c'est la seule vérité.
     */
    private static function verrou_est_a_nous(string $nom, string $jeton): bool
    {
        global $wpdb;
        $valeur = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
            $nom
        ));
        if (!is_string($valeur)) {
            return false;
        }
        $parties = explode('|', $valeur);
        if (($parties[0] ?? '') !== $jeton) {
            return false;
        }
        $pose = (int) ($parties[1] ?? 0);
        return $pose > 0 && (time() - $pose) <= self::VERROU_TTL;
    }

    /** Rend un jeton si le verrou est pris, null sinon. */
    private static function prendre_verrou(string $nom): ?string
    {
        global $wpdb;
        $jeton = wp_generate_password(20, false, false);
        $table = $wpdb->options;

        for ($essai = 0; $essai < 3; $essai++) {
            // Atomique : l'index UNIQUE sur option_name fait échouer le doublon.
            $ok = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $nom,
                $jeton . '|' . time()
            ));
            if ($ok === 1) {
                self::purger_cache_option($nom);
                return $jeton;
            }

            // Occupé : reprendre SEULEMENT si le détenteur est expiré, et
            // seulement si la valeur n'a pas changé entre-temps (compare-and-set).
            $actuel = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM `{$table}` WHERE option_name = %s",
                $nom
            ));
            if ($actuel === null) {
                continue; // libéré entre-temps : on retente le INSERT
            }
            $parties = explode('|', (string) $actuel);
            $pose = (int) ($parties[1] ?? 0);
            if ($pose > 0 && (time() - $pose) > self::VERROU_TTL) {
                $reprise = $wpdb->query($wpdb->prepare(
                    "UPDATE `{$table}` SET option_value = %s WHERE option_name = %s AND option_value = %s",
                    $jeton . '|' . time(),
                    $nom,
                    $actuel
                ));
                if ($reprise === 1) {
                    self::purger_cache_option($nom);
                        return $jeton;
                }
                continue; // quelqu'un d'autre l'a repris : on retente
            }
            usleep(20000); // 20 ms
        }
        return null;
    }

    /**
     * Libère le verrou SEULEMENT s'il est encore le nôtre (compare-and-set).
     * C'est ce qui empêche un ancien détenteur d'effacer le verrou d'un autre.
     */
    private static function rendre_verrou(string $nom, string $jeton): void
    {
        global $wpdb;
        $table = $wpdb->options;
        $actuel = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$table}` WHERE option_name = %s",
            $nom
        ));
        if ($actuel === null) {
            self::purger_cache_option($nom);
            return;
        }
        $parties = explode('|', (string) $actuel);
        if (($parties[0] ?? '') !== $jeton) {
            return; // plus nôtre : on ne touche pas au verrou d'autrui
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$table}` WHERE option_name = %s AND option_value = %s",
            $nom,
            $actuel
        ));
        self::purger_cache_option($nom);
    }

    /** Un verrou écrit en SQL direct doit aussi disparaître du cache objet. */
    private static function purger_cache_option(string $nom): void
    {
        wp_cache_delete($nom, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }


    /**
     * Clé de limitation : l'adresse vue par le serveur, JAMAIS un en-tête
     * fourni par le client (`X-Forwarded-For`, `X-Real-IP` sont falsifiables :
     * mesuré, une valeur forgee arrive intacte, et aucune liste de proxys de
     * confiance n'est declaree sur ce site).
     */
    private static function cle_limitation(string $prefixe): ?string
    {
        $ip = filter_var((string) ($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP);
        if ($ip === false) {
            return null;
        }
        return 'tuc_' . $prefixe . '_' . md5((string) $ip);
    }

    /**
     * Incrémente un compteur à fenêtre fixe. À appeler DANS `sous_verrou()`.
     *
     * Trois courses ont été trouvées par les revues, et chacune a sa réponse :
     *
     * 1. (n°3) vérifier la garde puis écrire sont deux opérations ; entre les
     *    deux, le verrou peut expirer et un autre processus écrire.
     *    → l'écriture est CONDITIONNELLE sur la valeur lue.
     * 2. (n°4) ABA : la ligne revient à la même valeur après d'autres écritures.
     *    → la séquence fait partie de la valeur comparée.
     * 3. (n°5) création concurrente : INSERT puis UPDATE sans condition laissait
     *    un processus écraser l'écriture d'un autre.
     *    → la création est un INSERT unique (jamais suivi d'un UPDATE) ; si la
     *      ligne apparaît entre-temps, on rend false et l'appelant retente sous
     *      verrou, au lieu d'écraser.
     * 4. (n°6) ABA PAR RECRÉATION : la ligne supprimée puis recréée repartait à
     *    « 1|<même fin>|1 ». Une écriture conditionnelle préparée AVANT la
     *    suppression retrouvait alors exactement la valeur comparée et pouvait
     *    être acceptée. → la valeur porte une GÉNÉRATION tirée d'un compteur
     *    global strictement croissant, jamais réutilisée ; un « reset » de
     *    fenêtre échue est une écriture CONDITIONNELLE, donc la génération de la
     *    ligne est conservée. Mesuré : `aba-recreation.php`.
     */
    private static function incrementer_compteur(string $cle, ?callable $garde = null, ?callable $annuler = null): ?bool
    {
        // null = refus (génération non allouée, ou vraie panne SQL).
        // false = course ordinaire (ligne apparue/disparue, verrou perdu).
        //
        // REPRISE SUR CONFLIT RÉCUPÉRABLE : un deadlock ou une collision
        // optimiste pendant l'écriture du compteur ne consomme rien et ne
        // laisse rien de partiel. On relit et on recommence, dans la limite de
        // COMPTEUR_TENTATIVES. Toute erreur non récupérable sort en `null` :
        // une vraie panne ne doit jamais être « réessayée » en boucle.
        self::$derniereErreurSql = '';
        for ($essai = 1; $essai <= self::COMPTEUR_TENTATIVES; $essai++) {
            // DÉFAUT MESURÉ (bac, 2026-11-03) : sans cette garde, une reprise
            // après deadlock écrivait en AUTOCOMMIT (la transaction était morte)
            // et consommait un crédit, puis la reprise de la section critique en
            // consommait un second : deux crédits pour une seule demande.
            // Ici, plus aucune écriture n'est tentée si le verrou ou la
            // transaction ne sont plus à nous.
            // La garde (verrou à nous + transaction vivante) est vérifiée DANS
            // `tenter_increment()`, entre la lecture et l'écriture — jamais
            // avant la lecture : sinon on relirait un état frais après la pause
            // du concurrent et l'écriture « préparée sur un état périmé »
            // réussirait (cas 74/84/87 du contrat).
            $etat = self::tenter_increment($cle, $garde, $annuler);
            if ($etat !== 'conflit') {
                if ($etat === false) {
                    self::$refusParPanne = false; // course ordinaire
                    self::$causeRefus = 'conflits';
                }
                if ($etat === null) {
                    // Deux lectures du même `null`, et une seule juste :
                    //   - « conflits » : les reprises sur le compteur sont
                    //     épuisées -> file d'attente, l'appelant rejoue ;
                    //   - « generation » : le numéro d'identité n'a pas pu être
                    //     alloué. Mesuré en bac le 2026-11-10 (2 passes de 96 IP
                    //     distinctes) : 205 échecs de génération, TOUS sur le
                    //     motif « transaction morte » (deadlock sur la ligne du
                    //     compteur global), 0 sur un motif de droits, de table ou
                    //     de disque. C'est donc un conflit de concurrence
                    //     transitoire, pas une panne du stockage — `sous_verrou`
                    //     rejoue déjà la section entière grâce à
                    //     `$transactionPerdue`. Le rendre en 503
                    //     `tuc_onboarding_storage` violait l'invariant « pas de
                    //     503 pour un conflit transitoire » et faisait perdre la
                    //     réservation (95/96 mesuré, CI et bac).
                    // Le refus reste un REFUS : rien n'est écrit, aucune unité
                    // de quota consommée, aucun identitaire inventé (revue n°7 :
                    // pas de repli horodaté). Seule la CLASSIFICATION change —
                    // réessayable, et non « service indisponible ».
                    // Le déclassement en « réessayable » est BORNE à la cause
                    // réellement mesurée : la transaction était MORTE au moment de
                    // l'allocation (`$transactionPerdue`, remis à false à chaque
                    // section par `sous_verrou`, donc jamais hérité d'une passe
                    // précédente). Un échec d'allocation sur une panne SQL vraie
                    // (table absente, droits — le cas 118 du contrat figé) reste
                    // un 503 `tuc_onboarding_storage` : on ne déguise pas une
                    // panne en file d'attente.
                    self::$refusParPanne = self::$causeRefus !== 'conflits'
                        && !(self::$causeRefus === 'generation' && self::$transactionPerdue);
                }
                return $etat;
            }
            // Un deadlock est TRANSITOIRE : le relancer a un sens. Une collision
            // optimiste vient au contraire d'avoir lu un état périmé : la bonne
            // réponse est de rendre false (l'appelant rejoue la section entière),
            // pas de réécrire avec la même valeur attendue. Mesuré sur le
            // contrat : réessayer ici faisait passer 14 cas de refus attendus en
            // acceptations silencieuses.
            if (!self::erreur_deadlock(self::$derniereErreurSql)) {
                self::$refusParPanne = false;
                self::$causeRefus = 'conflits';
                return false;
            }
            if ($essai < self::COMPTEUR_TENTATIVES) {
                self::oublier_erreur_sql();
                usleep(rand(2, 15) * 1000); // jitter : désynchronise les reprises
            }
        }
        // Reprises épuisées sur des deadlocks : conflit persistant, pas une
        // panne. L'appelant invite à réessayer (jamais un 503 « indisponible »).
        self::$refusParPanne = false;
        self::$causeRefus = 'conflits';
        return null; // refus propre, rien de partiel écrit
    }

    /** Un essai d'incrément. Rend true / false / null, ou 'conflit' si réessayable. */
    private static function tenter_increment(string $cle, ?callable $garde, ?callable $annuler): bool|string|null
    {
        global $wpdb;
        $brut = self::lire_brut($cle);
        // Avant d'écrire : le verrou est-il toujours le nôtre ?
        if ($garde !== null && !$garde()) {
            return false;
        }
        if ($brut === null) {
            // Aucun compteur : création atomique à 1. Si quelqu'un a créé la
            // ligne entre la lecture et l'instant présent, on n'écrase pas.
            return self::tenter_ecrire_compteur($cle, 1, time() + HOUR_IN_SECONDS, null, null, $annuler);
        }
        // On réutilise la valeur lue pour décider, et c'est CETTE valeur qui
        // sert d'attendu au CAS : relire une seconde fois (ancienne version)
        // ouvrait une fenêtre où la décision portait sur un état déjà remplacé.
        [$n, $restant] = self::lire_valeur($brut);
        $fin = $restant > 0 ? time() + $restant : time() + HOUR_IN_SECONDS;
        if ($n <= 0) {
            // Fenêtre échue : on repart à un, MAIS en écriture CONDITIONNELLE
            // sur la valeur lue — donc la génération de la ligne est conservée et
            // aucune purge concurrente ne peut être « annulée » par ce reset.
            return self::tenter_ecrire_compteur($cle, 1, $fin, $brut);
        }
        return self::tenter_ecrire_compteur($cle, $n + 1, $fin, $brut);
    }

    /**
     * Rend un crédit de demande quand la création échoue APRÈS réservation.
     * Plancher à 0. Rend true seulement si le crédit est réellement rendu.
     */
    private static function rendre_demande(): bool
    {
        $cle = self::cle_limitation('demandes');
        if ($cle === null) {
            return false;
        }
        self::oublier_erreur_sql();
        [$pris, $rendu] = self::sous_verrou($cle, static function (callable $garde, callable $annuler) use ($cle): bool {
            $brut = self::lire_brut($cle);
            [$n, $restant] = self::lire_valeur($brut);
            if ($n <= 0 || $brut === null) {
                return false; // rien à rendre
            }
            if (!$garde()) {
                return false; // verrou perdu pendant l'opération : on n'écrit pas
            }
            if ($restant <= 0) {
                return self::supprimer_compteur($cle);
            }
            return self::ecrire_compteur($cle, $n - 1, time() + $restant, $brut);
        });
        return $pris && $rendu === true && self::derniere_erreur_sql() === '';
    }

    /**
     * Barrière d'entrée : compte TOUTE tentative, lecture + décision + écriture
     * dans la MÊME section critique.
     */
    private static function limiter_tentative(): ?WP_Error
    {
        $cle = self::cle_limitation('tentatives');
        if ($cle === null) {
            return null; // pas d'adresse exploitable : on ne bloque pas au hasard
        }
        self::oublier_erreur_sql();
        $resultat = null;
        $ecrit = false;
        [$pris, ] = self::sous_verrou($cle, static function (callable $garde, callable $annuler) use ($cle, &$resultat, &$ecrit): bool {
            [$n, $restant] = self::lire_compteur($cle);
            if (self::derniere_erreur_sql() !== '') {
                // BARBIÈRE D'ENTRÉE : avant, TOUT conflit transitoire sur cette
                // lecture remontait en 503 « service indisponible », quel que
                // soit le message. Mesuré en CI (run sur c9540d7) : 16 refus 503
                // sur 96 réservations d'IP distinctes et 25 sur 144 en rafale —
                // exactement les chiffres qui résistaient aux trois corrections
                // précédentes, toutes situées plus bas dans la même requête.
                // Une lecture qui bute sur un deadlock n'a rien lu et n'a rien
                // perdu : c'est à rejouer, pas une panne.
                $messageLu = (string) self::derniere_erreur_sql();
                self::oublier_erreur_sql();
                if (self::erreur_recuperable($messageLu)) {
                    self::$refusParPanne = false;
                    self::$causeRefus = 'conflits';
                    $resultat = self::erreur_occupation_passee();
                    return false;
                }
                $resultat = self::erreur_stockage();
                return false;
            }
            if ($n >= self::MAX_TENTATIVES_PAR_HEURE) {
                $resultat = new WP_Error(
                    'tuc_onboarding_rate',
                    self::message_limite($restant),
                    ['status' => 429, 'retry_after' => $restant]
                );
                return false;
            }
            $ecrit = self::incrementer_compteur($cle, $garde, $annuler);
            if ($ecrit === null) {
                // `null` = REFUS, et le refus a deux natures que ce bloc
                // confondait : panne réelle du stockage, ou reprises épuisées sur
                // conflits transitoires (deadlock, collision optimiste,
                // allocation de génération sur transaction morte). C'est
                // `incrementer_compteur()` qui le sait : on lit son verdict
                // plutôt que de décréter la panne ici. Mesuré en CI (c9540d7) :
                // ce site rendait à lui seul l'essentiel des 503 de la rafale.
                // Aucun crédit consommé, aucune demande acceptée dans les deux cas.
                $resultat = self::$refusParPanne
                    ? self::erreur_stockage()
                    : self::erreur_occupation_passee();
                $annuler();
                return false;
            }
            if ($ecrit === false) {
                $resultat = self::erreur_occupation_passee();
            }
            return true;
        });
        if (!$pris) {
            return $resultat instanceof WP_Error ? $resultat : self::erreur_occupe();
        }
        return $resultat instanceof WP_Error ? $resultat : null;
    }

    /**
     * Barrière des demandes créées. À appeler UNIQUEMENT quand tout le reste a
     * validé. Lecture + décision + incrément sont dans la même section critique.
     */
    private static function reserver_demande(): ?WP_Error
    {
        $cle = self::cle_limitation('demandes');
        if ($cle === null) {
            return null;
        }
        // REPRISE DE LA SECTION CRITIQUE ENTIÈRE : si un deadlock a annulé la
        // transaction, tout ce qui a été fait dedans (verrou compris) est perdu.
        // On relance DONC la section complète — verrou, lecture, écriture — dans
        // une transaction neuve, et pas seulement la requête en échec.
        for ($essai = 1; $essai <= self::CRITIQUE_TENTATIVES; $essai++) {
            [$pris, $resultat, $perdue] = self::tenter_reservation($cle);
            // Le verdict « momentanément occupé » est une invitation à rejouer la
            // section — le module se l'applique à lui-même une fois ou deux avant
            // de la renvoyer au client. Mesuré en CI (run sur 49aaab0) : 18 de
            // ces refus sur 96 réservations d'IP distinctes, 31 sur 144, alors
            // qu'une seule reprise suffit (vérifié : 100 % passent au 2e essai).
            // Borné par CRITIQUE_TENTATIVES, avec jitter, et SANS consommation de
            // quota : ces deux branches n'ont rien écrit (`$annuler()` a tout
            // annulé). Un refus de quota (429) ou une panne réelle (503
            // `tuc_onboarding_storage`) ne sont PAS rejoués : ce ne sont pas des
            // courses perdues.
            if (!$perdue
                && (!$pris
                    || ($resultat instanceof WP_Error
                        && $resultat->get_error_code() === 'tuc_onboarding_occupe'))
                && $essai < self::CRITIQUE_TENTATIVES) {
                self::oublier_erreur_sql();
                usleep(rand(3, 25) * 1000);
                continue;
            }
            if (!$perdue) {
                if (!$pris) {
                    // Verrou NON pris : la demande n'est pas réservée. Sans ce
                    // garde-fou (perdu lors du passage aux 3 valeurs de retour),
                    // un verrou refusé sortait en `null` = « accepté » — c'est
                    // exactement ce que le cas 118 a attrapé.
                    return $resultat instanceof WP_Error ? $resultat : self::erreur_occupe();
                }
                return $resultat instanceof WP_Error ? $resultat : null;
            }
            // Transaction morte : rien n'a été validé, on recommence proprement.
            self::oublier_erreur_sql();
            usleep(rand(5, 40) * 1000);
        }
        return self::erreur_occupation_passee();
    }

    /** Une tentative complète de réservation. @return array{0:bool,1:mixed,2:bool} */
    private static function tenter_reservation(string $cle): array
    {
        self::oublier_erreur_sql();
        $resultat = null;
        [$pris, , $perdue] = self::sous_verrou($cle, static function (callable $garde, callable $annuler) use ($cle, &$resultat): bool {
            [$n, $restant] = self::lire_compteur($cle);
            if (self::derniere_erreur_sql() !== '') {
                // La LECTURE du compteur a échoué. Deux mondes séparés par le
                // message de la base, et le 503 « service indisponible » ne
                // concerne que le second :
                //   - conflit transitoire (deadlock, « Record has changed since
                //     last read », « Lock wait timeout exceeded ») : rien n'a été
                //     lu, rien n'est perdu, la section sera rejouée par
                //     `sous_verrou` ;
                //   - panne réelle (droits, table absente, disque) : là oui, le
                //     service est indisponible et doit le dire.
                // DÉFAUT MESURÉ en CI le 2026-11-10 (run sur 7e3a127) : ce chemin
                // était le seul encore classé « panne » sans regarder le message —
                // 19 refus 503 sur 96 réservations d'IP distinctes, 19 sur 144 en
                // rafale, alors que 0 unité de quota n'était consommée à tort.
                $messageLu = (string) self::derniere_erreur_sql();
                self::oublier_erreur_sql();
                if (self::erreur_recuperable($messageLu)) {
                    self::$refusParPanne = false;
                    self::$causeRefus = 'conflits';
                    $resultat = self::erreur_occupation_passee();
                    return false;
                }
                $resultat = self::erreur_stockage();
                return false;
            }
            if ($n >= self::MAX_DEMANDES_PAR_HEURE) {
                $resultat = new WP_Error(
                    'tuc_onboarding_quota',
                    self::message_limite($restant),
                    ['status' => 429, 'retry_after' => $restant]
                );
                return false;
            }
            $ecrit = self::incrementer_compteur($cle, $garde, $annuler);
            if ($ecrit === null) {
                // Deux causes très différentes, et le refus doit le dire :
                //  - VRAIE PANNE SQL (table absente, droits, disque) : c'est une
                //    indisponibilité du service, pas une file d'attente. On rend
                //    503 `tuc_onboarding_storage` et on n'invite pas à réessayer
                //    comme si c'était un simple conflit (exigence du donneur
                //    d'ordre : ne pas transformer une panne en conflit).
                //  - reprises épuisées sur deadlocks / courses : là, oui, inviter
                //    à réessayer.
                // Rien n'a été écrit dans les deux cas (toutes les écritures ont
                // échoué), donc le ROLLBACK ne perd rien.
                $resultat = self::$refusParPanne
                    ? self::erreur_stockage()
                    : self::erreur_occupation_passee();
                $annuler();
                return false;
            }
            if ($ecrit === false) {
                // Ligne apparue/disparue, verrou perdu, ou collision optimiste :
                // AUCUN crédit n'a été consommé. Ce n'est pas une panne de
                // service : on invite à réessayer (et on ANNULE la transaction,
                // pour ne rien laisser derrière) au lieu de rendre un 503.
                // Mesuré le 2026-11-10 : ce seul `return false` muet produisait
                // 6 refus 503 sur 12 demandes d'IP distinctes.
                $resultat = self::erreur_occupation_passee();
                $annuler();
                return false;
            }
            return true;
        });
        return [$pris, $resultat, $perdue];
    }

    /** La ligne a bougé pendant la section critique : nouvel essai invité, pas un 503. */
    private static function erreur_occupation_passee(): WP_Error
    {
        return new WP_Error(
            'tuc_onboarding_occupe',
            __('Le service est momentanément occupé. Réessayez dans un instant.', 'tuc-core'),
            ['status' => 503, 'retry' => true]
        );
    }

    /** Lecture ou écriture du compteur ratée : on refuse, on ne « laisse pas passer ». */
    private static function erreur_stockage(): WP_Error
    {
        return new WP_Error(
            'tuc_onboarding_storage',
            __('Le service est momentanément indisponible. Réessayez dans un instant.', 'tuc-core'),
            ['status' => 503]
        );
    }

    private static function erreur_occupe(): WP_Error
    {
        return new WP_Error(
            'tuc_onboarding_occupe',
            __('Le service est momentanément occupé. Réessayez dans un instant.', 'tuc-core'),
            ['status' => 503]
        );
    }

    /**
     * Curseur de purge, gardé en base : c'est ce qui permet à une passe de
     * REPRENDRE là où la précédente s'est arrêtée, au lieu de repartir du début.
     *
     * Revue n°6, point 2 : la passe précédente retenait en mémoire tous les noms
     * vus vivants et les réinjectait en `NOT IN (...)` dans la fenêtre suivante.
     * Sur un volume réel, cela faisait (a) une requête de plus en plus longue,
     * (b) une mémoire proportionnelle au nombre de compteurs vivants, (c) aucun
     * état conservé : la passe suivante relisait les mêmes 500 premières lignes.
     *
     * Le curseur est UNE ligne d'options, donc UNE sur tout le site, quelle que
     * soit l'adresse du visiteur. Il n'est jamais supprimé par la purge.
     */
    private const OPTION_CURSEUR = 'tuc_purge_curseur';

    /**
     * CIBLE de temps d'un appel (millisecondes) : on ne démarre PAS de nouvelle
     * fenêtre au-delà. Ce n'est PAS une limite absolue — voir le commentaire de
     * `balayer_lignes_perimees()`.
     */
    private const PURGE_MS_MAX = 250;

    /**
     * Balayage des lignes périmées : compteurs échus depuis plus de
     * RETENTION_COMPTEUR et verrous orphelins très anciens. Sans lui, chaque
     * nouvelle IP laisserait une ligne d'options pour toujours.
     *
     * HISTORIQUE DES DÉFAUTS, tous reproduits par un test avant correction :
     *
     * n°3 : suppression par nom après sélection → DELETE conditionnel sur la
     *       valeur exacte (une ligne renouvelée survit).
     * n°4 : 500 lignes vivantes triées avant les périmées → la fenêtre
     *       n'avançait pas ; corrigé alors par une exclusion en mémoire.
     * n°6 : cette exclusion en mémoire ne bornait rien (requête et mémoire
     *       proportionnelles au nombre de vivantes, pas de reprise). → CURSEUR
     *       PERSISTANT + PROGRESSION STRICTEMENT CROISSANTE + BUDGET DE TEMPS.
     */
    private static function purger_lignes_perimees(int $plafond = 500): int
    {
        if (wp_rand(1, self::PURGE_UN_SUR) !== 1) {
            return 0;
        }
        return self::balayer_lignes_perimees($plafond);
    }

    /**
     * Le balayage lui-même, sans le tirage aléatoire : c'est lui qu'on teste.
     * Rend le nombre de lignes réellement supprimées.
     *
     * INCORRECTIONS INTERDITES PAR CONSTRUCTION :
     *  - la fenêtre est toujours `option_name > curseur` (jamais `NOT IN`) :
     *    sa taille ne dépend plus de ce qui a été vu, seulement du `LIMIT` ;
     *  - le curseur ne recule jamais et n'est écrit qu'après le traitement de la
     *    fenêtre : une passe interrompue reprend exactement au même endroit ;
     *  - une visite complète (fenêtre vide) remet le curseur à zéro, sinon les
     *    lignes écrites plus tard, dont le nom est « avant » le curseur, ne
     *    seraient plus jamais visitées ;
     *  - la purge s'arrête de démarrer une nouvelle fenêtre au-delà de `$msMax`.
     *
     * CE QUE 250 ms GARANTIT, ET CE QU'IL NE GARANTIT PAS (revue n°7). La
     * vérification du budget a lieu ENTRE deux fenêtres : une requête SQL lente
     * ou une fenêtre complète (500 lignes) n'est pas interrompue. Le dépassement
     * possible est donc la durée d'UNE fenêtre (SELECT + jusqu'à 500 DELETE
     * conditionnels), pas celle de tout le balayage. Mesuré : cible tenue à
     * 250-252 ms sur 100 000 lignes ; dépassement mesuré à 1 101 ms avec un
     * `SLEEP(1)` injecté dans le SELECT, et la passe reprend ensuite au curseur.
     *
     * @param int $plafond   lignes par fenêtre
     * @param int $fenetresMax garde-fou de sécurité (nombre de fenêtres)
     * @param int $msMax     budget de temps de l'appel, en millisecondes (0 = illimité pour les tests)
     */
    private static function balayer_lignes_perimees(int $plafond = 500, int $fenetresMax = 2000, int $msMax = self::PURGE_MS_MAX): int
    {
        global $wpdb;
        $debut = microtime(true);
        $limite = time() - self::RETENTION_COMPTEUR;
        $supprimees = 0;
        $passes = 0;
        $lues = 0;
        $curseur = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
            self::OPTION_CURSEUR
        ));
        $depart = $curseur;

        while ($passes < max(1, $fenetresMax)) {
            if ($msMax > 0 && $passes > 0 && (microtime(true) - $debut) * 1000 >= $msMax) {
                // On ne démarre pas de nouvelle fenêtre. La fenêtre en cours, elle,
                // n'est jamais interrompue (cf. commentaire de la fonction).
                $GLOBALS['tuc_purge_budget_atteint'] = true;
                break;
            }
            $passes++;

            $lignes = $wpdb->get_results($wpdb->prepare(
                "SELECT option_name, option_value FROM `{$wpdb->options}`
                  WHERE (option_name LIKE %s OR option_name LIKE %s)
                    AND option_name > %s
                  ORDER BY option_name ASC
                  LIMIT %d",
                $wpdb->esc_like('tuc_tentatives_') . '%',
                $wpdb->esc_like('tuc_demandes_') . '%',
                $curseur,
                max(1, $plafond)
            ), ARRAY_A);

            if (empty($lignes)) {
                // File parcourue : on repart du début à la prochaine passe.
                self::ecrire_curseur('');
                $curseur = '';
                break;
            }
            $lues += count($lignes);

            $dernier = $curseur;
            foreach ((array) $lignes as $ligne) {
                $nom = (string) ($ligne['option_name'] ?? '');
                $valeur = (string) ($ligne['option_value'] ?? '');
                if ($nom <= $dernier) {
                    break; // sécurité : jamais deux fois le même nom dans une passe
                }
                $dernier = $nom;

                if (!preg_match('/^tuc_(tentatives|demandes)_[a-z0-9]+(_verrou)?$/', $nom)) {
                    continue; // jamais notre bien : laissé tel quel
                }
                // Point d'observation (action WordPress) : sans auditeur, aucun
                // effet. Il existe pour que le test puisse renouveler la ligne
                // EXACTEMENT entre la sélection et la suppression.
                do_action('tuc_avant_suppression_ligne_perimee', $nom, $valeur);

                $instant = (int) (explode('|', $valeur)[1] ?? 0);
                if ($instant <= 0 || $instant >= $limite) {
                    continue; // vivante : on la laisse, le curseur l'a dépassée
                }
                // CONDITIONNEL : ne supprime que si la valeur est encore celle lue.
                $touche = $wpdb->query($wpdb->prepare(
                    "DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND option_value = %s",
                    $nom,
                    $valeur
                ));
                if ((int) $touche === 1) {
                    $supprimees++;
                    self::purger_cache_option($nom);
                }
            }

            // Curseur écrit APRÈS traitement : une interruption relit la fenêtre
            // (travail refait, jamais travail sauté).
            $curseur = $dernier;
            self::ecrire_curseur($curseur);
        }

        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        $GLOBALS['tuc_purge_lues'] = $lues;
        $GLOBALS['tuc_purge_fenetres'] = $passes;
        $GLOBALS['tuc_purge_ms'] = (int) round((microtime(true) - $debut) * 1000);
        $GLOBALS['tuc_purge_depassement_ms'] = max(0, (int) $GLOBALS['tuc_purge_ms'] - max(0, $msMax));
        $GLOBALS['tuc_purge_curseur_depart'] = $depart;
        $GLOBALS['tuc_purge_curseur_fin'] = $curseur;
        return $supprimees;
    }

    /** Écrit (ou efface, si chaîne vide) le curseur de purge. */
    private static function ecrire_curseur(string $valeur): void
    {
        global $wpdb;
        if ($valeur === '') {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$wpdb->options}` WHERE option_name = %s",
                self::OPTION_CURSEUR
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                self::OPTION_CURSEUR,
                $valeur
            ));
            // SQLite ne connaît pas ON DUPLICATE KEY : on retente en deux temps.
            if (self::derniere_erreur_sql() !== '') {
                $wpdb->last_error = '';
                $wpdb->query($wpdb->prepare(
                    "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s",
                    $valeur,
                    self::OPTION_CURSEUR
                ));
                if ((int) $wpdb->rows_affected === 0) {
                    $wpdb->query($wpdb->prepare(
                        "INSERT OR IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                        self::OPTION_CURSEUR,
                        $valeur
                    ));
                }
            }
        }
        self::purger_cache_option(self::OPTION_CURSEUR);
    }

    /** Message avec un délai lisible : « dans 42 minutes », pas « plus tard ». */
    private static function message_limite(int $secondes): string
    {
        if ($secondes <= 0) {
            return __('Trop de demandes depuis cet appareil. Réessayez dans un instant.', 'tuc-core');
        }
        if ($secondes < 120) {
            return sprintf(
                _n(
                    'Trop de demandes depuis cet appareil. Réessayez dans %d seconde.',
                    'Trop de demandes depuis cet appareil. Réessayez dans %d secondes.',
                    $secondes,
                    'tuc-core'
                ),
                $secondes
            );
        }
        $minutes = (int) ceil($secondes / 60);
        return sprintf(
            _n(
                'Trop de demandes depuis cet appareil. Réessayez dans %d minute.',
                'Trop de demandes depuis cet appareil. Réessayez dans %d minutes.',
                $minutes,
                'tuc-core'
            ),
            $minutes
        );
    }

    public static function soumettre(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limite = self::limiter_tentative();
        if ($limite instanceof WP_Error) {
            return $limite;
        }
        self::purger_lignes_perimees();
        $waNumber = preg_replace('/[^0-9]/', '', (string) get_option('tuc_wa_numero', ''));
        if ($waNumber === '') {
            return new WP_Error('tuc_onboarding_whatsapp', 'Le contact WhatsApp de TUC n’est pas configuré.', ['status' => 503]);
        }

        $name = sanitize_text_field((string) $request->get_param('restaurant_name'));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : preg_match_all('/./us', $name, $nameCharacters);
        if ($nameLength < 2 || $nameLength > 120) {
            return new WP_Error('tuc_onboarding_name', 'Indiquez un nom de restaurant entre 2 et 120 caractères.', ['status' => 422]);
        }

        $files = $request->get_file_params();
        $photo = $files['menu_photo'] ?? null;
        if (!is_array($photo) || (int) ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || empty($photo['tmp_name']) || !is_file((string) $photo['tmp_name'])) {
            return new WP_Error('tuc_onboarding_photo', 'Ajoutez une photo du menu.', ['status' => 422]);
        }
        if ((int) ($photo['size'] ?? 0) <= 0 || (int) $photo['size'] > self::MAX_PHOTO_BYTES) {
            return new WP_Error('tuc_onboarding_photo_size', 'La photo doit faire 5 Mo maximum.', ['status' => 413]);
        }
        // ① Conversion éventuelle d'un HEIC/HEIF en JPEG, AVANT toute autre
        //    vérification de type : après elle, il n'existe plus qu'un JPEG.
        // MESURE AVANT CONVERSION : quel format l'appareil a-t-il RÉELLEMENT
        // envoyé ? On le note d'après les premiers octets. Un JPEG présent dans
        // les uploads ne prouve rien sur l'entrée (il peut venir d'iOS, qui
        // convertit parfois avant l'envoi) — d'où cette observation, qui ne
        // conserve aucune photo et aucune donnée personnelle.
        $formatRecu = tuc_heic_observer_entree((string) $photo['tmp_name'], 'avant-conversion');

        $converti = self::convertir_en_jpeg((string) $photo['tmp_name'], (string) ($photo['name'] ?? ''));
        if (is_wp_error($converti)) {
            return $converti;
        }
        if ($formatRecu === 'heic') {
            tuc_heic_observer_entree((string) $converti['chemin'], 'converti-heic-vers-jpeg');
        }
        $cheminPhoto = $converti['chemin'];
        $photo['name'] = $converti['nom'];
        $photo['size'] = (int) filesize($cheminPhoto);
        $photo['type'] = 'image/jpeg';
        $temporaireCree = (string) $converti['temporaire'];

        // INDISPENSABLE, et je l'avais oublié : `media_handle_sideload()` lit
        // `tmp_name`, pas `$cheminPhoto`. Sans cette ligne, le fichier stocké
        // reste l'original — un .heic atterrissait dans les uploads alors que
        // toute la validation avait porté sur le JPEG converti. Constaté dans un
        // bac à sable : `iphone.heic` présent à côté de `iphone.jpg`.
        $photo['tmp_name'] = $cheminPhoto;

        try {
            $allowed = ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            $fileType = wp_check_filetype_and_ext($cheminPhoto, (string) $photo['name'], $allowed);
            $imageInfo = @getimagesize($cheminPhoto);
            if (empty($fileType['type']) || !in_array($fileType['type'], array_values($allowed), true)
                || !is_array($imageInfo) || !in_array((string) ($imageInfo['mime'] ?? ''), array_values($allowed), true)) {
                return new WP_Error(
                    'tuc_onboarding_photo_type',
                    __('Cette photo n’est pas reconnue comme une image. Envoyez une photo JPEG, PNG, WebP, ou une photo d’iPhone (HEIC).', 'tuc-core'),
                    ['status' => 415]
                );
            }
            if ((int) $photo['size'] > self::MAX_PHOTO_BYTES) {
                return new WP_Error('tuc_onboarding_photo_size', 'La photo doit faire 5 Mo maximum.', ['status' => 413]);
            }


            // Dernière barrière, et seulement ici : tout ce qui précède a validé,
            // donc une erreur de saisie n'a jamais consommé ce quota.
            $quota = self::reserver_demande();
            if ($quota instanceof WP_Error) {
                return $quota;
            }

            /**
             * Tout ce qui peut échouer APRÈS la réservation passe par ici : on
             * nettoie ce qui a été créé, on rend le crédit, et si la restitution
             * elle-même échoue on le DIT au lieu de le masquer.
             */
            $echouer = static function (WP_Error $erreur, int $piece = 0, int $demande = 0): WP_Error {
                if ($piece > 0) {
                    wp_delete_attachment($piece, true);
                }
                if ($demande > 0) {
                    wp_delete_post($demande, true);
                }
                if (!self::rendre_demande()) {
                    // Le crédit reste consommé à tort. On ne renvoie pas l'erreur
                    // d'origine comme si de rien n'était : le code et la donnée
                    // disent ce qui s'est vraiment passé.
                    return new WP_Error(
                        'tuc_onboarding_credit',
                        __('La demande n’a pas abouti et le crédit n’a pas pu être rendu. Réessayez plus tard ou contactez TUC.', 'tuc-core'),
                        ['status' => 500, 'cause' => $erreur->get_error_code()]
                    );
                }
                return $erreur;
            };

            $applicationId = wp_insert_post([
                'post_type' => self::CPT,
                'post_status' => 'private',
                'post_title' => $name,
                'post_name' => 'demande-' . wp_generate_password(12, false, false),
            ], true);
            if (is_wp_error($applicationId)) {
                return $echouer(new WP_Error('tuc_onboarding_save', 'La demande n’a pas pu être enregistrée.', ['status' => 500]));
            }
            $applicationId = (int) $applicationId;

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachmentId = media_handle_sideload($photo, $applicationId, 'Photo du menu — ' . $name);
            if (is_wp_error($attachmentId)) {
                return $echouer(
                    new WP_Error(
                        'tuc_onboarding_photo_save',
                        __('La photo n’a pas pu être enregistrée. Réessayez avec une photo JPEG, PNG ou WebP.', 'tuc-core'),
                        ['status' => 500]
                    ),
                    0,
                    $applicationId
                );
            }
            $attachmentId = (int) $attachmentId;

            update_post_meta($applicationId, 'tuc_onboarding_menu_photo', $attachmentId);
            update_post_meta($applicationId, 'tuc_onboarding_state', 'pending');
            update_post_meta($applicationId, 'tuc_onboarding_reference', strtoupper(wp_generate_password(10, false, false)));
            update_post_meta($applicationId, 'tuc_onboarding_created_at', current_time('mysql', true));
            $waProof = wp_generate_password(40, false, false);
            update_post_meta($applicationId, 'tuc_onboarding_wa_proof_hash', hash('sha256', $waProof));

            $waRequest = new WP_REST_Request('POST', '/tuc/v1/wa-lien');
            $waRequest->set_param('but', 'restaurant_onboarding');
            $waRequest->set_param('application_id', $applicationId);
            $waRequest->set_param('wa_proof', $waProof);
            $waResult = TUC_Compte::wa_lien($waRequest);
            if (is_wp_error($waResult)) {
                // Le lien WhatsApp n'a pas pu être fabriqué : la demande est
                // inutilisable (l'utilisateur ne peut pas nous joindre), donc on
                // la retire ET on rend le crédit.
                return $echouer($waResult, $attachmentId, $applicationId);
            }
            $waData = $waResult instanceof WP_REST_Response ? $waResult->get_data() : [];
            update_post_meta($applicationId, 'tuc_onboarding_wa_code', (string) ($waData['code'] ?? ''));
        } finally {
            // Le JPEG issu de la conversion n'a plus d'utilité une fois la pièce
            // jointe créée : le fichier de WordPress est une copie. On ne laisse
            // donc aucun temporaire derrière nous, même en cas d'erreur.
            if ($temporaireCree !== '' && is_file($temporaireCree)) {
                @unlink($temporaireCree);
            }
        }

        $response = new WP_REST_Response([
            'reference' => (string) get_post_meta($applicationId, 'tuc_onboarding_reference', true),
            'wa_url' => (string) ($waData['url'] ?? ''),
            'code' => (string) ($waData['code'] ?? ''),
            'poll' => (string) ($waData['poll'] ?? ''),
            'message' => 'Demande enregistrée. Envoyez le message WhatsApp prérempli pour que notre équipe puisse vous retrouver.',
        ], 201);
        $response->header('Cache-Control', 'no-store, private, max-age=0');
        $response->header('Pragma', 'no-cache');
        return $response;
    }

    /** Called only after the signed WhatsApp webhook proves the sender number. */
    public static function associer_whatsapp(int $applicationId, string $phone): true|WP_Error
    {
        $application = get_post($applicationId);
        if (!$application instanceof WP_Post || $application->post_type !== self::CPT) {
            return new WP_Error('tuc_onboarding_missing', 'Demande introuvable.', ['status' => 404]);
        }
        $state = (string) get_post_meta($applicationId, 'tuc_onboarding_state', true);
        if (!in_array($state, ['pending', 'contacted'], true)) {
            return new WP_Error('tuc_onboarding_state', 'Cette demande est déjà traitée.', ['status' => 409]);
        }
        $phone = TUC_Compte::normaliser($phone);
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $phone)) {
            return new WP_Error('tuc_onboarding_phone', 'Numéro WhatsApp invalide.', ['status' => 422]);
        }
        $existingPhone = (string) get_post_meta($applicationId, 'tuc_onboarding_phone', true);
        if ($existingPhone !== '' && $existingPhone !== $phone) {
            return new WP_Error('tuc_onboarding_phone_conflict', 'Un autre numéro est déjà associé à cette demande.', ['status' => 409]);
        }
        update_post_meta($applicationId, 'tuc_onboarding_phone', $phone);
        update_post_meta($applicationId, 'tuc_onboarding_phone_verified_at', current_time('mysql', true));
        update_post_meta($applicationId, 'tuc_onboarding_phone_source', 'whatsapp_webhook');
        update_post_meta($applicationId, 'tuc_onboarding_state', 'contacted');
        return true;
    }

    public static function admin_menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . TUC_CPT::RESTO,
            'Demandes de restaurants',
            'Demandes TUC',
            'manage_options',
            'tuc-onboarding-restaurants',
            [self::class, 'page_admin']
        );
    }

    public static function page_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès réservé à l’équipe TUC.', 'tuc-core'), '', ['response' => 403]);
        }
        nocache_headers();
        $notice = sanitize_key((string) ($_GET['tuc_onboarding_notice'] ?? ''));
        $flashKey = 'tuc_onboarding_wa_' . get_current_user_id() . '_' . absint($_GET['application'] ?? 0);
        $waUrl = (string) get_transient($flashKey);
        if ($waUrl !== '') {
            delete_transient($flashKey);
        }
        $applications = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'private',
            'posts_per_page' => 100,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);
        ?>
        <div class="wrap">
          <h1><?php esc_html_e('Demandes de restaurants — accompagnement TUC', 'tuc-core'); ?></h1>
          <p><?php esc_html_e('Le formulaire public ne collecte que le nom et la photo du menu. Vérifiez le numéro dans votre conversation WhatsApp avant de créer l’accès.', 'tuc-core'); ?></p>
          <?php if (in_array($notice, ['created', 'resent'], true) && $waUrl !== '') : ?>
            <div class="notice notice-success"><p><?php echo $notice === 'created' ? esc_html__('Compte restaurateur créé. Envoyez le lien personnel ci-dessous dans WhatsApp; il expire après 15 minutes et ne peut servir qu’une fois.', 'tuc-core') : esc_html__('Nouveau lien préparé. L’ancien lien a été invalidé; envoyez ce lien dans WhatsApp dans les 15 minutes.', 'tuc-core'); ?></p>
              <p><a class="button button-primary" href="<?php echo esc_url($waUrl); ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer"><?php esc_html_e('Ouvrir WhatsApp pour envoyer l’accès', 'tuc-core'); ?></a></p>
            </div>
          <?php elseif ($notice === 'error') : ?>
            <div class="notice notice-error"><p><?php esc_html_e('Création impossible : vérifiez le numéro WhatsApp et l’état de la demande.', 'tuc-core'); ?></p></div>
          <?php endif; ?>
          <?php if ($applications === []) : ?><p><?php esc_html_e('Aucune demande pour le moment.', 'tuc-core'); ?></p><?php endif; ?>
          <div class="tuc-onboarding-admin-list">
          <?php foreach ($applications as $application) :
              $state = (string) get_post_meta($application->ID, 'tuc_onboarding_state', true);
              $phone = (string) get_post_meta($application->ID, 'tuc_onboarding_phone', true);
              $phoneSource = (string) get_post_meta($application->ID, 'tuc_onboarding_phone_source', true);
              $photoId = (int) get_post_meta($application->ID, 'tuc_onboarding_menu_photo', true);
              $photoUrl = $photoId > 0 ? wp_get_attachment_image_url($photoId, 'medium') : false;
              $restaurantId = (int) get_post_meta($application->ID, 'tuc_onboarding_restaurant_id', true);
              $userId = (int) get_post_meta($application->ID, 'tuc_onboarding_user_id', true);
          ?>
            <article class="tuc-onboarding-admin-card">
              <?php if ($photoUrl) : ?><img src="<?php echo esc_url($photoUrl); ?>" alt="<?php echo esc_attr(sprintf(__('Photo du menu de %s', 'tuc-core'), $application->post_title)); ?>" loading="lazy"><?php endif; ?>
              <div class="tuc-onboarding-admin-copy">
                <h2><?php echo esc_html($application->post_title); ?></h2>
                <p><?php echo esc_html(sprintf(__('Réf. interne %1$s · code WhatsApp %2$s · état : %3$s', 'tuc-core'), (string) get_post_meta($application->ID, 'tuc_onboarding_reference', true), (string) get_post_meta($application->ID, 'tuc_onboarding_wa_code', true), $state)); ?></p>
                <?php if ($restaurantId > 0 && $userId > 0) : ?>
                  <p><?php echo esc_html(sprintf(__('Restaurant #%1$d · compte #%2$d', 'tuc-core'), $restaurantId, $userId)); ?></p>
                  <?php $restaurantEditUrl = get_edit_post_link($restaurantId, ''); ?>
                  <?php if ($restaurantEditUrl) : ?><p><?php esc_html_e('La fiche reste en brouillon et invisible au public jusqu’à sa vérification par l’équipe.', 'tuc-core'); ?> <a href="<?php echo esc_url($restaurantEditUrl); ?>"><?php esc_html_e('Compléter la fiche et publier', 'tuc-core'); ?></a></p><?php endif; ?>
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tuc-onboarding-provision-form">
                    <input type="hidden" name="action" value="tuc_renvoyer_acces_restaurant">
                    <input type="hidden" name="application_id" value="<?php echo esc_attr((string) $application->ID); ?>">
                    <?php wp_nonce_field('tuc_renvoyer_acces_restaurant_' . $application->ID); ?>
                    <button class="button" type="submit"><?php esc_html_e('Préparer un nouveau lien WhatsApp', 'tuc-core'); ?></button>
                  </form>
                <?php elseif ($state !== 'provisioned') : ?>
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tuc-onboarding-provision-form">
                    <input type="hidden" name="action" value="tuc_provisionner_restaurant">
                    <input type="hidden" name="application_id" value="<?php echo esc_attr((string) $application->ID); ?>">
                    <?php wp_nonce_field('tuc_provisionner_restaurant_' . $application->ID); ?>
                    <label for="tuc-onboarding-phone-<?php echo esc_attr((string) $application->ID); ?>"><?php esc_html_e('Numéro WhatsApp du contact', 'tuc-core'); ?></label>
                    <input id="tuc-onboarding-phone-<?php echo esc_attr((string) $application->ID); ?>" name="phone" type="tel" inputmode="tel" autocomplete="tel" value="<?php echo esc_attr($phone); ?>" placeholder="+212 6 12 34 56 78" required>
                    <small><?php
                      if ($phoneSource === 'whatsapp_webhook') {
                          esc_html_e('Numéro confirmé par le webhook WhatsApp.', 'tuc-core');
                      } elseif ($phoneSource === 'staff') {
                          esc_html_e('Numéro saisi et confirmé par l’équipe TUC.', 'tuc-core');
                      } else {
                          esc_html_e('À saisir après vérification dans votre conversation WhatsApp.', 'tuc-core');
                      }
                    ?></small>
                    <button class="button button-primary" type="submit"><?php esc_html_e('Créer le restaurant et préparer l’accès', 'tuc-core'); ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
          </div>
          <style>
            .tuc-onboarding-admin-list{display:grid;gap:16px;max-width:1000px;margin-top:20px}
            .tuc-onboarding-admin-card{display:grid;grid-template-columns:minmax(140px,220px) minmax(0,1fr);gap:18px;padding:16px;background:#fff;border:1px solid #dcdcde;border-radius:12px}
            .tuc-onboarding-admin-card img{width:100%;height:auto;max-height:260px;object-fit:contain;background:#f6f7f7}
            .tuc-onboarding-admin-copy h2{margin-top:0}.tuc-onboarding-provision-form{display:grid;gap:8px;max-width:420px;margin-top:14px}
            .tuc-onboarding-provision-form input{min-height:44px}.tuc-onboarding-provision-form button{justify-self:start;min-height:44px}
            @media(max-width:600px){.tuc-onboarding-admin-card{grid-template-columns:1fr}.tuc-onboarding-admin-card img{max-height:220px}}
          </style>
        </div>
        <?php
    }

    public static function provisionner_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès réservé à l’équipe TUC.', 'tuc-core'), '', ['response' => 403]);
        }
        $applicationId = absint($_POST['application_id'] ?? 0);
        check_admin_referer('tuc_provisionner_restaurant_' . $applicationId);
        $application = get_post($applicationId);
        if (!$application instanceof WP_Post || $application->post_type !== self::CPT
            || (string) get_post_meta($applicationId, 'tuc_onboarding_state', true) === 'provisioned') {
            self::retour_admin('error', $applicationId);
        }
        $phone = TUC_Compte::normaliser(sanitize_text_field((string) wp_unslash($_POST['phone'] ?? '')));
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $phone)) {
            self::retour_admin('error', $applicationId);
        }
        $verifiedPhone = (string) get_post_meta($applicationId, 'tuc_onboarding_phone', true);
        if ($verifiedPhone !== '' && !hash_equals($verifiedPhone, $phone)) {
            self::retour_admin('error', $applicationId);
        }
        if ($verifiedPhone === '') {
            update_post_meta($applicationId, 'tuc_onboarding_phone', $phone);
            update_post_meta($applicationId, 'tuc_onboarding_phone_verified_at', current_time('mysql', true));
            update_post_meta($applicationId, 'tuc_onboarding_phone_source', 'staff');
        }

        $user = TUC_Compte::par_telephone($phone);
        $newUser = false;
        if (!$user instanceof WP_User) {
            $login = 'tuc_' . $phone;
            if (username_exists($login)) {
                self::retour_admin('error', $applicationId);
            }
            $userId = wp_insert_user([
                'user_login' => $login,
                'user_pass' => wp_generate_password(40, true, true),
                'user_email' => $phone . '@restaurant.tuc.ma',
                'display_name' => '+' . $phone,
                'role' => 'tuc_restaurateur',
            ]);
            if (is_wp_error($userId)) {
                self::retour_admin('error', $applicationId);
            }
            $user = get_user_by('id', (int) $userId);
            $newUser = true;
        }
        if (!$user instanceof WP_User || user_can($user, 'manage_options')
            || array_diff((array) $user->roles, ['tuc_client', 'subscriber', 'tuc_restaurateur']) !== []) {
            self::retour_admin('error', $applicationId);
        }
        $assignedRestaurant = (int) get_user_meta($user->ID, 'tuc_resto_id', true);
        if ($assignedRestaurant > 0) {
            if ($newUser) {
                wp_delete_user($user->ID);
            }
            self::retour_admin('error', $applicationId);
        }

        $restaurantId = wp_insert_post([
            'post_type' => TUC_CPT::RESTO,
            'post_status' => 'draft',
            'post_title' => sanitize_text_field($application->post_title),
            'post_content' => '',
        ], true);
        if (is_wp_error($restaurantId)) {
            if ($newUser) {
                wp_delete_user($user->ID);
            }
            self::retour_admin('error', $applicationId);
        }
        $user->add_role('tuc_restaurateur');
        update_user_meta($user->ID, 'tuc_tel', $phone);
        update_user_meta($user->ID, 'tuc_tel_verifie', current_time('c'));
        update_user_meta($user->ID, 'tuc_tel_verification_source', (string) get_post_meta($applicationId, 'tuc_onboarding_phone_source', true));
        update_user_meta($user->ID, 'tuc_resto_id', (int) $restaurantId);
        $menuPhotoId = (int) get_post_meta($applicationId, 'tuc_onboarding_menu_photo', true);
        if ($menuPhotoId > 0) {
            update_post_meta((int) $restaurantId, 'tuc_onboarding_menu_photo', $menuPhotoId);
        }
        update_post_meta($applicationId, 'tuc_onboarding_restaurant_id', (int) $restaurantId);
        update_post_meta($applicationId, 'tuc_onboarding_user_id', $user->ID);
        update_post_meta($applicationId, 'tuc_onboarding_phone', $phone);
        update_post_meta($applicationId, 'tuc_onboarding_state', 'provisioned');

        $magicUrl = TUC_Compte::lien_magique($user->ID, home_url('/espace-pro/'));
        $message = sprintf(
            "Bienvenue chez TUC 👋\n\nVotre espace restaurateur « %s » est prêt.\nOuvrez votre accès personnel (lien à usage unique, valable 15 minutes) : %s\n\nNe transférez pas ce lien; demandez-en un nouveau à l’équipe s’il expire.",
            $application->post_title,
            $magicUrl
        );
        $waUrl = TUC_WA_Message::wa_url($phone, $message);
        set_transient('tuc_onboarding_wa_' . get_current_user_id() . '_' . $applicationId, $waUrl, 5 * MINUTE_IN_SECONDS);
        self::retour_admin('created', $applicationId);
    }

    public static function renvoyer_acces_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès réservé à l’équipe TUC.', 'tuc-core'), '', ['response' => 403]);
        }
        $applicationId = absint($_POST['application_id'] ?? 0);
        check_admin_referer('tuc_renvoyer_acces_restaurant_' . $applicationId);
        $application = get_post($applicationId);
        $userId = (int) get_post_meta($applicationId, 'tuc_onboarding_user_id', true);
        $restaurantId = (int) get_post_meta($applicationId, 'tuc_onboarding_restaurant_id', true);
        $phone = (string) get_post_meta($applicationId, 'tuc_onboarding_phone', true);
        $user = $userId > 0 ? get_user_by('id', $userId) : false;
        $restaurant = $restaurantId > 0 ? get_post($restaurantId) : null;
        if (!$application instanceof WP_Post || $application->post_type !== self::CPT
            || (string) get_post_meta($applicationId, 'tuc_onboarding_state', true) !== 'provisioned'
            || !$user instanceof WP_User || user_can($user, 'manage_options')
            || !$restaurant instanceof WP_Post || $restaurant->post_type !== TUC_CPT::RESTO
            || (int) get_user_meta($user->ID, 'tuc_resto_id', true) !== $restaurantId
            || $phone === '') {
            self::retour_admin('error', $applicationId);
        }
        $magicUrl = TUC_Compte::lien_magique($user->ID, home_url('/espace-pro/'));
        $message = sprintf(
            "Votre nouveau lien d’accès TUC pour « %s » (lien personnel, à usage unique, valable 15 minutes) : %s",
            $application->post_title,
            $magicUrl
        );
        $waUrl = TUC_WA_Message::wa_url($phone, $message);
        set_transient('tuc_onboarding_wa_' . get_current_user_id() . '_' . $applicationId, $waUrl, 5 * MINUTE_IN_SECONDS);
        self::retour_admin('resent', $applicationId);
    }

    private static function retour_admin(string $notice, int $applicationId): never
    {
        wp_safe_redirect(add_query_arg(
            ['post_type' => TUC_CPT::RESTO, 'page' => 'tuc-onboarding-restaurants', 'tuc_onboarding_notice' => $notice, 'application' => $applicationId],
            admin_url('edit.php')
        ));
        exit;
    }
}
