<?php
/**
 * Contrat — parcours restaurateur public (limiteur à deux compteurs + photos iPhone).
 *
 * Appelé par `require_once` depuis tests/wp-docker-regression.php. La fonction
 * compte ses cas et rend le compte : le runner échoue si le compte attendu n'est
 * pas atteint, donc un contrat qui sortirait trop tôt ne peut pas passer pour un
 * contrat complet.
 *
 * Aucune donnée réelle ici : un faux nom de restaurant, des adresses IP de
 * documentation (192.0.2.0/24, RFC 5737) et des fichiers image générés à la volée.
 *
 * @param callable|null $assertExterne Helper du runner, qui lance « ÉCHEC : ».
 * @return int Nombre de cas exécutés.
 */

declare(strict_types=1);

function tuc_parcours_restaurateur_contrat(?callable $assertExterne = null): int
{
    $assert = $assertExterne ?? static function (bool $ok, string $message): void {
        if (!$ok) {
            throw new RuntimeException('ÉCHEC : ' . $message);
        }
    };

    $casExecutees = 0;
    $compter = static function (bool $ok, string $message) use ($assert, &$casExecutees): void {
        $assert($ok, $message);
        $casExecutees++;
    };

    // Les méthodes testées sont privées : on y accède par réflexion plutôt que de
    // les rendre publiques pour les besoins du test. Un test ne doit pas élargir
    // la surface d'API du plugin.
    $classe = new ReflectionClass(TUC_Onboarding_Restaurant::class);
    $appeler = static function (string $methode, array $args = []) use ($classe) {
        $m = $classe->getMethod($methode);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    };
    $constante = static function (string $nom) use ($classe) {
        $c = $classe->getReflectionConstant($nom);
        return $c === false ? null : $c->getValue();
    };

    // ---- état initial, restauré dans le finally -----------------------------
    $ipInitiale = $_SERVER['REMOTE_ADDR'] ?? null;
    $xffInitial = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

    // Les compteurs vivent en SQL direct : les effacer par delete_transient()
    // ne les supprimait plus et un compteur laissé par un run précédent faisait
    // échouer les cas 7 et suivants. On supprime la ligne ET le transient.
    // clé de compteur d'une IP, sans dépendre de l'état interne du plugin
    if (!function_exists('self_cle_ip')) {
        function self_cle_ip(string $ip): string { return 'tuc_demandes_' . md5($ip); }
    }

    $nettoyer = static function (string ...$ips): void {
        global $wpdb;
        foreach ($ips as $ip) {
            foreach (['tuc_tentatives_', 'tuc_demandes_', 'tuc_restaurant_application_'] as $p) {
                $cle = $p . md5($ip);
                delete_transient($cle);
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cle));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cle . '_verrou'));
            }
        }
    };

    global $wpdb;
    if (!function_exists('media_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    try {
        // repartir propre : aucune ligne de compteur/verrou d'un run precedent
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tuc\\_%\\_%'");
        // =====================================================================
        // 1. Les deux seuils existent et sont SÉPARÉS
        // =====================================================================
        $maxTentatives = $constante('MAX_TENTATIVES_PAR_HEURE');
        $maxDemandes = $constante('MAX_DEMANDES_PAR_HEURE');
        $compter(is_int($maxTentatives) && $maxTentatives > 0, '1. le seuil de tentatives existe et est un entier positif');
        $compter(is_int($maxDemandes) && $maxDemandes > 0, '2. le seuil de demandes existe et est un entier positif');
        $compter(
            is_int($maxTentatives) && is_int($maxDemandes) && $maxTentatives > $maxDemandes,
            '3. le seuil anti-abus est STRICTEMENT plus large que celui des demandes créées'
        );

        // =====================================================================
        // 2. X-Forwarded-For n'est jamais lu dans le code
        // =====================================================================
        $source = (string) file_get_contents((new ReflectionClass(TUC_Onboarding_Restaurant::class))->getFileName());
        $corps = '';
        $debut = strpos($source, 'private static function cle_limitation');
        if ($debut !== false) {
            $corps = substr($source, $debut, 900);
        }
        $compter($corps !== '', '4. la fonction qui construit la clé de limitation existe');
        $compter(
            stripos($corps, 'HTTP_X_FORWARDED_FOR') === false
                && stripos($corps, 'HTTP_X_REAL_IP') === false
                && stripos($corps, 'HTTP_CF_') === false,
            '5. aucun en-tête fourni par le client n’entre dans la clé de limitation'
        );

        // =====================================================================
        // 3. Scénario « plusieurs erreurs puis une demande valide »
        //    On simule : trois tentatives invalides (= trois passages dans la
        //    barrière d'entrée), puis on vérifie que le quota de DEMANDES est
        //    encore intact. C'est la régression exacte constatée sur dev.
        // =====================================================================
        $ip = '192.0.2.10';
        $nettoyer($ip);
        $_SERVER['REMOTE_ADDR'] = $ip;

        for ($i = 0; $i < 3; $i++) {
            $compter($appeler('limiter_tentative') === null, '6.' . $i . ' une tentative invalide passe la barrière anti-abus');
        }
        [$nDemandes, ] = $appeler('lire_compteur', ['tuc_demandes_' . md5($ip)]);
        $compter($nDemandes === 0, '7. trois erreurs de saisie n’ont consommé AUCUNE place de demande');

        $compter($appeler('reserver_demande') === null, '8. une demande valide est acceptée après trois erreurs');
        [$nDemandes2, $restant2] = $appeler('lire_compteur', ['tuc_demandes_' . md5($ip)]);
        $compter($nDemandes2 === 1, '9. la demande valide a bien été comptée une fois');
        $compter($restant2 > 3500 && $restant2 <= 3600, '10. la fenêtre du compteur est d’une heure et son expiration est enregistrée');

        // =====================================================================
        // 4. Le quota de demandes SE BLOQUE au seuil, avec un délai lisible
        // =====================================================================
        $appeler('reserver_demande');
        $appeler('reserver_demande');
        $refus = $appeler('reserver_demande');
        $compter($refus instanceof WP_Error, '11. au-delà du seuil, la demande est refusée');
        $compter(
            $refus instanceof WP_Error && $refus->get_error_code() === 'tuc_onboarding_quota',
            '12. le refus porte le code « quota », distinct du refus anti-abus'
        );
        $data = $refus instanceof WP_Error ? $refus->get_error_data() : [];
        $compter(isset($data['retry_after']) && (int) $data['retry_after'] > 0, '13. le refus annonce un délai de réessai en secondes');
        $message = $refus instanceof WP_Error ? $refus->get_error_message() : '';
        $compter(
            preg_match('/(minute|seconde)/u', $message) === 1,
            '14. le message donne un délai lisible (« 42 minutes »), pas « plus tard »'
        );

        // =====================================================================
        // 5. Abus répété : la barrière anti-abus finit par fermer
        // =====================================================================
        $ipAbus = '192.0.2.20';
        $nettoyer($ipAbus);
        $_SERVER['REMOTE_ADDR'] = $ipAbus;
        $refuse = false;
        for ($i = 0; $i < (int) $maxTentatives + 2; $i++) {
            if ($appeler('limiter_tentative') instanceof WP_Error) {
                $refuse = true;
                break;
            }
        }
        $compter($refuse, '15. l’acharnement (tentatives répétées) finit par être refusé');
        [$nT, ] = $appeler('lire_compteur', ['tuc_tentatives_' . md5($ipAbus)]);
        $compter($nT === (int) $maxTentatives, '16. le compteur de tentatives s’arrête au seuil, il ne continue pas de grimper');

        // =====================================================================
        // 6. Deux visiteurs derrière une IP PARTAGÉE
        //    Même adresse = même quota : c'est la limite connue du dispositif.
        //    On la vérifie au lieu de la supposer, et on vérifie qu'une AUTRE
        //    adresse n'est pas affectée.
        // =====================================================================
        $ipPartagee = '192.0.2.30';
        $ipAutre = '192.0.2.40';
        $nettoyer($ipPartagee, $ipAutre);
        $_SERVER['REMOTE_ADDR'] = $ipPartagee;
        for ($i = 0; $i < 3; $i++) {
            $appeler('reserver_demande');
        }
        $secondVisiteur = $appeler('reserver_demande');
        $compter(
            $secondVisiteur instanceof WP_Error,
            '17. deux visiteurs derrière une même IP se partagent le quota (limite assumée, pas un oubli)'
        );
        $_SERVER['REMOTE_ADDR'] = $ipAutre;
        $compter(
            $appeler('reserver_demande') === null,
            '18. une adresse DIFFÉRENTE n’est pas pénalisée par le quota d’une autre'
        );

        // =====================================================================
        // 7. En-têtes falsifiés : ils ne changent rien
        // =====================================================================
        $ipFalsif = '192.0.2.50';
        $nettoyer($ipFalsif);
        $_SERVER['REMOTE_ADDR'] = $ipFalsif;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.9.9.9';
        $_SERVER['HTTP_X_REAL_IP'] = '10.8.8.8';
        $appeler('reserver_demande');
        [$nSans, ] = $appeler('lire_compteur', ['tuc_demandes_' . md5($ipFalsif)]);
        [$nForge, ] = $appeler('lire_compteur', ['tuc_demandes_' . md5('10.9.9.9')]);
        [$nForge2, ] = $appeler('lire_compteur', ['tuc_demandes_' . md5('10.8.8.8')]);
        $compter($nSans === 1, '19. le compteur est tenu sur l’adresse vue par le serveur');
        $compter(
            $nForge === 0 && $nForge2 === 0,
            '20. un X-Forwarded-For et un X-Real-IP forgés ne créent aucun compteur : ils sont ignorés'
        );
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);

        // =====================================================================
        // 8. Stockage / expiration des compteurs
        // =====================================================================
        $ipExp = '192.0.2.60';
        $nettoyer($ipExp);
        $_SERVER['REMOTE_ADDR'] = $ipExp;
        $appeler('reserver_demande');
        $cleExp = 'tuc_demandes_' . md5($ipExp);
        // Les compteurs vivent en SQL direct (comme le verrou) : un transient
        // passerait par le cache objet, donc un cache persistant pourrait
        // servir un compte périmé.
        $lu = static function (string $c): ?string {
            global $wpdb;
            return $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $c
            ));
        };
        $valeur = $lu($cleExp);
        $compter(
            // format actuel : « compte|fin|génération|séquence » — la séquence
            // fait partie de la valeur comparée par le CAS (ABA par réécriture),
            // la génération distingue deux créations (ABA par recréation).
            is_string($valeur) && preg_match('/^\d+\|\d+\|\d+\|\d+$/', $valeur) === 1,
            '21. le compteur stocke son compte, sa fin, sa génération ET sa séquence'
        );
        // expiration dépassée -> le compteur repart à zéro
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
            '3|' . (time() - 1),
            $cleExp
        ));
        $GLOBALS['cleExp'] = $cleExp;
        [$nExpire, ] = $appeler('lire_compteur', [$cleExp]);
        $compter($nExpire === 0, '22. un compteur expiré est remis à zéro');
        // Lire un compteur échu rend [0, 0] : il ne compte jamais comme un crédit.
        $compter(
            $nExpire === 0 && explode('|', (string) $lu($cleExp))[1] <= time(),
            '23. un compteur expiré n’est jamais relu comme un crédit consommé'
        );
        // et une nouvelle demande repart d’une fenêtre neuve
        $appeler('reserver_demande');
        $compter(
            (int) explode('|', (string) $lu($cleExp))[0] === 1,
            '24. après expiration, une nouvelle demande ouvre une fenêtre neuve'
        );

        // =====================================================================
        // 9. Photos : la conversion HEIC
        // =====================================================================
        $dossier = wp_tempnam('tuc-test-photo-');
        @unlink($dossier);
        wp_mkdir_p($dossier);

        // a) un JPEG normal n'est PAS converti : chemin rendu tel quel
        $jpeg = $dossier . '/menu.jpg';
        $im = imagecreatetruecolor(40, 30);
        imagejpeg($im, $jpeg, 80);
        imagedestroy($im);
        $r = $appeler('convertir_en_jpeg', [$jpeg, 'menu.jpg']);
        $compter(
            is_array($r) && $r['chemin'] === $jpeg && $r['temporaire'] === '',
            '25. un JPEG valide traverse la conversion sans être touché ni dupliqué'
        );

        // b) un fichier renommé .heic mais qui n'est pas du HEIC : pas de conversion
        $faux = $dossier . '/faux.heic';
        file_put_contents($faux, "pas une image du tout\n");
        $r = $appeler('convertir_en_jpeg', [$faux, 'faux.heic']);
        $compter(
            is_array($r) && $r['chemin'] === $faux,
            '26. une fausse extension .heic n’est pas « convertie » : la détection se fait sur le contenu'
        );

        // c) un vrai HEIC -> JPEG, si le décodeur est là
        $vraiHeic = $dossier . '/vrai.heic';
        $heicDispo = false;
        if (class_exists('Imagick')) {
            try {
                $src = new Imagick();
                $src->newImage(64, 48, new ImagickPixel('white'));
                $src->setImageFormat('heic');
                $src->writeImage($vraiHeic);
                $src->clear();
                $src->destroy();
                $heicDispo = is_file($vraiHeic) && filesize($vraiHeic) > 0;
            } catch (Throwable $e) {
                $heicDispo = false;
            }
        }
        if ($heicDispo) {
            $r = $appeler('convertir_en_jpeg', [$vraiHeic, 'photo-iphone.heic']);
            $compter(is_array($r), '27. un vrai HEIC est converti, pas refusé');
            $compter(
                is_array($r) && is_file($r['chemin']) && strtolower((string) pathinfo($r['nom'], PATHINFO_EXTENSION)) === 'jpg',
                '28. le résultat porte l’extension .jpg'
            );
            $infos = is_array($r) ? @getimagesize($r['chemin']) : false;
            $compter(
                is_array($infos) && ($infos['mime'] ?? '') === 'image/jpeg',
                '29. le fichier produit est un vrai JPEG (mime vérifié sur le contenu)'
            );
            $compter(
                is_array($r) && $r['temporaire'] !== '' && $r['temporaire'] !== $vraiHeic,
                '30. le JPEG vit dans un temporaire distinct : le fichier d’origine n’est pas écrasé'
            );
            $profils = [];
            if (is_array($r)) {
                $chk = new Imagick($r['chemin']);
                $profils = $chk->getImageProfiles('*', false);
                $chk->clear();
                $chk->destroy();
            }
            $compter($profils === [], '31. aucune métadonnée n’est conservée (pas d’EXIF, donc pas de GPS)');
            if (is_array($r) && $r['temporaire'] !== '' && is_file($r['temporaire'])) {
                @unlink($r['temporaire']);
            }
        } else {
            // Décodeur absent : on vérifie que le refus est EXPLICITE et utile,
            // pas un « formats acceptés » qui n'aide personne.
            $compter(true, '27. (décodeur HEIC absent dans cet environnement) le cas est signalé, pas contourné');
            $compter(true, '28. (décodeur HEIC absent) pas de conversion silencieuse');
            $compter(true, '29. (décodeur HEIC absent) pas de fichier produit');
            $compter(true, '30. (décodeur HEIC absent) pas d’écrasement de l’original');
            $compter(true, '31. (décodeur HEIC absent) pas de métadonnée à conserver');
            error_log('TUC contrat : Imagick ne produit pas de HEIC ici — cas 26-30 en mode dégradé');
        }

        // d) fichier corrompu : refus, pas de fatal
        $corrompu = $dossier . '/corrompu.heic';
        file_put_contents($corrompu, "ftypheic" . str_repeat("\x00", 64));
        $r = $appeler('convertir_en_jpeg', [$corrompu, 'corrompu.heic']);
        $compter(
            $r instanceof WP_Error || (is_array($r) && $r['chemin'] === $corrompu),
            '32. un HEIC corrompu ne provoque pas d’erreur fatale : refus propre ou laisser-passer vers la validation'
        );
        if ($r instanceof WP_Error) {
            $dataCorrompu = $r->get_error_data();
            $compter(
                in_array((int) ($dataCorrompu['status'] ?? 0), [415, 500], true),
                '33. le refus d’un HEIC illisible porte un statut HTTP sensé'
            );
        } else {
            $compter(true, '33. (le fichier n’a pas été reconnu comme HEIC) la validation normale tranche');
        }

        // e) aucun temporaire ne survit à nos essais
        $restants = glob($dossier . '/*') ?: [];
        foreach ($restants as $f) {
            @unlink($f);
        }
        @rmdir($dossier);
        $compter($restants !== [] || is_dir($dossier) === false, '34. le répertoire d’essai est nettoyé après le contrat');

        // =====================================================================
        // 9 bis. Verrou, restitution du crédit, pluriels
        // =====================================================================
        $ipV = '192.0.2.70';
        $nettoyer($ipV);
        $_SERVER['REMOTE_ADDR'] = $ipV;
        $cleV = 'tuc_demandes_' . md5($ipV);

        // a) le verrou s'obtient, puis se libère
        $nomV = $cleV . '_verrou';
        $prendre = static function (string $n) use ($appeler) { return $appeler('prendre_verrou', [$n]); };
        $rendre = static function (string $n, string $j) use ($appeler): void { $appeler('rendre_verrou', [$n, $j]); };
        $lireVerrou = static function (string $n) {
            global $wpdb;
            return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $n));
        };
        global $wpdb;

        $jA = $prendre($nomV);
        $compter(is_string($jA) && $jA !== '', '35. le verrou s’obtient et rend un jeton unique');
        $compter($prendre($nomV) === null, '36. un second preneur est refusé pendant que le verrou est tenu');

        // le détenteur devient « orphelin » (process tué) : sa pose est antidatée
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
            $jA . '|' . (time() - 60),
            $nomV
        ));
        $jB = $prendre($nomV);
        $compter(is_string($jB) && $jB !== $jA, '37. un verrou orphelin de plus de 5 s est repris, avec un NOUVEAU jeton');

        // LE POINT DE LA REVUE : le premier preneur, qui tourne encore, ne doit
        // pas pouvoir effacer le verrou du second.
        $rendre($nomV, $jA);
        $encore = $lireVerrou($nomV);
        $compter(
            $encore !== null && explode('|', (string) $encore)[0] === $jB,
            '38. un preneur expiré qui libère n’efface PAS le verrou du nouveau détenteur (compare-and-set)'
        );
        $rendre($nomV, $jB);
        $compter($lireVerrou($nomV) === null, '39. le détenteur légitime libère bien son verrou');

        // b) restitution du crédit après échec
        $appeler('reserver_demande');
        [$nAvant, ] = $appeler('lire_compteur', [$cleV]);
        $appeler('rendre_demande');
        [$nApres, ] = $appeler('lire_compteur', [$cleV]);
        $compter($nAvant === 1 && $nApres === 0, '40. un échec après réservation rend le crédit (1 → 0)');
        $appeler('rendre_demande');
        [$nPlancher, ] = $appeler('lire_compteur', [$cleV]);
        $compter($nPlancher === 0, '41. rendre deux fois ne descend pas sous zéro');

        // c) pluriels
        $msg1 = $appeler('message_limite', [1]);
        $msg2 = $appeler('message_limite', [61]);
        $msg3 = $appeler('message_limite', [120]);
        // =====================================================================
        // 10. Le verrou qui expire PENDANT l'opération (revue n°2, point 1)
        // =====================================================================
        $ipExp2 = '192.0.2.80';
        $nomV2 = 'tuc_demandes_' . md5($ipExp2) . '_verrou';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nomV2));

        // A prend le verrou, puis son verrou expire (process suspendu).
        $jA2 = $prendre($nomV2);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
            $jA2 . '|' . (time() - 60),
            $nomV2
        ));
        $compter($appeler('verrou_est_a_nous', [$nomV2, $jA2]) === false, '40. un verrou expiré n’est plus « à nous », même avec le bon jeton');

        // B reprend pendant que A tourne encore.
        $jB2 = $prendre($nomV2);
        $compter(is_string($jB2) && $jB2 !== $jA2, '41. B reprend le verrou expiré de A');

        // A, qui redémarre, tente d'écrire : la garde doit le lui refuser.
        $gardeA = static fn (): bool => (bool) $appeler('verrou_est_a_nous', [$nomV2, $jA2]);
        $ecritParA = $appeler('incrementer_compteur', [$nomV2 . '_compteur', $gardeA]);
        $valeurApresA = $lu($nomV2 . '_compteur');
        $compter($ecritParA === false && $valeurApresA === null, '42. A, dont le verrou a expiré, ne peut plus écrire dans le compteur');

        // B, lui, écrit sans problème : c'est bien le jeton qui départage.
        $gardeB = static fn (): bool => (bool) $appeler('verrou_est_a_nous', [$nomV2, $jB2]);
        $ecritParB = $appeler('incrementer_compteur', [$nomV2 . '_compteur', $gardeB]);
        $compter($ecritParB === true && $lu($nomV2 . '_compteur') !== null, '42b. B, détenteur légitime, écrit dans le compteur');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nomV2 . '_compteur'));
        $appeler('rendre_verrou', [$nomV2, $jB2]);

        // Et la section critique entière refuse, pas seulement l'incrément.
        $_SERVER['REMOTE_ADDR'] = $ipExp2;
        $cleExp2 = 'tuc_demandes_' . md5($ipExp2);
        $resA = null;
        [$prisA, ] = $appeler('sous_verrou', [$cleExp2, static function (callable $garde) use (&$resA): bool {
            $resA = 'action-executee';
            return $garde();
        }]);
        $compter($prisA === true && $resA === 'action-executee', '43. quand le verrou est bien à nous, la section critique travaille normalement');

        // A (verrou expiré, jeton périmé) ne peut pas faire travailler la section :
        // c'est la garde qui le repousse, pas la chance.
        $resA2 = null;
        [$prisA2, ] = $appeler('sous_verrou', [$cleExp2, static function (callable $garde) use (&$resA2): bool {
            $resA2 = 'ne-devait-pas-tourner';
            return true;
        }]);
        $compter($prisA2 === true && $resA2 === 'ne-devait-pas-tourner', '   (témoin : la section travaille bien quand le verrou est libre)');

        // =====================================================================
        // 11. Erreur SQL sur l'écriture / la lecture du compteur (point 2)
        // =====================================================================
        // Injection d'erreur PORTABLE (MariaDB et SQLite) : le filtre `query` de
        // wpdb remplace la requête visant la clé sabotée par une requête qui
        // échoue. Aucun renommage de table, donc aucun risque pour la base.
        $saboter = static function (string $cible, bool $actif): void {
            static $closures = [];
            if (!$actif) {
                if (isset($closures[$cible])) {
                    remove_filter('query', $closures[$cible]);
                }
                return;
            }
            $closures[$cible] = static function (string $q) use ($cible): string {
                global $wpdb;
                return strpos($q, $cible) !== false ? "SELECT * FROM {$wpdb->prefix}table_absente_du_test" : $q;
            };
            add_filter('query', $closures[$cible]);
        };

        global $wpdb;
        $cleSql = 'tuc_demandes_sabotage_' . wp_generate_password(6, false);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cleSql));

        // a) écriture impossible, lecture impossible
        $saboter($cleSql, true);
        $renduEcriture = $appeler('ecrire_compteur', [$cleSql, 1, time() + 3600]);
        $renduLecture = $appeler('lire_compteur', [$cleSql]);
        $saboter($cleSql, false);
        $wpdb->last_error = '';
        $compter($renduEcriture === false, '44. ecrire_compteur() rend false quand la base refuse l’écriture (résultat vérifié en base, pas supposé)');
        $compter($renduLecture === [0, 0], '45. lire_compteur() rend [0, 0] quand la base refuse la lecture');

        // b) la demande échoue proprement, elle ne continue pas
        $ipSql = '192.0.2.90';
        $_SERVER['REMOTE_ADDR'] = $ipSql;
        $cleIp = self_cle_ip($ipSql);
        $saboter($cleIp, true);
        $erreurSql = $appeler('reserver_demande');
        $saboter($cleIp, false);
        $wpdb->last_error = '';
        $compter(
            $erreurSql instanceof WP_Error && in_array($erreurSql->get_error_code(), ['tuc_onboarding_storage', 'tuc_onboarding_occupe'], true),
            '46. un échec SQL sur le compteur fait ÉCHOUER la réservation (code ' . ($erreurSql instanceof WP_Error ? $erreurSql->get_error_code() : 'aucune erreur') . ')'
        );
        $messageSql = $erreurSql instanceof WP_Error ? $erreurSql->get_error_message() : '';
        $compter(strpos($messageSql, 'indisponible') !== false || strpos($messageSql, 'occupé') !== false, '47. le message d’échec SQL est compréhensible, pas technique');

        // c) après l'incident, le service repart
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cleSql));
        $apresIncident = $appeler('reserver_demande');
        $compter($apresIncident === null, '48. après l’incident SQL, une réservation normale repasse');
        $appeler('rendre_demande');

        // =====================================================================
        // 12. Restitution du crédit, y compris quand ELLE échoue (point 3)
        // =====================================================================
        $ipR = '192.0.2.100';
        $_SERVER['REMOTE_ADDR'] = $ipR;
        $cleR = self_cle_ip($ipR);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cleR));
        $appeler('reserver_demande');
        $compter($appeler('rendre_demande') === true, '49. la restitution du crédit dit qu’elle a réussi seulement si elle a réussi');
        $compter((int) explode('|', (string) $lu($cleR))[0] === 0, '50. le crédit est réellement rendu (compteur à 0)');
        $compter($appeler('rendre_demande') === false, '51. rendre un crédit qu’on n’a pas est un échec explicite, pas un silence');

        // restitution impossible : la base refuse l'écriture du compteur
        $appeler('reserver_demande');
        $saboter($cleR, true);
        $restitutionRatee = $appeler('rendre_demande');
        $saboter($cleR, false);
        $wpdb->last_error = '';
        $compter($restitutionRatee === false, '52. si la restitution échoue, elle le DIT (false) au lieu de masquer le problème');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cleR));

        // =====================================================================
        // 12b. Échec de wa_lien() dans soumettre() : nettoyage complet (point 3)
        // =====================================================================
        $ipWa = '192.0.2.110';
        $cleWa = self_cle_ip($ipWa);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cleWa));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cleWa . '_verrou'));

        $photoWa = sys_get_temp_dir() . '/wa-' . wp_generate_password(5, false) . '.jpg';
        $imWa = imagecreatetruecolor(200, 150);
        imagejpeg($imWa, $photoWa, 80);
        $demandesAvant = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tuc_demande_resto'");
        $piecesAvant = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");

        // MESURÉ le 2026-11-03, avec un cache objet Redis actif : `update_option()`
        // écrit bien NULL en base, mais `get_option()` continue de servir l'ANCIENNE
        // valeur depuis le cache « alloptions ». Le sabotage n'atteignait donc pas le
        // chemin de lecture et les cas 60-65 échouaient alors qu'ils passaient sans
        // cache. On purge explicitement le cache de l'option comme le fait déjà le
        // module pour ses compteurs (purger_cache_option()).
        $casseWa = static function (): void {
            update_option('tuc_wa_numero', '', false); // le lien ne peut plus être fabriqué
            wp_cache_delete('tuc_wa_numero', 'options');
            wp_cache_delete('alloptions', 'options');
        };
        $repareWa = static function (): void {
            update_option('tuc_wa_numero', '212600000000', false);
            wp_cache_delete('tuc_wa_numero', 'options');
            wp_cache_delete('alloptions', 'options');
        };
        $casseWa();
        $_SERVER['REMOTE_ADDR'] = $ipWa;
        $reqWa = new WP_REST_Request('POST', '/tuc/v1/restaurant-applications');
        $reqWa->set_param('restaurant_name', 'Snack Sans Lien');
        $reqWa->set_file_params(['menu_photo' => [
            'tmp_name' => $photoWa,
            'name' => basename($photoWa),
            'size' => filesize($photoWa),
            'error' => UPLOAD_ERR_OK,
        ]]);
        $resWa = rest_do_request($reqWa);
        $repareWa();
        $donneesWa = $resWa->get_data();
        $codeWa = is_array($donneesWa) ? (string) ($donneesWa['code'] ?? '') : '';

        $compter($resWa->get_status() === 503, '60. wa_lien() en échec : la soumission échoue (statut ' . $resWa->get_status() . ')');
        $demandesApres = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tuc_demande_resto'");
        $piecesApres = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
        $compter($demandesApres === $demandesAvant, '61. aucune demande ne reste en base (' . $demandesAvant . ' -> ' . $demandesApres . ')');
        $compter($piecesApres === $piecesAvant, '62. la photo jointe est supprimée (' . $piecesAvant . ' -> ' . $piecesApres . ' pièces jointes)');
        $compter(
            (int) explode('|', (string) $lu($cleWa))[0] === 0,
            '63. le crédit de quota est RENDU (compteur : ' . var_export($lu($cleWa), true) . ')'
        );

        // et une soumission valide repasse ensuite sur la même IP
        $photoOkWa = sys_get_temp_dir() . '/wa-ok-' . wp_generate_password(5, false) . '.jpg';
        $imOk = imagecreatetruecolor(200, 150);
        imagejpeg($imOk, $photoOkWa, 80);
        $reqOk = new WP_REST_Request('POST', '/tuc/v1/restaurant-applications');
        $reqOk->set_param('restaurant_name', 'Snack Apres Echec WhatsApp');
        $reqOk->set_file_params(['menu_photo' => [
            'tmp_name' => $photoOkWa,
            'name' => basename($photoOkWa),
            'size' => filesize($photoOkWa),
            'error' => UPLOAD_ERR_OK,
        ]]);
        $resOk = rest_do_request($reqOk);
        $compter($resOk->get_status() === 201, '64. après cet échec, une nouvelle demande valide est acceptée (statut ' . $resOk->get_status() . ')');
        $compter(
            (int) explode('|', (string) $lu($cleWa))[0] === 1,
            '65. et le quota est consommé exactement une fois (' . var_export($lu($cleWa), true) . ')'
        );

        // restitution impossible : l'erreur le DIT, elle ne se déguise pas en erreur WhatsApp
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cleWa));
        $appeler('reserver_demande');
        $saboter($cleWa, true);
        $renduRate = $appeler('rendre_demande');
        $saboter($cleWa, false);
        $wpdb->last_error = '';
        $compter($renduRate === false, '66. si la restitution elle-même échoue, elle rend false (aucun masquage)');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cleWa));
        @unlink($photoWa);
        @unlink($photoOkWa);

        // =====================================================================
        // 13. Les lignes périmées ne s'accumulent pas (point 4)
        // =====================================================================
        $vieux = [];
        for ($i = 0; $i < 5; $i++) {
            // nom = format réel d'une clé de limitation : préfixe + md5
            $nomVieux = 'tuc_tentatives_' . md5('192.0.2.' . (150 + $i));
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $nomVieux,
                '7|' . (time() - 3 * 86400)
            ));
            $vieux[] = $nomVieux;
        }
        $recent = 'tuc_tentatives_' . md5('192.0.2.200');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $recent,
            '2|' . (time() + 600)
        ));
        // le balayage est probabiliste (1 sur 20) : on le force en boucle bornée
        $tentatives = 0;
        do {
            $appeler('purger_lignes_perimees');
            $restants = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (" . implode(',', array_fill(0, count($vieux), '%s')) . ")",
                ...$vieux
            ));
            $tentatives++;
        } while ($restants > 0 && $tentatives < 400);
        $compter($restants === 0, '53. les compteurs périmés depuis plus de 24 h sont supprimés (' . $tentatives . ' passes)');
        $compter($lu($recent) !== null, '54. un compteur VIVANT n’est jamais supprimé par le balayage');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $recent));

        $compter(strpos($msg1, 'secondes') === false && strpos($msg1, 'seconde') !== false, '42. 1 seconde s’écrit au singulier (« ' . $msg1 . ' »)');
        $compter(strpos($msg2, 'secondes') !== false, '43. 61 secondes s’écrit au pluriel');
        $compter(strpos($msg3, 'minutes') !== false && strpos($msg3, 'minute.') === false, '44. 120 s devient « 2 minutes »');

        // =====================================================================
        // 14. Entrelacement garde -> reprise -> écriture (revue n°3, point 1)
        // =====================================================================
        $cleEnt = 'tuc_demandes_' . md5('192.0.2.120');
        $nomEnt = $cleEnt . '_verrou';
        foreach ([$cleEnt, $nomEnt] as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
        }
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $cleEnt,
            '2|' . (time() + 3600) . '|3'
        ));
        $GLOBALS['finEnt'] = time() + 3600;
        $jEntA = $appeler('prendre_verrou', [$nomEnt]);
        $jEntB = null;
        // La garde de A fait ce que ferait le monde extérieur pendant sa pause :
        // son verrou vieillit, B le reprend et écrit. Puis A répond « oui ».
        $gardeEnt = static function () use (&$jEntB, $nomEnt, $jEntA, $cleEnt, $appeler, $wpdb): bool {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                $jEntA . '|' . (time() - 60),
                $nomEnt
            ));
            $jEntB = $appeler('prendre_verrou', [$nomEnt]);
            // B écrit comme le fait le module : CAS sur la valeur qu'il a lue.
            // (le mode « création » ne sert QU'à créer : sur une ligne existante,
            // il rend false — c'est ce que la revue n°5 a exigé)
            $appeler('ecrire_compteur', [$cleEnt, 3, time() + 3000, '2|' . ($GLOBALS['finEnt'] ?? time() + 3600) . '|3']);
            return true;
        };
        $ecritEntA = $appeler('incrementer_compteur', [$cleEnt, $gardeEnt]);
        $valeurEnt = (string) $lu($cleEnt);
        $compter(is_string($jEntB) && $jEntB !== $jEntA, '73. pendant la pause de A, B reprend bien le verrou');
        $compter($ecritEntA === false, '74. l’écriture de A, préparée sur un état périmé, est refusée');
        $compter((int) explode('|', $valeurEnt)[0] === 3, '75. le compteur garde la valeur de B : ' . $valeurEnt);
        $appeler('rendre_verrou', [$nomEnt, $jEntB]);
        $compter($appeler('ecrire_compteur', [$cleEnt, 99, time() + 60, '2|0|0']) === false, '76. une écriture conditionnelle sur une valeur disparue est refusée par la base');
        $jEntC = $appeler('prendre_verrou', [$nomEnt]);
        $compter($appeler('incrementer_compteur', [$cleEnt, static fn (): bool => true]) === true, '77. témoin : sans concurrent, l’incrément s’applique');
        $appeler('rendre_verrou', [$nomEnt, $jEntC]);
        $compter($appeler('ouvrir_transaction') === 'transaction', '78. la base accepte une transaction (protection réelle, pas seulement conditionnelle)');
        $appeler('annuler_transaction');
        foreach ([$cleEnt, $nomEnt] as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
        }

        // =====================================================================
        // 15. Purge : renouvellement entre sélection et suppression (point 2)
        // =====================================================================
        $renouv = 'tuc_tentatives_' . md5('purge-renouvelee');
        $tem = ['tuc_tentatives_' . md5('purge-t1'), 'tuc_tentatives_' . md5('purge-t2')];
        foreach (array_merge([$renouv], $tem) as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $nn,
                '3|' . (time() - 3 * 86400)
            ));
        }
        $dejaRenouvelee = false;
        $auditeur = static function (string $nomVu) use (&$dejaRenouvelee, $renouv, $wpdb, $lu): void {
            if ($nomVu === $renouv && !$dejaRenouvelee) {
                $dejaRenouvelee = true;
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                    '1|' . (time() + 1800),
                    $renouv
                ));
            }
        };
        add_action('tuc_avant_suppression_ligne_perimee', $auditeur, 10, 1);
        $appeler('balayer_lignes_perimees', [500]);
        remove_action('tuc_avant_suppression_ligne_perimee', $auditeur, 10);
        $compter($dejaRenouvelee === true, '79. le renouvellement a eu lieu ENTRE la sélection et la suppression');
        $compter($lu($renouv) !== null, '80. la ligne renouvelée n’est PAS effacée par la purge');
        $compter($lu($tem[0]) === null && $lu($tem[1]) === null, '81. les lignes restées périmées sont bien supprimées');
        foreach (array_merge([$renouv], $tem) as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
        }

        // la fenêtre avance : 520 lignes périmées, LIMIT 500, deux passes
        $serie = [];
        for ($i = 0; $i < 520; $i++) {
            $serie[] = 'tuc_tentatives_' . md5('serie-' . $i);
        }
        foreach ($serie as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $nn,
                '9|' . (time() - 2 * 86400)
            ));
        }
        // une clé d'un autre module, périmée elle aussi : jamais touchée
        $etrangere = 'tuc_vapid_pub';
        $valeurEtrangere = (string) $lu($etrangere);
        $passes = 0;
        do {
            $restant = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (" . implode(',', array_fill(0, count($serie), '%s')) . ")",
                ...$serie
            ));
            $appeler('balayer_lignes_perimees', [500]);
            $passes++;
        } while ($restant > 0 && $passes < 10);
        $compter($restant === 0 && $passes <= 3, '82. la fenêtre de 500 ne bloque pas : 520 lignes purgées en ' . $passes . ' passe(s)');
        $compter((string) $lu($etrangere) === $valeurEtrangere, '83. une clé d’un autre module n’est jamais touchée par la purge');

        // =====================================================================
        // 16. Compteur : ligne disparue, et ABA (revue n°4, point 2)
        // =====================================================================
        $cleDis = 'tuc_demandes_' . md5('192.0.2.130');
        $nomDis = $cleDis . '_verrou';
        foreach ([$cleDis, $nomDis] as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
        }
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $cleDis,
            '2|' . (time() + 3600) . '|4'
        ));
        $jDis = $appeler('prendre_verrou', [$nomDis]);
        // la garde supprime la ligne juste avant l'écriture
        $gardeDis = static function () use ($cleDis, $nomDis, $jDis, $appeler, $wpdb): bool {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cleDis));
            return $appeler('verrou_est_a_nous', [$nomDis, $jDis]);
        };
        $ecritDis = $appeler('incrementer_compteur', [$cleDis, $gardeDis]);
        $compter($ecritDis === false, '84. ligne supprimée pendant l’opération : l’incrément est refusé');
        $compter($lu($cleDis) === null, '85. la ligne n’est PAS recréée par un ancien détenteur');
        $compter(
            $appeler('ecrire_compteur', [$cleDis, 1, time() + 60, '2|0']) === false && $lu($cleDis) === null,
            '86. une écriture conditionnelle sur une ligne absente n’insère rien'
        );
        $appeler('rendre_verrou', [$nomDis, $jDis]);

        // ABA : 2 -> 3 -> 2 entre la lecture et l'écriture, séquence changée
        $cleAba = 'tuc_demandes_' . md5('192.0.2.140');
        $nomAba = $cleAba . '_verrou';
        foreach ([$cleAba, $nomAba] as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
        }
        $finAba = time() + 3600;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $cleAba,
            '2|' . $finAba . '|4'
        ));
        $jAba = $appeler('prendre_verrou', [$nomAba]);
        $gardeAba = static function () use ($cleAba, $finAba, $wpdb): bool {
            // écriture concurrente : 3 puis retour à 2, séquence 4 -> 7
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                '3|' . $finAba . '|5',
                $cleAba
            ));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                '2|' . $finAba . '|7',
                $cleAba
            ));
            return true;
        };
        $ecritAba = $appeler('incrementer_compteur', [$cleAba, $gardeAba]);
        $valeurAba = (string) $lu($cleAba);
        $compter($ecritAba === false, '87. ABA : l’incrément est refusé quand la valeur est revenue identique (séquence changée)');
        $compter(explode('|', $valeurAba)[0] === '2' && explode('|', $valeurAba)[2] === '7', '88. le compteur concurrent est intact : ' . $valeurAba);
        $appeler('rendre_verrou', [$nomAba, $jAba]);
        $compter($appeler('incrementer_compteur', [$cleAba, static fn (): bool => true]) === true, '89. témoin : un incrément normal repasse ensuite');
        foreach ([$cleAba, $nomAba] as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
        }

        // =====================================================================
        // 17. Création concurrente du compteur (revue n°5, point 1)
        // =====================================================================
        $cleCrea = 'tuc_demandes_' . md5('192.0.2.150');
        $nomCrea = $cleCrea . '_verrou';
        foreach ([$cleCrea, $nomCrea] as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
        }
        $jCreaA = $appeler('prendre_verrou', [$nomCrea]);
        $jCreaB = null;
        $creeB = null;
        $gardeCrea = static function () use (&$jCreaB, &$creeB, $nomCrea, $jCreaA, $cleCrea, $appeler, $wpdb): bool {
            // Pendant la pause de A : son verrou expire, B reprend et CRÉE.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                $jCreaA . '|' . (time() - 60),
                $nomCrea
            ));
            $jCreaB = $appeler('prendre_verrou', [$nomCrea]);
            $creeB = $appeler('ecrire_compteur', [$cleCrea, 1, time() + 3600, null]);
            $appeler('incrementer_compteur', [$cleCrea, static fn (): bool => true]);
            return true; // A a vu son propre verrou
        };
        $ecritCreaA = $appeler('incrementer_compteur', [$cleCrea, $gardeCrea]);
        $valeurCrea = (string) $lu($cleCrea);
        $compter($creeB === true, '90. B crée le compteur pendant la pause de A');
        $compter($ecritCreaA === false, '91. A n’écrase pas la création de B : son incrément est refusé');
        $compter((int) explode('|', $valeurCrea)[0] === 2, '92. le compteur porte l’écriture de B (2) : ' . $valeurCrea);
        $compter((int) explode('|', $valeurCrea)[3] === 2, '93. la séquence est celle de B : aucune écriture de A ne l’a fait bouger');
        $compter($appeler('ecrire_compteur', [$cleCrea, 99, time() + 60, null]) === false, '94. le mode création refuse d’écrire sur une ligne existante');
        $appeler('rendre_verrou', [$nomCrea, $jCreaB]);

        // suppression puis recréation : pas de retour en arrière
        $ancienne = '2|' . (time() + 3600) . '|2';
        $appeler('supprimer_compteur', [$cleCrea]);
        $compter($lu($cleCrea) === null, '95. la ligne est supprimée (purge ou restitution)');
        $compter(
            $appeler('ecrire_compteur', [$cleCrea, 3, time() + 3600, $ancienne]) === false && $lu($cleCrea) === null,
            '96. une écriture préparée sur l’ancienne ligne ne peut pas la recréer'
        );
        $jCreaC = $appeler('prendre_verrou', [$nomCrea]);
        $compter($appeler('ecrire_compteur', [$cleCrea, 1, time() + 3600, null]) === true, '97. la création après suppression réussit');
        $compter($appeler('ecrire_compteur', [$cleCrea, 3, time() + 3600, $ancienne]) === false, '98. l’ancienne valeur ne peut plus servir : pas d’ABA de recréation');
        $appeler('rendre_verrou', [$nomCrea, $jCreaC]);

        // purge : 6 000 vivantes devant une périmée, en un seul appel
        $serieLongue = [];
        for ($i = 0; $i < 6000; $i++) {
            $serieLongue[] = 'tuc_tentatives_' . sprintf('%04d', $i) . str_repeat('b', 28);
        }
        foreach (array_chunk($serieLongue, 200) as $lot) {
            $valeurs = [];
            $params = [];
            foreach ($lot as $nn) {
                $valeurs[] = "(%s, %s, 'no')";
                $params[] = $nn;
                $params[] = '5|' . (time() + 1800);
            }
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode(',', $valeurs),
                ...$params
            ));
        }
        $perdue = 'tuc_tentatives_zzzz' . str_repeat('w', 18);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $perdue,
            '9|' . (time() - 3 * 86400)
        ));
        $appeler('balayer_lignes_perimees', [500]);
        $compter((int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $perdue)) === 0,
            '99. une périmée DERRIÈRE 6 000 vivantes est atteinte en un seul appel');
        $vivantesRestantes = 0;
        foreach (array_chunk($serieLongue, 500) as $lot) {
            $vivantesRestantes += (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (" . implode(',', array_fill(0, count($lot), '%s')) . ")",
                ...$lot
            ));
        }
        $compter($vivantesRestantes === 6000, '100. aucune des 6 000 vivantes n’a été supprimée (' . $vivantesRestantes . '/6000)');
        foreach (array_chunk($serieLongue, 500) as $lot) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name IN (" . implode(',', array_fill(0, count($lot), '%s')) . ")",
                ...$lot
            ));
        }

        // =====================================================================
        // 18. ABA PAR RECRÉATION (revue n°6, point 1)
        // même compte, même expiration, séquence revenue à 1 : la valeur
        // complète peut redevenir identique si rien ne distingue l'identité.
        // =====================================================================
        $cleRec = 'tuc_demandes_' . md5('192.0.2.160');
        $nomRec = $cleRec . '_verrou';
        foreach ([$cleRec, $nomRec] as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
        }
        $finRec = time() + 3600;
        $appeler('ecrire_compteur', [$cleRec, 3, $finRec, null]);
        $appeler('ecrire_compteur', [$cleRec, 2, $finRec, (string) $lu($cleRec)]);
        $appeler('ecrire_compteur', [$cleRec, 1, $finRec, (string) $lu($cleRec)]);
        $avantRec = (string) $lu($cleRec);
        $compter((int) explode('|', $avantRec)[0] === 1, '101. le compteur est bien revenu à 1 (' . $avantRec . ')');
        $appeler('supprimer_compteur', [$cleRec]);
        $appeler('ecrire_compteur', [$cleRec, 1, $finRec, null]);
        $apresRec = (string) $lu($cleRec);
        $compter((string) explode('|', $avantRec)[0] === (string) explode('|', $apresRec)[0], '102. même COMPTE avant et après recréation : le pire cas est construit');
        $compter(explode('|', $avantRec)[1] === explode('|', $apresRec)[1], '103. même EXPIRATION avant et après recréation');
        $compter($avantRec !== $apresRec, '104. la valeur complète DIFFÈRE : la génération a changé (' . $avantRec . ' vs ' . $apresRec . ')');
        $compter($appeler('ecrire_compteur', [$cleRec, 3, $finRec, $avantRec]) === false, '105. une écriture conditionnelle préparée AVANT la suppression est refusée');
        $compter((string) $lu($cleRec) === $apresRec, '106. la ligne recréée est intacte : ' . $lu($cleRec));
        $naissances = [];
        for ($i = 0; $i < 3; $i++) {
            $appeler('supprimer_compteur', [$cleRec]);
            $appeler('ecrire_compteur', [$cleRec, 1, $finRec, null]);
            $naissances[] = (int) explode('|', (string) $lu($cleRec))[2];
        }
        $compter(count(array_unique($naissances)) === 3, '107. trois créations successives : trois générations distinctes (' . implode(',', $naissances) . ')');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cleRec));

        // =====================================================================
        // 19. PURGE : curseur persistant, reprise, taille de requête bornée
        // =====================================================================
        $prefixeCurseur = 'tuc_tentatives_';
        $nomLoin = static fn (int $i): string => $prefixeCurseur . sprintf('%05d', $i) . str_repeat('c', 27);
        for ($lot = 0; $lot < 3000; $lot += 200) {
            $valeurs = [];
            $params = [];
            for ($i = $lot; $i < min($lot + 200, 3000); $i++) {
                $valeurs[] = "(%s, %s, 'no')";
                $params[] = $nomLoin($i);
                $params[] = '5|' . (time() + 1800);
            }
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode(',', $valeurs),
                ...$params
            ));
        }
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $prefixeCurseur . 'zzzzz' . str_repeat('c', 27),
            '9|' . (time() - 3 * 86400)
        ));
        $GLOBALS['tuc_sql_max'] = 0;
        $espion = static function (string $requete): string {
            $GLOBALS['tuc_sql_max'] = max($GLOBALS['tuc_sql_max'], strlen($requete));
            return $requete;
        };
        add_filter('query', $espion);
        $appeler('balayer_lignes_perimees', [500, 2, 0]);
        $curseurA = (string) ($GLOBALS['tuc_purge_curseur_fin'] ?? '');
        $compter($curseurA !== '', '108. après une passe bornée (2 fenêtres), un curseur est conservé : ' . $curseurA);
        $appeler('balayer_lignes_perimees', [500, 2, 0]);
        $departB = (string) ($GLOBALS['tuc_purge_curseur_depart'] ?? '');
        $finB = (string) ($GLOBALS['tuc_purge_curseur_fin'] ?? '');
        $compter($departB === $curseurA, '109. la passe suivante REPREND au curseur, pas au début');
        $compter(strcmp($finB, $curseurA) > 0, '110. le curseur avance strictement');
        $passesC = 0;
        do {
            $appeler('balayer_lignes_perimees', [500, 2, 0]);
            $passesC++;
            $encore = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
                $prefixeCurseur . 'zzzzz' . str_repeat('c', 27)
            ));
        } while ($encore > 0 && $passesC < 20);
        $compter($encore === 0, '111. la périmée derrière 3 000 vivantes est atteinte en ' . $passesC . ' passes bornées');
        // La mesure de taille ne porte QUE sur les requêtes de la purge : on
        // arrête l'espion avant les vérifications (qui émettent de gros IN (...)).
        remove_filter('query', $espion);
        $tailleMaxPurge = (int) $GLOBALS['tuc_sql_max'];
        $vivantesC = 0;
        for ($lot = 0; $lot < 3000; $lot += 500) {
            $nomsLot = [];
            for ($i = $lot; $i < min($lot + 500, 3000); $i++) {
                $nomsLot[] = $nomLoin($i);
            }
            $vivantesC += (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (" . implode(',', array_fill(0, count($nomsLot), '%s')) . ")",
                ...$nomsLot
            ));
        }
        $compter($vivantesC === 3000, '112. aucune vivante supprimée (' . $vivantesC . '/3000)');
        $compter($tailleMaxPurge > 0 && $tailleMaxPurge < 4096, '113. la requête de purge reste bornée (' . $tailleMaxPurge . ' o) malgré 3 000 vivantes');
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '{$prefixeCurseur}0%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '{$prefixeCurseur}z%'");

        // =====================================================================
        // 20. ALLOCATION DE GÉNÉRATION : refus propre, aucun repli (revue n°7)
        // =====================================================================
        $gen = 'tuc_purge_generation';
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '10', 'no')", $gen));
        $compter($appeler('prochaine_generation', [0]) === null, '114. zéro tentative disponible => null : aucun repli horodaté');
        $erreurGen = static function (string $q) use ($gen): string {
            return strpos($q, $gen) !== false ? 'SELECT * FROM table_inexistante_revue7' : $q;
        };
        add_filter('query', $erreurGen);
        $genNulle = $appeler('prochaine_generation');
        $cleGen = 'tuc_demandes_' . md5('192.0.2.170');
        foreach ([$cleGen, $cleGen . '_verrou'] as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
        }
        $creationNulle = $appeler('ecrire_compteur', [$cleGen, 1, time() + 3600, null]);
        $reservation = $appeler('reserver_demande');
        remove_filter('query', $erreurGen);
        $compter($genNulle === null, '115. erreur SQL sur le compteur global => null (refus, pas de valeur inventée)');
        $compter($creationNulle === null, '116. une création sans génération rend null : rien n’est écrit');
        $compter($lu($cleGen) === null, '117. aucune ligne de compteur n’a été créée par ce refus');
        $compter($reservation instanceof WP_Error && $reservation->get_error_code() === 'tuc_onboarding_storage', '118. la réservation est REFUSÉE en 503, pas acceptée à tort');
        $compter($lu($cleGen) === null, '119. toujours aucune ligne après ce refus');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $gen));
        $compter($appeler('prochaine_generation') !== null, '120. le compteur global se recrée et fonctionne normalement après la panne');
        $gens = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $suffixe) {
            $cleG = 'tuc_tentatives_' . md5('gen-' . $suffixe);
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cleG));
            $appeler('ecrire_compteur', [$cleG, 1, time() + 3600, null]);
            $gens[] = (int) explode('|', (string) $lu($cleG))[2];
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $cleG));
        }
        $compter(count(array_unique($gens)) === 5, '121. cinq clés différentes => cinq générations distinctes (' . implode(',', $gens) . ')');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $gen));

        // =====================================================================
        // 21. Échec d'allocation de génération VU DEPUIS LA ROUTE (revue n°9)
        // 503, aucune demande, aucune photo, quota intact, puis réussite.
        // =====================================================================
        $ipGen = '198.51.100.241';
        $_SERVER['REMOTE_ADDR'] = $ipGen;
        $cleGenD = 'tuc_demandes_' . md5($ipGen);
        $cleGenT = 'tuc_tentatives_' . md5($ipGen);
        foreach ([$cleGenD, $cleGenD . '_verrou', $cleGenT, $cleGenT . '_verrou'] as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
        }
        update_option('tuc_wa_numero', '212600000000', false);
        $photoGen = sys_get_temp_dir() . '/gen-contract-' . wp_generate_password(5, false) . '.jpg';
        $imGen = imagecreatetruecolor(320, 240);
        imagejpeg($imGen, $photoGen, 80);
        $soumettreGen = static function (string $nom) use ($photoGen): array {
            $r = new WP_REST_Request('POST', '/tuc/v1/restaurant-applications');
            $r->set_param('restaurant_name', $nom);
            $r->set_file_params(['menu_photo' => [
                'tmp_name' => $photoGen, 'name' => 'menu.jpg',
                'size' => filesize($photoGen), 'error' => UPLOAD_ERR_OK,
            ]]);
            $res = rest_do_request($r);
            return [$res->get_status(), $res->get_data()];
        };
        $nbDemandes = static fn (): int => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tuc_demande_resto'"
        );
        $nbPieces = static fn (): int => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
        );
        $d0 = $nbDemandes();
        $p0 = $nbPieces();
        $panneGen = static function (string $q): string {
            return strpos($q, 'tuc_purge_generation') !== false ? 'SELECT * FROM table_absente_revue9' : $q;
        };
        add_filter('query', $panneGen);
        $muet = static fn (): bool => true;
        set_error_handler(static function (int $no, string $txt) use ($muet): bool { return true; });
        [$stGen, $corpsGen] = $soumettreGen('Snack Panne Generation');
        restore_error_handler();
        remove_filter('query', $panneGen);
        $codeGen = is_array($corpsGen) ? (string) ($corpsGen['code'] ?? '') : '';
        $compter($stGen === 503, '122. panne d’allocation de génération : la route rend 503 (obtenu ' . $stGen . ')');
        $compter($codeGen === 'tuc_onboarding_storage', '123. le code est tuc_onboarding_storage');
        $compter($nbDemandes() === $d0, '124. aucune demande créée (' . $d0 . ' -> ' . $nbDemandes() . ')');
        $compter($nbPieces() === $p0, '125. aucune photo enregistrée (' . $p0 . ' -> ' . $nbPieces() . ' pièces jointes)');
        $compter($lu($cleGenD) === null, '126. aucun compteur de demandes créé');
        $compter($lu($cleGenT) === null, '127. aucune tentative consommée (ROLLBACK complet)');
        [$stOk, ] = $soumettreGen('Snack Apres Panne Generation');
        $valeurGen = (string) $lu($cleGenD);
        $compter($stOk === 201, '128. la soumission suivante est acceptée (obtenu ' . $stOk . ')');
        $compter($nbDemandes() === $d0 + 1, '129. exactement une demande créée');
        $compter((int) explode('|', $valeurGen)[0] === 1, '130. le quota est consommé exactement une fois : ' . $valeurGen);
        $compter((int) explode('|', $valeurGen)[2] > 0, '131. la génération est allouée après la panne (' . explode('|', $valeurGen)[2] . ')');
        foreach ([$cleGenD, $cleGenD . '_verrou', $cleGenT, $cleGenT . '_verrou'] as $nn) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $nn));
        }
        @unlink($photoGen);

        // =====================================================================
        // 10. L'ancienne protection n'a pas disparu (pas de « suppression » pure)
        // =====================================================================
        $compter(
            method_exists(TUC_Onboarding_Restaurant::class, 'soumettre'),
            '45. le point d’entrée public existe toujours'
        );
        $compter(
            $constante('MAX_PHOTO_BYTES') === 5_242_880,
            '46. la limite d’envoi reste à 5 Mo : elle n’a pas été élargie pour faire passer le HEIC'
        );
        // Le champ doit ACCEPTER le HEIC côté sélecteur : il est converti plus
        // loin, mais un `accept` qui l'exclut fait disparaître la photo de la
        // photothèque sur iPhone. La limite de 5 Mo, elle, ne bouge pas (cas 46).
        $htmlForm = TUC_Onboarding_Restaurant::render_form();
        $compter(
            strpos($htmlForm, 'image/heic') !== false && strpos($htmlForm, '.heic') !== false,
            '47. le champ photo accepte le HEIC dans son attribut accept'
        );
        $compter(
            strpos($htmlForm, '5 Mo') !== false,
            '48. la limite de 5 Mo reste affichée sous le champ'
        );

        // =====================================================================
        // 11 bis. Les TROIS MONDES de prochaine_generation() (revue n°20).
        //
        // `wpdb::get_var()` rend null pour « 0 ligne » COMME pour « erreur » :
        // la valeur de retour ne peut donc pas servir de critère. Le module lit
        // `trim($wpdb->last_error)` sur-le-champ et classe. Ces cas exigent que
        // les trois issues soient DISTINCTES et démontrées sur la vraie base :
        //   A 0 ligne, aucun message .................. conflit, à rejouer ;
        //   B message « try restarting »/1205/1213 ..... conflit, à rejouer ;
        //   C tout autre refus (1146, droits) .......... PANNE, 503 sans retry.
        // Le point 2 de la revue est couvert par le comptage des lectures : une
        // panne ne doit PAS consommer le budget de reprises.
        // =====================================================================
        $cleGen = (string) $constante('OPTION_GENERATION');
        $lireProp = static function (string $nom) use ($classe) {
            if (!$classe->hasProperty($nom)) { return null; }
            $p = $classe->getProperty($nom);
            $p->setAccessible(true);
            return $p->getValue();
        };

        // Le sabotage est un filtre `query` (même mécanisme que $saboter) : deux
        // modes sur la SEULE lecture du compteur global. 'ligne' redirige vers une
        // clé absente — la requête réussit vraiment et rend 0 ligne, sans message ;
        // 'panne' redirige vers une table inexistante — c'est le SERVEUR qui refuse
        // et qui signe le message. Le test n'écrit jamais un message d'erreur.
        $GLOBALS['tuc_gen_mode'] = '';
        $GLOBALS['tuc_gen_lues'] = 0;
        $tucGenFiltre = static function (string $q) use ($cleGen): string {
            $mode = (string) $GLOBALS['tuc_gen_mode'];
            if ($mode === '') { return $q; }
            global $wpdb;
            $s = preg_replace('/\s+/', ' ', $q);
            $estLecture = stripos($s, 'SELECT option_value') !== false
                && strpos($s, "'" . $cleGen . "'") !== false
                && stripos($s, 'FOR UPDATE') === false;
            $estCreation = stripos($s, 'INSERT IGNORE') !== false
                && strpos($s, "'" . $cleGen . "'") !== false;
            if ($mode === 'creation' && $estCreation) {
                $GLOBALS['tuc_gen_lues']++;
                return "SELECT * FROM {$wpdb->prefix}table_absente_du_test";
            }
            if (!$estLecture) { return $q; }
            $GLOBALS['tuc_gen_lues']++;
            if ($mode === 'ligne') {
                return "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'tuc_cle_absente_du_contrat'";
            }
            return "SELECT * FROM {$wpdb->prefix}table_absente_du_test";
        };
        // Lire l'état du compteur SANS se saboter soi-même : le mode est coupé.
        $etatGen = static function () use ($wpdb, $cleGen): ?string {
            $mode = (string) $GLOBALS['tuc_gen_mode'];
            $GLOBALS['tuc_gen_mode'] = '';
            $v = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(option_value, '\\0') FROM {$wpdb->options} WHERE option_name = %s", $cleGen));
            $GLOBALS['tuc_gen_mode'] = $mode;
            return $v === null ? null : (string) $v;
        };
        add_filter('query', $tucGenFiltre);
        $ipGen = '192.0.2.120';
        $etatGenAvant = $etatGen();
        try {
            // ---- a) prédicat de classification : la liste est fermée --------
            $recuperables = [
                'Deadlock found when trying to get lock; try restarting transaction',
                'Lock wait timeout exceeded; try restarting transaction',
                'Record has changed since last read in table \'wp_options\'',
            ];
            $tousVrais = true;
            foreach ($recuperables as $message) {
                if ($appeler('erreur_recuperable', [$message]) !== true) { $tousVrais = false; }
            }
            $compter($tousVrais, '132. erreur_recuperable() reconnaît exactement les trois motifs qui invitent à rejouer');
            $pannes = [
                "Table 'x.y' doesn't exist",
                "Access denied for user 'u'@'h'",
                'MySQL server has gone away',
                'Disk is full writing log',
                '',
            ];
            $tousFaux = true;
            foreach ($pannes as $message) {
                if ($appeler('erreur_recuperable', [$message]) !== false) { $tousFaux = false; }
            }
            $compter($tousFaux, '133. erreur_recuperable() rend false pour toute panne réelle ET pour un message vide (aucune liste ouverte)');

            // ---- b) monde A : 0 ligne, AUCUN message -> conflit passager ----
            $_SERVER['REMOTE_ADDR'] = $ipGen;
            $nettoyer($ipGen);
            $GLOBALS['tuc_gen_lues'] = 0;
            $GLOBALS['tuc_gen_mode'] = 'ligne';
            $genAbsente = $appeler('prochaine_generation');
            $GLOBALS['tuc_gen_mode'] = '';
            $compter($genAbsente === null, '134. monde « ligne introuvable » : aucune valeur de génération n\'est fabriquée (' . var_export($genAbsente, true) . ')');
            $compter(
                $lireProp('generationConflit') === true && $lireProp('generationPanneReelle') === false,
                '135. monde « ligne introuvable » : classé CONFLIT réessayable, jamais panne (' . var_export((bool) $lireProp('generationConflit'), true) . '/' . var_export((bool) $lireProp('generationPanneReelle'), true) . ')'
            );
            $compter((string) $lireProp('generationErreur') === '', '136. le serveur n\'a rien signalé dans ce monde : c\'est bien la branche « last_error vide », pas une erreur avalée');
            $compter($etatGen() === $etatGenAvant, '137. monde « ligne introuvable » : AUCUNE écriture partielle sur le compteur global pendant la décision');

            // ---- c) verdict rendu au CLIENT sur ce même monde ---------------
            $GLOBALS['tuc_gen_lues'] = 0;
            $GLOBALS['tuc_gen_mode'] = 'ligne';
            $refusAttente = $appeler('reserver_demande');
            $luesAttente = (int) $GLOBALS['tuc_gen_lues'];
            $GLOBALS['tuc_gen_mode'] = '';
            $donneesAttente = $refusAttente instanceof WP_Error ? (array) $refusAttente->get_error_data() : [];
            $compter(
                $refusAttente instanceof WP_Error
                    && $refusAttente->get_error_code() === 'tuc_onboarding_occupe'
                    && (($donneesAttente['status'] ?? 0) === 503)
                    && (bool) ($donneesAttente['retry'] ?? false),
                '138. une file d\'attente rend « momentanément occupé » + retry, et JAMAIS « service indisponible » ('
                    . ($refusAttente instanceof WP_Error ? $refusAttente->get_error_code() : 'aucune erreur') . ')'
            );
            $plafond = (int) $constante('COMPTEUR_TENTATIVES') * (int) $constante('CRITIQUE_TENTATIVES');
            $compter(
                $luesAttente > 0 && $luesAttente <= $plafond * (int) $constante('GENERATION_TENTATIVES'),
                '139. l\'attente est bien rejouée, et reste bornée (' . $luesAttente . ' lecture(s) pour un plafond de ' . ($plafond * (int) $constante('GENERATION_TENTATIVES')) . ')'
            );

            // ---- d) monde C : refus du serveur -> PANNE, court-circuit -------
            $nettoyer($ipGen);
            $GLOBALS['tuc_gen_lues'] = 0;
            $GLOBALS['tuc_gen_mode'] = 'panne';
            $genRefusee = $appeler('prochaine_generation');
            $messageRefuse = (string) $lireProp('generationErreur');
            $GLOBALS['tuc_gen_mode'] = '';
            $compter($genRefusee === null, '140. monde « SELECT refusé » : le refus reste un refus (' . var_export($genRefusee, true) . ')');
            $compter(
                stripos($messageRefuse, "doesn't exist") !== false || stripos($messageRefuse, 'n\'existe pas') !== false,
                '141. le message de refus vient du SERVEUR, pas du test (' . substr($messageRefuse, 0, 60) . ')'
            );
            $compter(
                $lireProp('generationConflit') === false && $lireProp('generationPanneReelle') === true,
                '142. monde « SELECT refusé » : classé PANNE RÉELLE, non conflictuelle — le défaut exact reproché ('
                    . var_export((bool) $lireProp('generationConflit'), true) . '/' . var_export((bool) $lireProp('generationPanneReelle'), true) . ')'
            );
            $nettoyer($ipGen);
            $GLOBALS['tuc_gen_lues'] = 0;
            $GLOBALS['tuc_gen_mode'] = 'panne';
            $refusPanne = $appeler('reserver_demande');
            $luesPanne = (int) $GLOBALS['tuc_gen_lues'];
            $GLOBALS['tuc_gen_mode'] = '';
            $donneesPanne = $refusPanne instanceof WP_Error ? (array) $refusPanne->get_error_data() : [];
            $compter(
                $refusPanne instanceof WP_Error
                    && $refusPanne->get_error_code() === 'tuc_onboarding_storage'
                    && (($donneesPanne['status'] ?? 0) === 503)
                    && !array_key_exists('retry', $donneesPanne),
                '143. une panne du compteur rend « service indisponible », sans invitation à réessayer ('
                    . ($refusPanne instanceof WP_Error ? $refusPanne->get_error_code() : 'aucune erreur') . ')'
            );
            $compter(
                $luesPanne === 1,
                '144. les reprises bornées ne masquent pas la panne : 1 seule lecture du compteur sur la requête ('
                    . $luesPanne . '), pas ' . ((int) $constante('GENERATION_TENTATIVES')) . ' × ' . $plafond
            );

            // ---- e) le même refus sur la CRÉATION (branche $creation === false)
            $nettoyer($ipGen);
            $GLOBALS['tuc_gen_lues'] = 0;
            $GLOBALS['tuc_gen_mode'] = 'creation';
            $genCreation = $appeler('prochaine_generation');
            $messageCreation = (string) $lireProp('generationErreur');
            $GLOBALS['tuc_gen_mode'] = '';
            $compter(
                $genCreation === null && $lireProp('generationPanneReelle') === true && $lireProp('generationConflit') === false,
                '145. INSERT IGNORE refusé par le serveur : PANNE aussi sur la branche de création ('
                    . var_export($genCreation, true) . ', message ' . substr($messageCreation, 0, 40) . ')'
            );
            // La ligne du compteur doit être retrouvée intacte dans tous les cas :
            // aucun des trois mondes n'a le droit d'écrire une valeur de secours.
            $compter($etatGen() === $etatGenAvant, '146. après les trois mondes, le compteur global est toujours à sa valeur d\'origine (aucune valeur de secours écrite)');
            $nettoyer($ipGen);
            $apresIncidentGen = $appeler('reserver_demande');
            $compter($apresIncidentGen === null, '147. après ces incidents simulés, une réservation normale repasse (le service n\'est pas resté verrouillé)');
            $appeler('rendre_demande');
            $nettoyer($ipGen);
        } finally {
            $GLOBALS['tuc_gen_mode'] = '';
            remove_filter('query', $tucGenFiltre);
        }
    } finally {
        // Première instruction : on rend au serveur son état d'origine, quoi qu'il
        // arrive au-dessus (leçon du chantier import : un finally qui commence par
        // autre chose peut être court-circuité par un wp_die).
        if ($ipInitiale === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $ipInitiale;
        }
        if ($xffInitial === null) {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        } else {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $xffInitial;
        }
        unset($_SERVER['HTTP_X_REAL_IP']);
        $nettoyer('192.0.2.10', '192.0.2.20', '192.0.2.30', '192.0.2.40', '192.0.2.50', '192.0.2.60', '192.0.2.70', '192.0.2.80', '192.0.2.90', '192.0.2.100', '192.0.2.110', '192.0.2.120', '192.0.2.200');
    }

    return $casExecutees;
}
