<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import de menu par CSV — l'OCR reste dehors.
 *
 * Pourquoi ce module existe : l'onboarding à 30 restaurants par jour ne tient pas
 * si chaque menu se ressaisit à la main. On photographie le menu, une IA le
 * transcrit en deux CSV, et ce module les importe. **Aucun OCR ici** : le plugin
 * ne devine rien, il valide et il écrit.
 *
 * Trois refus qui structurent tout le fichier :
 *
 * 1. **Rien ne s'écrit avant que tout soit validé.** La validation complète
 *    (dry-run) précède la première écriture. Pas de menu à moitié importé.
 * 2. **Aucune coercition silencieuse.** Le cœur WordPress force `min=1` quand un
 *    groupe est obligatoire et que min vaut 0 ; il ne rejette pas. Une ligne
 *    `obligatoire=oui;min=0` est donc refusée ICI, au niveau du CSV, sinon la
 *    donnée du client est modifiée sans que personne ne le voie.
 * 3. **Tout arrive en brouillon.** Un prix mal lu par une IA ne doit jamais être
 *    visible d'un client sans qu'un humain ait relu.
 *
 * Sur le « tout-ou-rien » : ce n'est PAS une transaction. Le motif n'est pas
 * InnoDB — il sait annuler du DML multi-tables. Le motif, c'est WordPress :
 * chaque `wp_insert_post` / `update_post_meta` / `wp_set_object_terms` déclenche
 * des hooks et invalide des caches hors transaction (objets, transients,
 * caches de pages, réécriture de permaliens). Un `ROLLBACK` rendrait donc la base
 * cohérente avec un état que les caches ne verraient jamais. D'où un **journal
 * d'écritures + compensation** : on note ce qu'on a créé et l'ancienne valeur de
 * ce qu'on a modifié, et on rejoue à l'envers en cas d'échec.
 * **Ne pas « optimiser » ce fichier avec une transaction** : ça paraîtrait plus
 * propre et ce serait faux.
 */
final class TUC_Import_CSV
{
    /** Version du format CSV accepté. Un fichier d'une autre version est refusé. */
    public const FORMAT = 1;

    /** Seuil d'alerte sur un prix, en MAD. Au-delà, l'humain doit confirmer. */
    public const SEUIL_ALERTE_PRIX = 500.0;

    /** Préfixe des métadonnées internes à l'import. */
    private const META_REF = 'tuc_import_ref';
    private const META_SOURCE = 'tuc_import_source';

    /** État conservé entre le dry-run et l'import réel (transient, par utilisateur). */
    private const TRANSIENT = 'tuc_import_csv_';

