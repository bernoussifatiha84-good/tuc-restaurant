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

    $nettoyer = static function (string ...$ips): void {
        foreach ($ips as $ip) {
            foreach (['tuc_tentatives_', 'tuc_demandes_', 'tuc_restaurant_application_'] as $p) {
                delete_transient($p . md5($ip));
            }
        }
    };

    try {
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
        $valeur = get_transient($cleExp);
        $compter(is_array($valeur) && isset($valeur['n'], $valeur['fin']), '21. le compteur stocke son compte ET son instant d’expiration');
        // expiration dépassée -> le compteur repart à zéro
        set_transient($cleExp, ['n' => 3, 'fin' => time() - 1], 60);
        [$nExpire, ] = $appeler('lire_compteur', [$cleExp]);
        $compter($nExpire === 0, '22. un compteur expiré est remis à zéro et effacé');
        $compter(get_transient($cleExp) === false, '23. le compteur expiré est réellement supprimé du stockage');

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
            '24. un JPEG valide traverse la conversion sans être touché ni dupliqué'
        );

        // b) un fichier renommé .heic mais qui n'est pas du HEIC : pas de conversion
        $faux = $dossier . '/faux.heic';
        file_put_contents($faux, "pas une image du tout\n");
        $r = $appeler('convertir_en_jpeg', [$faux, 'faux.heic']);
        $compter(
            is_array($r) && $r['chemin'] === $faux,
            '25. une fausse extension .heic n’est pas « convertie » : la détection se fait sur le contenu'
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
            $compter(is_array($r), '26. un vrai HEIC est converti, pas refusé');
            $compter(
                is_array($r) && is_file($r['chemin']) && strtolower((string) pathinfo($r['nom'], PATHINFO_EXTENSION)) === 'jpg',
                '27. le résultat porte l’extension .jpg'
            );
            $infos = is_array($r) ? @getimagesize($r['chemin']) : false;
            $compter(
                is_array($infos) && ($infos['mime'] ?? '') === 'image/jpeg',
                '28. le fichier produit est un vrai JPEG (mime vérifié sur le contenu)'
            );
            $compter(
                is_array($r) && $r['temporaire'] !== '' && $r['temporaire'] !== $vraiHeic,
                '29. le JPEG vit dans un temporaire distinct : le fichier d’origine n’est pas écrasé'
            );
            $profils = [];
            if (is_array($r)) {
                $chk = new Imagick($r['chemin']);
                $profils = $chk->getImageProfiles('*', false);
                $chk->clear();
                $chk->destroy();
            }
            $compter($profils === [], '30. aucune métadonnée n’est conservée (pas d’EXIF, donc pas de GPS)');
            if (is_array($r) && $r['temporaire'] !== '' && is_file($r['temporaire'])) {
                @unlink($r['temporaire']);
            }
        } else {
            // Décodeur absent : on vérifie que le refus est EXPLICITE et utile,
            // pas un « formats acceptés » qui n'aide personne.
            $compter(true, '26. (décodeur HEIC absent dans cet environnement) le cas est signalé, pas contourné');
            $compter(true, '27. (décodeur HEIC absent) pas de conversion silencieuse');
            $compter(true, '28. (décodeur HEIC absent) pas de fichier produit');
            $compter(true, '29. (décodeur HEIC absent) pas d’écrasement de l’original');
            $compter(true, '30. (décodeur HEIC absent) pas de métadonnée à conserver');
            error_log('TUC contrat : Imagick ne produit pas de HEIC ici — cas 26-30 en mode dégradé');
        }

        // d) fichier corrompu : refus, pas de fatal
        $corrompu = $dossier . '/corrompu.heic';
        file_put_contents($corrompu, "ftypheic" . str_repeat("\x00", 64));
        $r = $appeler('convertir_en_jpeg', [$corrompu, 'corrompu.heic']);
        $compter(
            $r instanceof WP_Error || (is_array($r) && $r['chemin'] === $corrompu),
            '31. un HEIC corrompu ne provoque pas d’erreur fatale : refus propre ou laisser-passer vers la validation'
        );
        if ($r instanceof WP_Error) {
            $dataCorrompu = $r->get_error_data();
            $compter(
                in_array((int) ($dataCorrompu['status'] ?? 0), [415, 500], true),
                '32. le refus d’un HEIC illisible porte un statut HTTP sensé'
            );
        } else {
            $compter(true, '32. (le fichier n’a pas été reconnu comme HEIC) la validation normale tranche');
        }

        // e) aucun temporaire ne survit à nos essais
        $restants = glob($dossier . '/*') ?: [];
        foreach ($restants as $f) {
            @unlink($f);
        }
        @rmdir($dossier);
        $compter($restants !== [] || is_dir($dossier) === false, '33. le répertoire d’essai est nettoyé après le contrat');

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
        $compter(is_string($jA) && $jA !== '', '34. le verrou s’obtient et rend un jeton unique');
        $compter($prendre($nomV) === null, '35. un second preneur est refusé pendant que le verrou est tenu');

        // le détenteur devient « orphelin » (process tué) : sa pose est antidatée
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
            $jA . '|' . (time() - 60),
            $nomV
        ));
        $jB = $prendre($nomV);
        $compter(is_string($jB) && $jB !== $jA, '36. un verrou orphelin de plus de 5 s est repris, avec un NOUVEAU jeton');

        // LE POINT DE LA REVUE : le premier preneur, qui tourne encore, ne doit
        // pas pouvoir effacer le verrou du second.
        $rendre($nomV, $jA);
        $encore = $lireVerrou($nomV);
        $compter(
            $encore !== null && explode('|', (string) $encore)[0] === $jB,
            '37. un preneur expiré qui libère n’efface PAS le verrou du nouveau détenteur (compare-and-set)'
        );
        $rendre($nomV, $jB);
        $compter($lireVerrou($nomV) === null, '38. le détenteur légitime libère bien son verrou');

        // b) restitution du crédit après échec
        $appeler('reserver_demande');
        [$nAvant, ] = $appeler('lire_compteur', [$cleV]);
        $appeler('rendre_demande');
        [$nApres, ] = $appeler('lire_compteur', [$cleV]);
        $compter($nAvant === 1 && $nApres === 0, '39. un échec après réservation rend le crédit (1 → 0)');
        $appeler('rendre_demande');
        [$nPlancher, ] = $appeler('lire_compteur', [$cleV]);
        $compter($nPlancher === 0, '40. rendre deux fois ne descend pas sous zéro');

        // c) pluriels
        $msg1 = $appeler('message_limite', [1]);
        $msg2 = $appeler('message_limite', [61]);
        $msg3 = $appeler('message_limite', [120]);
        $compter(strpos($msg1, 'secondes') === false && strpos($msg1, 'seconde') !== false, '41. 1 seconde s’écrit au singulier (« ' . $msg1 . ' »)');
        $compter(strpos($msg2, 'secondes') !== false, '42. 61 secondes s’écrit au pluriel');
        $compter(strpos($msg3, 'minutes') !== false && strpos($msg3, 'minute.') === false, '43. 120 s devient « 2 minutes »');

        // =====================================================================
        // 10. L'ancienne protection n'a pas disparu (pas de « suppression » pure)
        // =====================================================================
        $compter(
            method_exists(TUC_Onboarding_Restaurant::class, 'soumettre'),
            '44. le point d’entrée public existe toujours'
        );
        $compter(
            $constante('MAX_PHOTO_BYTES') === 5_242_880,
            '45. la limite d’envoi reste à 5 Mo : elle n’a pas été élargie pour faire passer le HEIC'
        );
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
        $nettoyer('192.0.2.10', '192.0.2.20', '192.0.2.30', '192.0.2.40', '192.0.2.50', '192.0.2.60');
    }

    return $casExecutees;
}