    /** Taille maximale d'un fichier CSV téléversé (octets). */
    private const TAILLE_MAX = 2097152; // 2 Mio

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_tuc_import_csv_telecharger', [self::class, 'telecharger_admin']);
        add_action('admin_post_tuc_import_csv_verifier', [self::class, 'verifier_admin']);
        add_action('admin_post_tuc_import_csv_executer', [self::class, 'executer_admin']);
    }

    public static function admin_menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . TUC_CPT::RESTO,
            'Importer un menu',
            'Importer un menu',
            'manage_options',
            'tuc-import-csv',
            [self::class, 'page_admin']
        );
    }

    // ---------------------------------------------------------------------
    // 1. Ce que le site propose réellement — jamais écrit en dur
    // ---------------------------------------------------------------------

    /**
     * Les catégories réelles de la taxonomie « tuc_categorie ».
     * Si elle est vide, on propose la liste usuelle en le DISANT : ces termes
     * seront créés à l'import, donc autant que l'humain le sache avant.
     *
     * @return array{termes: string[], depuis_le_site: bool}
     */
    public static function categories_disponibles(): array
    {
        $termes = get_terms([
            'taxonomy'   => 'tuc_categorie',
            'hide_empty' => false,
            'fields'     => 'names',
        ]);
        if (is_wp_error($termes) || $termes === []) {
            return [
                'termes'         => ['Entrées', 'Salades', 'Sandwichs', 'Tacos', 'Burgers', 'Pizzas',
                                     'Grillades', 'Poissons', 'Pâtes', 'Desserts', 'Boissons', 'Cafés'],
                'depuis_le_site' => false,
            ];
        }
        $noms = array_values(array_map('strval', $termes));
        sort($noms, SORT_NATURAL | SORT_FLAG_CASE);
        return ['termes' => $noms, 'depuis_le_site' => true];
    }

    /**
     * Les noms d'images réels du thème, bannières exclues.
     *
     * Sert de garde anti-hallucination : une IA qui invente `sushi-poulet` doit
     * être refusée, pas silencieusement remplacée par une photo de tagine comme
     * le fait `tuc_img()`. (À noter : cette colonne n'entre PAS dans le CSV v1 —
     * voir le journal. `tuc_img` n'est pas déclaré sur les plats.)
     *
     * @return string[]
     */
    public static function images_valides(): array
    {
        $dossier = TUC_DIR . '/assets/img';
        $noms = [];
        foreach ((array) glob($dossier . '/*.jpg') as $chemin) {
            $nom = basename((string) $chemin, '.jpg');
            if (str_starts_with($nom, 'banner-') || str_starts_with($nom, 'hero-banner')) {
                continue;
            }
            $noms[] = $nom;
        }
        sort($noms, SORT_NATURAL | SORT_FLAG_CASE);
        return $noms;
    }

    /** @return WP_Post[] */
    public static function restaurants_cibles(): array
    {
        return get_posts([
            'post_type'      => TUC_CPT::RESTO,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);
    }

    // ---------------------------------------------------------------------
    // 2. Lecture des CSV — les deux côtés, sinon ça casse dans un sens
    // ---------------------------------------------------------------------

    /**
     * Lit un CSV en tableau associatif.
     *
     * Contrat, identique à celui de l'écriture :
     *  - BOM UTF-8 retiré s'il est présent (Excel en met un, PHP le laisse) ;
     *  - séparateur point-virgule OU virgule, détecté sur la première ligne ;
     *  - fin de ligne \r\n, \n ou \r ;
     *  - champs entre guillemets avec guillemets doublés (RFC 4180).
     *
     * @return array{entetes: string[], lignes: array<int, array<string, string>>}|WP_Error
     */
    public static function lire_csv(string $contenu): array|WP_Error
    {
        // Le BOM doit partir AVANT tout : sinon la première en-tête devient
        // « \xEF\xBB\xBFreference » et la colonne « reference » n'existe plus.
        if (str_starts_with($contenu, "\xEF\xBB\xBF")) {
            $contenu = substr($contenu, 3);
        }
        $contenu = str_replace(["\r\n", "\r"], "\n", $contenu);
        if (trim($contenu) === '') {
            return new WP_Error('tuc_csv_vide', 'Le fichier est vide.');
        }

        $separateur = self::detecter_separateur($contenu);
        $entetes = [];
        $lignes = [];
        $numero = 0;

        foreach (explode("\n", $contenu) as $brute) {
            $numero++;
            if (trim($brute) === '') {
                continue;
            }
            $champs = self::decouper($brute, $separateur);
            if ($entetes === []) {
                $entetes = array_map(static fn($v): string => trim((string) $v), $champs);
                continue;
            }
            if (count($champs) !== count($entetes)) {
                return new WP_Error(
                    'tuc_csv_colonnes',
                    sprintf(
                        'Ligne %d : %d colonne(s) au lieu de %d. Un point-virgule manque, ou un nom contient le séparateur sans guillemets.',
                        $numero,
                        count($champs),
                        count($entetes)
                    )
                );
            }
            $assoc = [];
            foreach ($entetes as $i => $entete) {
                $assoc[$entete] = trim((string) $champs[$i]);
            }
            $lignes[$numero] = $assoc;
        }

        if ($entetes === []) {
            return new WP_Error('tuc_csv_vide', 'Aucune en-tête trouvée.');
        }
        return ['entetes' => $entetes, 'lignes' => $lignes];
    }

    private static function detecter_separateur(string $contenu): string
    {
        $premiere = strtok($contenu, "\n");
        $premiere = is_string($premiere) ? $premiere : '';
        return substr_count($premiere, ';') >= substr_count($premiere, ',') ? ';' : ',';
    }

    /** @return string[] */
    private static function decouper(string $ligne, string $separateur): array
    {
        $champs = [];
        $courant = '';
        $entreGuillemets = false;
        $longueur = strlen($ligne);
        for ($i = 0; $i < $longueur; $i++) {
            $c = $ligne[$i];
            if ($c === '"') {
                if ($entreGuillemets && ($ligne[$i + 1] ?? '') === '"') {
                    $courant .= '"';
                    $i++;
                } else {
                    $entreGuillemets = !$entreGuillemets;
                }
                continue;
            }
            if ($c === $separateur && !$entreGuillemets) {
                $champs[] = $courant;
                $courant = '';
                continue;
            }
            $courant .= $c;
        }
        $champs[] = $courant;
        return $champs;
    }

    /**
     * Interprète un prix écrit par un humain ou par une IA.
     *
     * Accepte « 45 », « 45.00 », « 45,50 » (virgule décimale) et les espaces
     * insécables que les tableurs ajoutent. Refuse tout le reste — **y compris
     * le marqueur A_VERIFIER** : il doit devenir une erreur de ligne, jamais un 0.
     *
     * @return float|WP_Error
     */
    public static function lire_prix(string $brut)
    {
        $valeur = trim($brut);
        $valeur = str_replace(["\xC2\xA0", ' ', "\xE2\x80\xAF"], '', $valeur); // espaces, insécable, fine insécable
        if ($valeur === '') {
            return new WP_Error('tuc_csv_prix_vide', 'prix absent');
        }
        if (preg_match('/^[0-9]+(?:[.,][0-9]{1,2})?$/', $valeur) !== 1) {
            return new WP_Error(
                'tuc_csv_prix',
                sprintf('« %s » n’est pas un prix. Un nombre avec point ou virgule décimale, sans devise.', $brut)
            );
        }
        return (float) str_replace(',', '.', $valeur);
    }

    // ---------------------------------------------------------------------
    // 3. Validation — le cœur du travail
    // ---------------------------------------------------------------------

    /**
     * Analyse complète, SANS AUCUNE ÉCRITURE.
     *
     * @param array{lignes: array<int, array<string,string>>} $plats
     * @param array{lignes: array<int, array<string,string>>}|null $options null = pas de fichier d'options
     * @return array{
     *   lignes: list<array<string,mixed>>,
     *   options: list<array<string,mixed>>,
     *   erreurs: int,
     *   avertissements: int,
     *   resume: array<string,mixed>,
     *   bloquant: bool
     * }
     */
    public static function analyser(array $plats, ?array $options, int $resto_id, ?float $seuil = null): array
    {
        $seuil = $seuil ?? self::SEUIL_ALERTE_PRIX;
        $lignes = [];
        $erreurs = 0;
        $avertissements = 0;
        $references = [];
        $prix = [];

        $existants = self::indexer_existants($resto_id);

        foreach ($plats['lignes'] as $numero => $ligne) {
            $l = self::analyser_ligne_plat($numero, $ligne, $resto_id, $existants, $seuil);
            if ($l['reference'] !== '') {
                if (isset($references[$l['reference']])) {
                    $l['erreurs'][] = sprintf('référence « %s » déjà utilisée ligne %d', $l['reference'], $references[$l['reference']]);
                } else {
                    $references[$l['reference']] = $numero;
                }
            }
            if (is_float($l['prix'])) {
                $prix[] = $l['prix'];
            }
            $erreurs += count($l['erreurs']);
            $avertissements += count($l['avertissements']);
            $lignes[] = $l;
        }

        // ---- options ----
        $lignesOptions = [];
        $groupes = [];
        if ($options !== null) {
            foreach ($options['lignes'] as $numero => $ligne) {
                $ref = $ligne['plat_reference'] ?? '';
                if ($ref !== '' && !isset($references[$ref])) {
                    $lignesOptions[] = [
                        'numero'   => $numero,
                        'reference' => $ref,
                        'erreurs'  => [sprintf('plat_reference « %s » absent de plats.csv', $ref)],
                        'avertissements' => [],
                    ];
                    $erreurs++;
                    continue;
                }
                $lignesOptions[] = [
                    'numero'    => $numero,
                    'reference' => $ref,
                    'erreurs'   => [],
                    'avertissements' => [],
                ];
                $groupes[$ref][] = $ligne;
            }
        }

        // La cohérence d'un groupe ne se juge qu'une fois toutes ses lignes lues :
        // c'est la divergence ENTRE lignes qui est le piège.
        foreach ($groupes as $ref => $lignesDuPlat) {
            foreach (self::analyser_groupes($ref, $lignesDuPlat) as $probleme) {
                foreach ($lignesOptions as $i => $ligneOption) {
                    if ($ligneOption['reference'] === $ref) {
                        $lignesOptions[$i]['erreurs'][] = $probleme;
                    }
                }
                $erreurs++;
            }
        }

        $nbGroupes = 0;
        $nbChoix = 0;
        foreach ($groupes as $ref => $lignesDuPlat) {
            $parGroupe = [];
            foreach ($lignesDuPlat as $ligne) {
                $parGroupe[(string) ($ligne['groupe_id'] ?? '')][] = $ligne;
            }
            $nbGroupes += count($parGroupe);
            $nbChoix += count($lignesDuPlat);
        }

        return [
            'lignes'         => $lignes,
            'options'        => $lignesOptions,
            'erreurs'        => $erreurs,
            'avertissements' => $avertissements,
            'bloquant'       => $erreurs > 0,
            'resume'         => [
                'plats'    => count($lignes),
                'groupes'  => $nbGroupes,
                'choix'    => $nbChoix,
                'prix_min' => $prix === [] ? null : min($prix),
                'prix_max' => $prix === [] ? null : max($prix),
                'seuil'    => $seuil,
            ],
        ];
    }

    /**
     * Indexe les plats déjà importés pour ce restaurant : reference => post_id.
     *
     * @return array<string,int>
     */
    private static function indexer_existants(int $resto_id): array
    {
        $posts = get_posts([
            'post_type'      => TUC_CPT::ITEM,
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [['key' => 'tuc_resto_id', 'value' => $resto_id, 'compare' => '=', 'type' => 'NUMERIC']],
        ]);
        $index = [];
        foreach ($posts as $id) {
            $ref = (string) get_post_meta((int) $id, self::META_REF, true);
            if ($ref !== '') {
                $index[$ref] = (int) $id;
            }
        }
        return $index;
    }

    /** @param array<string,string> $ligne @param array<string,int> $existants */
    private static function analyser_ligne_plat(int $numero, array $ligne, int $resto_id, array $existants, float $seuil): array
    {
        $resultat = [
            'numero'    => $numero,
            'reference' => (string) ($ligne['reference'] ?? ''),
            'nom'       => (string) ($ligne['nom'] ?? ''),
            'action'    => 'créer',
            'prix_brut' => (string) ($ligne['prix'] ?? ''),
            'prix'      => null,
            'erreurs'   => [],
            'avertissements' => [],
            'categorie' => (string) ($ligne['categorie'] ?? ''),
            'existant'  => null,
        ];

        $ref = $resultat['reference'];
        if ($ref === '') {
            $resultat['erreurs'][] = 'reference vide';
        } elseif (sanitize_title($ref) !== $ref) {
            $resultat['erreurs'][] = sprintf('reference « %s » : minuscules, chiffres et tirets uniquement', $ref);
        }

        if (isset($existants[$ref])) {
            $resultat['action'] = 'mettre à jour';
            $resultat['existant'] = $existants[$ref];
        }

        // Le nom de PLAT, lui, peut être en arabe : c'est la seule colonne
        // textuelle libre. Les limites du cœur sont en OCTETS (strlen), donc
        // 400 octets = 200 caractères arabes (2 octets par caractère en UTF-8).
        if ($resultat['nom'] === '') {
            $resultat['erreurs'][] = 'nom vide';
        } elseif (strlen($resultat['nom']) > 400) {
            $resultat['erreurs'][] = sprintf(
                'nom trop long : %d octets (limite 400, soit ~200 caractères arabes)',
                strlen($resultat['nom'])
            );
        }
        if (strlen((string) ($ligne['description'] ?? '')) > 4000) {
            $resultat['erreurs'][] = 'description trop longue (limite 4000 octets)';
        }

        $prix = self::lire_prix($resultat['prix_brut']);
        if (is_wp_error($prix)) {
            $resultat['erreurs'][] = $prix->get_error_message();
        } else {
            $resultat['prix'] = $prix;
            if ($prix > $seuil) {
                $resultat['avertissements'][] = sprintf(
                    'prix %.2f MAD au-dessus du seuil %.0f — vérifiez qu’une virgule n’a pas été lue comme un zéro',
                    $prix,
                    $seuil
                );
            }
        }

        $promo = (string) ($ligne['promo_pct'] ?? '0');
        if ($promo === '') {
            $promo = '0';
        }
        if (!is_numeric($promo) || (int) $promo < 0 || (int) $promo > 100) {
            $resultat['erreurs'][] = 'promo_pct doit être un entier entre 0 et 100';
        }

        $dispo = strtolower((string) ($ligne['disponible'] ?? 'oui'));
        if (!in_array($dispo, ['oui', 'non', '1', '0', 'true', 'false', ''], true)) {
            $resultat['erreurs'][] = 'disponible doit valoir oui ou non';
        }

        // Le statut n'est JAMAIS demandé au CSV : il est forcé à brouillon.
        // Et l'annoncer, parce qu'un plat déjà publié qui redevient brouillon
        // surprend le restaurateur.
        if ($resultat['existant'] !== null) {
            $statut = get_post_status($resultat['existant']);
            if ($statut === 'publish') {
                $resultat['avertissements'][] = 'ce plat est actuellement PUBLIÉ ; il repassera en brouillon après l’import';
            }
            if (self::a_options_conditionnelles($resultat['existant'])) {
                $resultat['erreurs'][] = 'options conditionnelles présentes : à modifier à la main (non gérées par le CSV v1)';
            }
        }

        $categories = self::categories_disponibles();
        $categorie = $resultat['categorie'];
        if ($categorie === '') {
            $resultat['avertissements'][] = 'aucune catégorie : le plat sera importé sans classement';
        } elseif ($categories['depuis_le_site']
            && !in_array($categorie, $categories['termes'], true)
            && !in_array(mb_strtolower($categorie), array_map('mb_strtolower', $categories['termes']), true)) {
            $resultat['avertissements'][] = sprintf('catégorie « %s » inconnue du site : elle sera créée', $categorie);
        }

        return $resultat;
    }

    /** Un plat a-t-il des conditions d'affichage saisies à la main ? */
    public static function a_options_conditionnelles(int $post_id): bool
    {
        $definition = get_post_meta($post_id, 'tuc_options', true);
        if (!is_array($definition) || !isset($definition['groups']) || !is_array($definition['groups'])) {
            return false;
        }
        foreach ($definition['groups'] as $groupe) {
            if (is_array($groupe) && !empty($groupe['visible_if'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Valide un ensemble de lignes d'options appartenant au même plat.
     *
     * C'est ici que se joue le piège de coercition : `normaliser_definition()`
     * force min=1 quand obligatoire=oui, au lieu de refuser. On refuse donc AVANT
     * lui, et on refuse aussi la divergence entre deux lignes d'un même groupe
     * (le cœur, lui, garderait la dernière valeur lue — en silence).
     *
     * @param array<int, array<string,string>> $lignes
     * @return string[] messages d'erreur
     */
    public static function analyser_groupes(string $reference, array $lignes): array
    {
        $erreurs = [];
        $parGroupe = [];
        foreach ($lignes as $numero => $ligne) {
            $gid = (string) ($ligne['groupe_id'] ?? '');
            $parGroupe[$gid]['lignes'][] = $ligne;
            $parGroupe[$gid]['numeros'][] = $numero;
        }

        if (count($parGroupe) > 12) {
            $erreurs[] = sprintf('%s : %d groupes, maximum 12', $reference, count($parGroupe));
        }

        foreach ($parGroupe as $gid => $contenu) {
            $premier = $contenu['lignes'][0];
            $numeros = $contenu['numeros'];
            $libelle = sprintf('%s/%s (lignes %s)', $reference, (string) $gid, implode(', ', $numeros));

            if ($gid === '' || sanitize_key($gid) !== $gid) {
                $erreurs[] = sprintf('%s : groupe_id invalide — minuscules, chiffres et tirets', $libelle);
            }
            if (strlen((string) $gid) > 40) {
                $erreurs[] = sprintf('%s : groupe_id trop long', $libelle);
            }
            if ((string) ($premier['groupe_nom'] ?? '') === '') {
                $erreurs[] = sprintf('%s : groupe_nom vide', $libelle);
            }

            $obligatoire = self::booleen((string) ($premier['obligatoire'] ?? 'non'));
            $min = (string) ($premier['min'] ?? '');
            $max = (string) ($premier['max'] ?? '');
            if (!preg_match('/^[0-9]+$/', $min) || !preg_match('/^[0-9]+$/', $max)) {
                $erreurs[] = sprintf('%s : min et max doivent être des entiers positifs', $libelle);
            }

            // Le piège : obligatoire=oui avec min=0. Le cœur ne rejette pas, il
            // force min=1. On refuse ici pour que la donnée du client soit
            // respectée ou corrigée, jamais modifiée en douce.
            if ($obligatoire && $min === '0') {
                $erreurs[] = sprintf(
                    '%s : obligatoire=oui avec min=0 est incohérent (le cœur forcerait min=1 en silence). Écrivez min=1, ou obligatoire=non.',
                    $libelle
                );
            }

            // Divergence entre les lignes d'un même groupe.
            foreach (['groupe_nom', 'obligatoire', 'min', 'max'] as $colonne) {
                foreach ($contenu['lignes'] as $ligne) {
                    if ((string) ($ligne[$colonne] ?? '') !== (string) ($premier[$colonne] ?? '')) {
                        $erreurs[] = sprintf(
                            '%s : « %s » diffère entre les lignes du même groupe (« %s » puis « %s »)',
                            $libelle,
                            $colonne,
                            (string) ($premier[$colonne] ?? ''),
                            (string) ($ligne[$colonne] ?? '')
                        );
                        break 2;
                    }
                }
            }

            if (count($contenu['lignes']) > 20) {
                $erreurs[] = sprintf('%s : %d choix dans un seul groupe, maximum 20', $libelle, count($contenu['lignes']));
            }

            $choixVus = [];
            $disponibles = 0;
            foreach ($contenu['lignes'] as $ligne) {
                $cid = (string) ($ligne['choix_id'] ?? '');
                if ($cid === '' || sanitize_key($cid) !== $cid) {
                    $erreurs[] = sprintf('%s : choix_id invalide — minuscules, chiffres et tirets', $libelle);
                }
                if (strlen($cid) > 40) {
                    $erreurs[] = sprintf('%s : choix_id « %s » trop long', $libelle, $cid);
                }
                if (isset($choixVus[$cid])) {
                    $erreurs[] = sprintf('%s : choix_id « %s » en double dans le groupe', $libelle, $cid);
                }
                $choixVus[$cid] = true;
                if ((string) ($ligne['choix_nom'] ?? '') === '') {
                    $erreurs[] = sprintf('%s : choix_nom vide pour « %s »', $libelle, $cid);
                }
                $supplement = self::lire_prix((string) ($ligne['supplement_prix'] ?? '0'));
                if (is_wp_error($supplement)) {
                    $erreurs[] = sprintf('%s : supplément de « %s » invalide — %s', $libelle, $cid, $supplement->get_error_message());
                } elseif ($supplement > 10000) {
                    $erreurs[] = sprintf('%s : supplément de « %s » au-dessus de 10 000 MAD', $libelle, $cid);
                }
                $dispoChoix = strtolower((string) ($ligne['disponible'] ?? 'oui'));
                if (!in_array($dispoChoix, ['oui', 'non', '1', '0', 'true', 'false', ''], true)) {
                    $erreurs[] = sprintf('%s : disponible de « %s » doit valoir oui ou non', $libelle, $cid);
                }
                if (!in_array($dispoChoix, ['non', '0', 'false'], true)) {
                    $disponibles++;
                }
            }

            if (preg_match('/^[0-9]+$/', $min) && preg_match('/^[0-9]+$/', $max)) {
                $minI = (int) $min;
                $maxI = (int) $max;
                if ($maxI < 1 || $minI > $maxI) {
                    $erreurs[] = sprintf('%s : min=%d et max=%d incohérents (max ≥ 1 et min ≤ max)', $libelle, $minI, $maxI);
                }
                if ($maxI > count($contenu['lignes'])) {
                    $erreurs[] = sprintf('%s : max=%d mais seulement %d choix', $libelle, $maxI, count($contenu['lignes']));
                }
                if ($minI > $disponibles) {
                    $erreurs[] = sprintf('%s : min=%d mais seulement %d choix disponibles', $libelle, $minI, $disponibles);
                }
            }
        }

        return $erreurs;
    }

    private static function booleen(string $valeur): bool
    {
        return in_array(strtolower(trim($valeur)), ['oui', '1', 'true', 'vrai'], true);
    }

    // ---------------------------------------------------------------------
    // 4. Écriture — mesurée et compensable
    // ---------------------------------------------------------------------

    /**
     * Construit le payload attendu par `TUC_Espace_Pro::valider_payload()`.
     *
     * Un seul validateur : celui de la saisie manuelle. Le dupliquer aurait fait
     * diverger les deux chemins, et c'est toujours l'import qu'on aurait oublié
     * de corriger.
     *
     * @param array<string,string> $ligne ligne CSV
     * @param array<int, array<string,string>> $lignesOptions toutes les lignes d'options du fichier
     */
    public static function construire_payload(array $ligne, array $lignesOptions): array
    {
        $prix = self::lire_prix((string) ($ligne['prix'] ?? ''));
        $promo = (string) ($ligne['promo_pct'] ?? '0');
        $dispo = strtolower((string) ($ligne['disponible'] ?? 'oui'));

        return [
            'title'       => (string) ($ligne['nom'] ?? ''),
            'description' => (string) ($ligne['description'] ?? ''),
            'price'       => is_wp_error($prix) ? 0 : $prix,
            'promo_pct'   => $promo === '' ? 0 : (int) $promo,
            'available'   => !in_array($dispo, ['non', '0', 'false'], true),
            // Forcé, jamais lu du CSV : un prix non relu ne doit pas être public.
            'status'      => 'draft',
            'options'     => self::construire_options((string) ($ligne['reference'] ?? ''), $lignesOptions),
        ];
    }

    /**
     * Regroupe les lignes d'options par (plat_reference, groupe_id) et
     * reconstruit la structure imbriquée attendue par normaliser_definition().
     *
     * L'ordre d'apparition dans le fichier est conservé : `visible_if` désigne un
     * groupe ANTÉRIEUR, donc l'ordre est porteur de sens.
     *
     * @param array<int, array<string,string>> $lignes
     */
    public static function construire_options(string $reference, array $lignes): array
    {
        $groupes = [];
        foreach ($lignes as $ligne) {
            if ((string) ($ligne['plat_reference'] ?? '') !== $reference) {
                continue;
            }
            $gid = (string) ($ligne['groupe_id'] ?? '');
            if (!isset($groupes[$gid])) {
                $groupes[$gid] = [
                    'id'       => $gid,
                    'name'     => (string) ($ligne['groupe_nom'] ?? ''),
                    'required' => self::booleen((string) ($ligne['obligatoire'] ?? 'non')),
                    'min'      => (int) ($ligne['min'] ?? 0),
                    'max'      => (int) ($ligne['max'] ?? 1),
                    'choices'  => [],
                ];
            }
            $groupes[$gid]['choices'][] = [
                'id'          => (string) ($ligne['choix_id'] ?? ''),
                'name'        => (string) ($ligne['choix_nom'] ?? ''),
                'price_delta' => (float) str_replace(',', '.', (string) ($ligne['supplement_prix'] ?? '0')),
                'available'   => !in_array(strtolower((string) ($ligne['disponible'] ?? 'oui')), ['non', '0', 'false'], true),
            ];
        }

        if ($groupes === []) {
            return [];
        }
        return ['version' => TUC_Options::VERSION, 'groups' => array_values($groupes)];
    }

    /**
     * Écrit réellement le menu, en compensant tout échec.
     *
     * Trois règles :
     *  - tout a été validé avant (l'analyse a déjà tourné) ;
     *  - chaque écriture est journalisée avec son inverse ;
     *  - un échec rejoue le journal à l'envers. Aucune écriture partielle.
     *
     * @param array<string, array<string,string>> $plats  reference => ligne
     * @param array<int, array<string,string>> $options
     * @return array{crees:int, maj:int, journal:list<string>}|WP_Error
     */
    public static function importer(array $plats, array $options, int $resto_id, bool $ecraserOptions = false): array|WP_Error
    {
        if (!get_post($resto_id) instanceof WP_Post) {
            return new WP_Error('tuc_import_resto', 'Restaurant cible introuvable.');
        }

        $journal = [];
        $crees = 0;
        $maj = 0;
        $existants = self::indexer_existants($resto_id);

        foreach ($plats as $reference => $ligne) {
            $payload = self::construire_payload($ligne, $options);
            $existant = isset($existants[$reference]) ? get_post($existants[$reference]) : null;
            if ($existant instanceof WP_Post && $existant->post_type !== TUC_CPT::ITEM) {
                $existant = null;
            }

            // Le dry-run bloquait déjà ces plats, mais un import direct (CLI,
            // appel programmatique, ou dry-run contourné) ne vérifiait RIEN :
            // le garde n'existait qu'à l'affichage. Constaté par le cas 15c.
            if ($existant instanceof WP_Post && self::a_options_conditionnelles($existant->ID) && !$ecraserOptions) {
                self::compenser($journal);
                return new WP_Error(
                    'tuc_import_condition',
                    sprintf(
                        '%s : ce plat a des options conditionnelles saisies à la main. Le format CSV v1 ne sait pas les représenter ; import interrompu plutôt que de les écraser en silence.',
                        $reference
                    )
                );
            }

            $champs = TUC_Espace_Pro::valider_payload($payload, $existant instanceof WP_Post ? $existant : null);
            if (is_wp_error($champs)) {
                self::compenser($journal);
                return new WP_Error(
                    'tuc_import_validation',
                    sprintf('%s : %s', $reference, $champs->get_error_message())
                );
            }

            if ($existant instanceof WP_Post) {
                $avant = [
                    'title'    => $existant->post_title,
                    'content'  => $existant->post_content,
                    'status'   => $existant->post_status,
                    'meta'     => self::meta_snapshot($existant->ID),
                ];
                $journal[] = ['type' => 'maj', 'id' => $existant->ID, 'avant' => $avant];

                $resultat = wp_update_post([
                    'ID'           => $existant->ID,
                    'post_title'   => $champs['title'],
                    'post_content' => $champs['description'],
                    'post_status'  => 'draft', // forcé, jamais celui du CSV ni l'ancien
                ], true);
                if (is_wp_error($resultat)) {
                    self::compenser($journal);
                    return $resultat;
                }
                $post_id = $existant->ID;
                $maj++;
            } else {
                $resultat = wp_insert_post([
                    'post_type'    => TUC_CPT::ITEM,
                    'post_title'   => $champs['title'],
                    'post_content' => $champs['description'],
                    'post_status'  => 'draft',
                ], true);
                if (is_wp_error($resultat) || (int) $resultat <= 0) {
                    self::compenser($journal);
                    return is_wp_error($resultat) ? $resultat : new WP_Error('tuc_import_insert', 'Création refusée pour ' . $reference);
                }
                $post_id = (int) $resultat;
                $journal[] = ['type' => 'creation', 'id' => $post_id];
                $crees++;
            }

            update_post_meta($post_id, 'tuc_prix', $champs['price']);
            update_post_meta($post_id, 'tuc_promo_pct', $champs['promo_pct']);
            update_post_meta($post_id, 'tuc_dispo', $champs['available'] ? '1' : '0');
            update_post_meta($post_id, 'tuc_options', $champs['options']);
            update_post_meta($post_id, 'tuc_options_schema_version', TUC_Options::VERSION);
            // `tuc_resto_id` — et surtout PAS `tuc_resto`, qui est la clé des
            // COMMANDES. Un plat écrit avec `tuc_resto` serait invisible dans
            // /menu/{slug} et dans /pro/menu : un menu fantôme, sans erreur.
            update_post_meta($post_id, 'tuc_resto_id', $resto_id);
            update_post_meta($post_id, self::META_REF, $reference);
            update_post_meta($post_id, self::META_SOURCE, 'csv');

            $categorie = trim((string) ($ligne['categorie'] ?? ''));
            if ($categorie !== '') {
                $term = term_exists($categorie, 'tuc_categorie');
                if (!is_array($term) && !is_int($term)) {
                    $term = wp_insert_term($categorie, 'tuc_categorie');
                }
                $term_id = is_array($term) ? (int) $term['term_id'] : (int) $term;
                if ($term_id > 0) {
                    wp_set_object_terms($post_id, [$term_id], 'tuc_categorie', false);
                }
            }
        }

        return ['crees' => $crees, 'maj' => $maj, 'journal' => array_map(
            static fn(array $e): string => $e['type'] . ' #' . $e['id'],
            $journal
        )];
    }

    /** @return array<string, mixed> */
    private static function meta_snapshot(int $post_id): array
    {
        $cles = ['tuc_prix', 'tuc_promo_pct', 'tuc_dispo', 'tuc_options',
                 'tuc_options_schema_version', 'tuc_resto_id', self::META_REF, self::META_SOURCE];
        $snapshot = [];
        foreach ($cles as $cle) {
            $snapshot[$cle] = get_post_meta($post_id, $cle, true);
        }
        return $snapshot;
    }

    /**
     * Rejoue le journal à l'envers.
     *
     * Ce n'est pas une transaction : c'est une compensation. Voir l'en-tête du
     * fichier pour le motif (hooks et caches WordPress, pas InnoDB).
     *
     * @param list<array<string,mixed>> $journal
     */
    private static function compenser(array $journal): void
    {
        foreach (array_reverse($journal) as $entree) {
            if (($entree['type'] ?? '') === 'creation') {
                wp_delete_post((int) $entree['id'], true);
                continue;
            }
            if (($entree['type'] ?? '') === 'maj') {
                $avant = $entree['avant'];
                wp_update_post([
                    'ID'           => (int) $entree['id'],
                    'post_title'   => $avant['title'],
                    'post_content' => $avant['content'],
                    'post_status'  => $avant['status'],
                ]);
                foreach ((array) $avant['meta'] as $cle => $valeur) {
                    if ($valeur === '' || $valeur === null) {
                        delete_post_meta((int) $entree['id'], (string) $cle);
                    } else {
                        update_post_meta((int) $entree['id'], (string) $cle, $valeur);
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // 5. Modèles téléchargeables — générés depuis l'état réel du site
    // ---------------------------------------------------------------------

    public static function csv_plats(): string
    {
        $categories = self::categories_disponibles();
        $lignes = [
            ['reference', 'nom', 'description', 'prix', 'categorie', 'promo_pct', 'disponible'],
            ['tacos-poulet', 'Tacos poulet', 'Sauce fromagère, frites maison', '45.00', $categories['termes'][3] ?? 'Tacos', '0', 'oui'],
            ['burger-classic', 'Burger classic', 'Steak 150g, cheddar', '52.00', $categories['termes'][4] ?? 'Burgers', '0', 'oui'],
        ];
        return self::ecrire_csv($lignes);
    }

    public static function csv_options(): string
    {
        $lignes = [
            ['plat_reference', 'groupe_id', 'groupe_nom', 'obligatoire', 'min', 'max', 'choix_id', 'choix_nom', 'supplement_prix', 'disponible'],
            ['tacos-poulet', 'taille', 'Taille', 'oui', '1', '1', 'm', 'M', '0.00', 'oui'],
            ['tacos-poulet', 'taille', 'Taille', 'oui', '1', '1', 'l', 'L', '10.00', 'oui'],
            ['tacos-poulet', 'taille', 'Taille', 'oui', '1', '1', 'xl', 'XL', '18.00', 'oui'],
            ['tacos-poulet', 'sauce', 'Sauces', 'non', '0', '2', 'algerienne', 'Algérienne', '0.00', 'oui'],
            ['tacos-poulet', 'sauce', 'Sauces', 'non', '0', '2', 'fromagere', 'Fromagère', '5.00', 'oui'],
            ['tacos-poulet', 'supplement', 'Suppléments', 'non', '0', '3', 'fromage', 'Fromage sup.', '6.00', 'oui'],
        ];
        return self::ecrire_csv($lignes);
    }

    /**
     * Écrit un CSV **avec BOM**, séparateur point-virgule.
     *
     * Le BOM et le point-virgule ne sont pas décoratifs : sans eux, Excel en
     * français ouvre le fichier avec des accents cassés et une seule colonne.
     * Le BOM est retiré à la lecture (voir `lire_csv`).
     *
     * @param list<list<string>> $lignes
     */
    private static function ecrire_csv(array $lignes): string
    {
        $sortie = "\xEF\xBB\xBF";
        foreach ($lignes as $ligne) {
            $sortie .= implode(';', array_map([self::class, 'echapper'], $ligne)) . "\r\n";
        }
        return $sortie;
    }

    private static function echapper(string $valeur): string
    {
        if (strpbrk($valeur, ';"' . "\n") === false) {
            return $valeur;
        }
        return '"' . str_replace('"', '""', $valeur) . '"';
    }

    /** La consigne à coller dans DeepSeek/Claude, avec les valeurs RÉELLES du site. */
    public static function consigne_ia(): string
    {
        $categories = self::categories_disponibles();
        $listeCategories = implode(', ', $categories['termes']);
        $noteCategories = $categories['depuis_le_site']
            ? ''
            : "\n(Le site n'a encore aucune catégorie : celles-ci seront créées à l'import.)";

        return <<<TXT
Tu transcris un menu de restaurant à partir des photos jointes.
Rends DEUX fichiers CSV, UTF-8, séparateur point-virgule, en-têtes exactes ci-dessous.

FICHIER 1 : plats.csv
reference;nom;description;prix;categorie;promo_pct;disponible
- reference : identifiant stable, minuscules sans accent, chiffres et tirets (ex. tacos-poulet). Unique.
- nom : le nom du plat TEL QU'IL EST ÉCRIT sur le menu. Garde l'arabe si le menu est
  en arabe, ne translittère pas. N'ajoute pas de mention marketing.
  Attention : 200 caractères maximum (la limite du site est de 400 octets, et un
  caractère arabe en occupe 2).
- description : courte, factuelle, en français. Vide si le menu ne dit rien.
- prix : nombre avec POINT décimal, sans devise ni espace (ex. 45.00).
- categorie : choisis UNIQUEMENT dans cette liste : {$listeCategories}{$noteCategories}
- promo_pct : 0, sauf promotion explicitement écrite sur le menu.
- disponible : oui, sauf mention d'épuisement.
- Il n'y a PAS de colonne image. N'invente aucun nom de photo.

FICHIER 2 : options.csv (une ligne par choix ; laisse-le vide s'il n'y a aucune option)
plat_reference;groupe_id;groupe_nom;obligatoire;min;max;choix_id;choix_nom;supplement_prix;disponible
- plat_reference : doit exister dans plats.csv.
- groupe_id / choix_id : minuscules sans accent, chiffres et tirets. Jamais d'arabe ici.
- Le groupe se répète à l'identique sur toutes ses lignes (groupe_nom, obligatoire, min, max).
- obligatoire=oui => min≥1. Taille unique obligatoire : oui;1;1.
  Sauces facultatives jusqu'à 2 : non;0;2.
- supplement_prix : 0.00 si le choix ne change pas le prix. Sinon le supplément seul,
  pas le prix total.
- Maximum 12 groupes par plat, 20 choix par groupe.

RÈGLES ABSOLUES
1. N'invente AUCUN plat, AUCUN prix, AUCUNE option. Tout doit être lisible sur les photos.
2. Prix illisible ou ambigu => écris exactement A_VERIFIER dans la colonne prix.
   Ne devine jamais.
3. Si le menu donne un prix par taille, mets le prix de la plus petite taille dans
   plats.csv et les écarts en supplement_prix dans options.csv.
4. Si une information est douteuse, ajoute en fin de réponse une liste
   « À VÉRIFIER AVEC LE CLIENT » — pas dans les CSV.
5. Aucune ligne vide, aucun commentaire dans les CSV, pas de bloc Markdown autour.
TXT;
    }

    // ---------------------------------------------------------------------
    // 6. Les trois handlers — chacun avec son nonce ET sa capacité
    // ---------------------------------------------------------------------

    public static function telecharger_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès réservé à l’équipe TUC.', 'tuc-core'), '', ['response' => 403]);
        }
        check_admin_referer('tuc_import_csv_telecharger');
        $quoi = sanitize_key((string) ($_GET['modele'] ?? ''));
        $fichiers = [
            'plats'    => ['plats.csv', 'text/csv', self::csv_plats()],
            'options'  => ['options.csv', 'text/csv', self::csv_options()],
            'consigne' => ['consigne-ia.txt', 'text/plain', self::consigne_ia()],
        ];
        if (!isset($fichiers[$quoi])) {
            wp_die(esc_html__('Modèle inconnu.', 'tuc-core'), '', ['response' => 400]);
        }
        [$nom, $type, $contenu] = $fichiers[$quoi];
        nocache_headers();
        header('Content-Type: ' . $type . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nom . '"');
        header('Content-Length: ' . strlen($contenu));
        echo $contenu; // phpcs:ignore WordPress.Security.EscapeOutput -- flux CSV/TXT, en-têtes forcés
        exit;
    }

    public static function verifier_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès réservé à l’équipe TUC.', 'tuc-core'), '', ['response' => 403]);
        }
        check_admin_referer('tuc_import_csv_verifier');
        $resto_id = absint($_POST['restaurant_id'] ?? 0);
        $lecture = self::lire_televersements($_FILES);
        if (is_wp_error($lecture)) {
            self::retour('tuc-import-csv', ['erreur' => $lecture->get_error_message()]);
        }
        $analyse = self::analyser($lecture['plats'], $lecture['options'], $resto_id);
        // L'état du dry-run est conservé pour l'import réel : on ne redemande pas
        // les fichiers, et surtout on n'importe pas un fichier que personne n'a vu.
        set_transient(
            self::TRANSIENT . get_current_user_id(),
            ['resto' => $resto_id, 'plats' => $lecture['plats'], 'options' => $lecture['options'], 'analyse' => $analyse],
            30 * MINUTE_IN_SECONDS
        );
        self::retour('tuc-import-csv', ['verifie' => '1']);
    }

    public static function executer_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès réservé à l’équipe TUC.', 'tuc-core'), '', ['response' => 403]);
        }
        check_admin_referer('tuc_import_csv_executer');

        $etat = get_transient(self::TRANSIENT . get_current_user_id());
        if (!is_array($etat) || empty($etat['analyse'])) {
            self::retour('tuc-import-csv', ['erreur' => 'Aucune vérification en cours. Téléversez et vérifiez avant d’importer.']);
        }
        // On rejoue l'analyse : le dry-run affiché et l'écriture doivent porter
        // sur le MÊME verdict, calculé par le même code.
        $analyse = self::analyser($etat['plats'], $etat['options'], (int) $etat['resto']);
        if ($analyse['bloquant']) {
            self::retour('tuc-import-csv', ['erreur' => 'Import refusé : des lignes sont encore en erreur.']);
        }
        $confirmation = sanitize_key((string) ($_POST['confirmer_avertissements'] ?? ''));
        if ($analyse['avertissements'] > 0 && $confirmation !== 'oui') {
            self::retour('tuc-import-csv', ['erreur' => 'Des avertissements sont en attente : confirmez-les avant d’importer.']);
        }

        $plats = [];
        foreach ($etat['plats']['lignes'] as $ligne) {
            $plats[(string) $ligne['reference']] = $ligne;
        }
        $options = $etat['options']['lignes'] ?? [];

        $resultat = self::importer($plats, $options, (int) $etat['resto']);
        delete_transient(self::TRANSIENT . get_current_user_id());
        if (is_wp_error($resultat)) {
            self::retour('tuc-import-csv', ['erreur' => $resultat->get_error_message()]);
        }
        self::retour('tuc-import-csv', [
            'importe' => sprintf('%d créé(s), %d mis à jour — tous en brouillon.', $resultat['crees'], $resultat['maj']),
        ]);
    }

    /**
     * Lit les deux fichiers téléversés, avec type, taille et erreur d'upload.
     *
     * @param array<string,mixed> $fichiers
     * @return array{plats: array{lignes: array<int,array<string,string>>}, options: array{lignes: array<int,array<string,string>>}|null}|WP_Error
     */
    public static function lire_televersements(array $fichiers): array|WP_Error
    {
        $plats = self::lire_un_fichier($fichiers['csv_plats'] ?? null, 'plats.csv', true);
        if (is_wp_error($plats)) {
            return $plats;
        }
        $options = self::lire_un_fichier($fichiers['csv_options'] ?? null, 'options.csv', false);
        if (is_wp_error($options)) {
            return $options;
        }
        return ['plats' => $plats, 'options' => $options];
    }

    private static function lire_un_fichier($fichier, string $nom, bool $obligatoire): array|WP_Error|null
    {
        $absent = !is_array($fichier)
            || !isset($fichier['error'], $fichier['size'], $fichier['tmp_name'])
            || (int) $fichier['error'] === UPLOAD_ERR_NO_FILE;
        if ($absent) {
            return $obligatoire ? new WP_Error('tuc_csv_absent', $nom . ' est obligatoire.') : null;
        }
        if ((int) $fichier['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('tuc_csv_upload', sprintf('%s : téléversement incomplet (code %d).', $nom, (int) $fichier['error']));
        }
        if ((int) $fichier['size'] <= 0 || (int) $fichier['size'] > self::TAILLE_MAX) {
            return new WP_Error('tuc_csv_taille', sprintf('%s : fichier vide ou supérieur à 2 Mio.', $nom));
        }
        $tmp = (string) $fichier['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            return new WP_Error('tuc_csv_upload', $nom . ' : fichier temporaire invalide.');
        }
        // Le type MIME est vérifié sur le CONTENU, pas sur l'extension : un CSV
        // texte reste du texte, même mal nommé.
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'], true)) {
            return new WP_Error('tuc_csv_type', sprintf('%s : ce n’est pas un fichier CSV (type détecté : %s).', $nom, $mime));
        }
        $contenu = (string) file_get_contents($tmp);
        $lu = self::lire_csv($contenu);
        if (is_wp_error($lu)) {
            return $lu;
        }
        return ['lignes' => $lu['lignes']];
    }

    /** @param array<string,string> $arguments */
    private static function retour(string $page, array $arguments): void
    {
        $url = add_query_arg(array_merge(['page' => $page], $arguments), admin_url('edit.php?post_type=' . TUC_CPT::RESTO));
        wp_safe_redirect($url);
        exit;
    }

    // ---------------------------------------------------------------------
    // 7. L'écran
    // ---------------------------------------------------------------------

    public static function page_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès réservé à l’équipe TUC.', 'tuc-core'), '', ['response' => 403]);
        }
        nocache_headers();

        $restaurants = self::restaurants_cibles();
        $categories = self::categories_disponibles();
        $images = self::images_valides();
        $etat = get_transient(self::TRANSIENT . get_current_user_id());
        $analyse = is_array($etat) ? $etat['analyse'] : null;
        ?>
        <div class="wrap">
          <h1>Importer un menu par CSV</h1>
          <?php if (isset($_GET['erreur'])) : ?>
            <div class="notice notice-error"><p><?php echo esc_html((string) wp_unslash($_GET['erreur'])); ?></p></div>
          <?php endif; ?>
          <?php if (isset($_GET['importe'])) : ?>
            <div class="notice notice-success"><p><?php echo esc_html((string) wp_unslash($_GET['importe'])); ?></p></div>
          <?php endif; ?>

          <h2>1. Télécharger les modèles</h2>
          <p>
            Les catégories proposées viennent
            <?php echo $categories['depuis_le_site'] ? 'de votre site' : 'd’une liste usuelle (votre site n’en a encore aucune)'; ?>
            : <?php echo esc_html(implode(', ', $categories['termes'])); ?>.
          </p>
          <p>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(
                add_query_arg(['action' => 'tuc_import_csv_telecharger', 'modele' => 'plats'], admin_url('admin-post.php')),
                'tuc_import_csv_telecharger'
            )); ?>">plats.csv</a>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(
                add_query_arg(['action' => 'tuc_import_csv_telecharger', 'modele' => 'options'], admin_url('admin-post.php')),
                'tuc_import_csv_telecharger'
            )); ?>">options.csv</a>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(
                add_query_arg(['action' => 'tuc_import_csv_telecharger', 'modele' => 'consigne'], admin_url('admin-post.php')),
                'tuc_import_csv_telecharger'
            )); ?>">consigne-ia.txt</a>
          </p>

          <h2>2. Téléverser</h2>
          <form method="post" enctype="multipart/form-data"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tuc_import_csv_verifier">
            <?php wp_nonce_field('tuc_import_csv_verifier'); ?>
            <table class="form-table">
              <tr><th><label for="restaurant_id">Restaurant cible</label></th>
                <td>
                  <select name="restaurant_id" id="restaurant_id" required>
                    <option value="">— choisir —</option>
                    <?php foreach ($restaurants as $resto) : ?>
                      <option value="<?php echo (int) $resto->ID; ?>"
                        <?php selected(is_array($etat) ? (int) $etat['resto'] : 0, $resto->ID); ?>>
                        <?php echo esc_html($resto->post_title); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <p class="description">L’import ne devine jamais le restaurant.</p>
                </td>
              </tr>
              <tr><th><label for="csv_plats">plats.csv</label></th>
                <td><input type="file" name="csv_plats" id="csv_plats" accept=".csv,text/csv" required></td></tr>
              <tr><th><label for="csv_options">options.csv (facultatif)</label></th>
                <td><input type="file" name="csv_options" id="csv_options" accept=".csv,text/csv"></td></tr>
            </table>
            <?php submit_button('Vérifier (aucune écriture)'); ?>
          </form>

          <?php if (is_array($analyse)) : ?>
            <h2>3. Vérification</h2>
            <p>
              <strong><?php echo (int) $analyse['resume']['plats']; ?></strong> plat(s),
              <strong><?php echo (int) $analyse['resume']['groupes']; ?></strong> groupe(s),
              <strong><?php echo (int) $analyse['resume']['choix']; ?></strong> choix.
              <?php if ($analyse['resume']['prix_min'] !== null) : ?>
                Prix de <strong><?php echo esc_html(number_format((float) $analyse['resume']['prix_min'], 2, ',', ' ')); ?></strong>
                à <strong><?php echo esc_html(number_format((float) $analyse['resume']['prix_max'], 2, ',', ' ')); ?></strong> MAD.
              <?php endif; ?>
            </p>
            <p>
              <?php echo (int) $analyse['erreurs']; ?> erreur(s),
              <?php echo (int) $analyse['avertissements']; ?> avertissement(s).
            </p>
            <table class="widefat striped">
              <thead><tr><th>Ligne</th><th>Référence</th><th>Action</th><th>Prix lu</th><th>Erreurs</th><th>Avertissements</th></tr></thead>
              <tbody>
              <?php foreach ($analyse['lignes'] as $ligne) : ?>
                <tr>
                  <td><?php echo (int) $ligne['numero']; ?></td>
                  <td><?php echo esc_html((string) $ligne['reference']); ?></td>
                  <td><?php echo esc_html((string) $ligne['action']); ?></td>
                  <td><?php echo is_float($ligne['prix']) ? esc_html(number_format($ligne['prix'], 2, ',', ' ')) : esc_html('—'); ?></td>
                  <td><?php echo esc_html(implode(' ; ', $ligne['erreurs'])); ?></td>
                  <td><?php echo esc_html(implode(' ; ', $ligne['avertissements'])); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php foreach ($analyse['options'] as $ligne) : ?>
                <tr>
                  <td><?php echo (int) $ligne['numero']; ?> (options)</td>
                  <td><?php echo esc_html((string) $ligne['reference']); ?></td>
                  <td colspan="2">—</td>
                  <td><?php echo esc_html(implode(' ; ', $ligne['erreurs'])); ?></td>
                  <td><?php echo esc_html(implode(' ; ', $ligne['avertissements'])); ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>

            <h2>4. Importer</h2>
            <?php if ($analyse['bloquant']) : ?>
              <p><strong>Import impossible :</strong> corrigez les lignes en erreur.
                 Rien n’a été écrit, et rien ne sera écrit tant qu’une seule ligne est en erreur.</p>
            <?php else : ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tuc_import_csv_executer">
                <?php wp_nonce_field('tuc_import_csv_executer'); ?>
                <?php if ($analyse['avertissements'] > 0) : ?>
                  <p>
                    <label>
                      <input type="checkbox" name="confirmer_avertissements" value="oui">
                      J’ai lu les <?php echo (int) $analyse['avertissements']; ?> avertissement(s) ci-dessus et je confirme l’import.
                    </label>
                  </p>
                <?php endif; ?>
                <p>Les plats seront importés <strong>en brouillon</strong>. La publication reste un geste explicite.</p>
                <?php submit_button('Importer'); ?>
              </form>
            <?php endif; ?>

            <h3>Images du site (pour information)</h3>
            <p class="description">
              <?php echo count($images); ?> visuel(s) disponible(s) dans le thème.
              La colonne « image » n’existe pas dans ce format : les visuels de restaurant
              se règlent dans l’admin de la fiche.
            </p>
          <?php endif; ?>
        </div>
        <?php
    }
}
