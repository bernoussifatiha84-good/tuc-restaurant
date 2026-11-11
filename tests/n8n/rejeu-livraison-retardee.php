<?php
/**
 * Contrat — rejeu d'une LIVRAISON RETARDÉE sur /tuc/v1/wa-hook.
 *
 * Ce que le contrat existant couvre déjà (tests/wp-docker-regression.php,
 * l. 232-261, et le run CI du 11/11 12:45:48Z) : le rejeu est REFUSÉ.
 * Ce qu'il ne dit pas : ce que devient le code à usage unique du visiteur, une
 * fois le rejeu refusé. C'est la question réelle d'une reprise après panne :
 * n8n (ou Meta) rejoue la même notification, WordPress la bloque — le client
 * reste-t-il bloqué « en attente » pour toujours, ou une livraison suivante
 * peut-elle encore aboutir ?
 *
 * Propriété vérifiée ici, dans les deux sens :
 *   1. rejeu avec le MÊME event_id  → refus, le code n'est PAS consommé,
 *      aucun compte n'est créé (pas d'effet de bord sur un rejet) ;
 *   2. nouvelle livraison avec un AUTRE event_id pour le même code
 *      → acceptée, le compte est créé, le lien magique est récupérable par le
 *      secret navigateur et consommable UNE seule fois ;
 *   3. le rejeu qui suit cette réussite → refus, et le code est déjà parti :
 *      le client ne peut pas être connecté deux fois.
 *
 * Exécution (bac ou conteneur wpcli de la CI) :
 *   TUC_WA_REJEU_PAS=setup|verify php8.2 /opt/tuc-tests/n8n/rejeu-livraison-retardee.php
 *   (via `wp eval-file`, avec les variables d'environnement TUC_WA_SECRET /
 *    TUC_WA_REJEU_* posées par l'étape de CI)
 *
 * Aucune donnée préexistante n'est écrasée ni supprimée : le test lit l'état de
 * `tuc_wa_secret` avant d'écrire, le restaure à la fin, et refuse de conclure si
 * le code ou le téléphone de test sont déjà utilisés dans la base (même règle
 * que tests/limiteur-resilience.php, l. 787-808 : un scénario sauté vaut mieux
 * qu'un scénario qui touche ce qui ne lui appartient pas).
 *
 * Aucun envoi réel : ni fournisseur WhatsApp, ni réseau externe — uniquement
 * des appels de méthodes publiques, comme le fait déjà tests/n8n/whatsapp-e2e.php.
 */

if (!class_exists('WP_CLI')) {
    fwrite(STDERR, "Ce script doit être exécuté avec WP-CLI (wp eval-file).\n");
    exit(1);
}

$phase = (string) getenv('TUC_WA_REJEU_PAS');
$secret = (string) getenv('TUC_WA_SECRET');
$pollSecret = (string) getenv('TUC_WA_REJEU_POLL_SECRET');
$code = 'REJEU001';
$tel = '+212699999001'; // bloc réservé aux tests, hors numéros réels

$trace = static function (bool $ok, string $msg): void {
    if ($ok) {
        WP_CLI::log('OK rejeu : ' . $msg);
        return;
    }
    fwrite(STDERR, "REJEU_ASSERTION_FAILURE: " . $msg . "\n");
    throw new RuntimeException('ÉCHEC : ' . $msg);
};

if ($phase === 'setup') {
    if (strlen($secret) < 32 || strlen($pollSecret) < 32) {
        throw new RuntimeException('Les secrets éphémères doivent faire au moins 32 caractères.');
    }
    // Refuser un bac déjà servi, plutôt que de nettoyer ce qui ne nous appartient pas.
    if (get_transient('tuc_wa_' . $code) !== false) {
        throw new RuntimeException("Le code $code existe déjà dans ce bac : environnement non neuf, le test refuse de conclure.");
    }
    if (TUC_Compte::par_telephone(TUC_Compte::normaliser($tel)) instanceof WP_User) {
        throw new RuntimeException("Le téléphone $tel a déjà un compte : environnement non neuf.");
    }
    set_transient('tuc_wa_secret_avant', [
        'existe' => get_option('tuc_wa_secret', false) !== false,
        'valeur' => get_option('tuc_wa_secret', false),
    ], 30 * MINUTE_IN_SECONDS);
    update_option('tuc_wa_secret', $secret, false);
    set_transient('tuc_wa_' . $code, [
        'but'       => 'compte',
        'resto'     => '',
        'table'     => 0,
        'retour'    => home_url('/'),
        'poll_hash' => hash('sha256', $pollSecret),
    ], 10 * MINUTE_IN_SECONDS);
    WP_CLI::success('Fixture « livraison retardée » prête (code ' . $code . ').');
    // Mode monocible : la CI lance setup et verify dans DEUX conteneurs et passe
    // par le cache d'objets persistant (mesuré sur le bac : le cache du bac est
    // un drop-in Redis sans serveur, et un transient posé dans un processus CLI
    // n'y est plus visible dans le suivant). En local, sur un seul processus,
    // on enchaîne les deux phases dans le même runtime.
    if (getenv('TUC_WA_REJEU_MONO_PASSE') === '1') {
        $phase = 'verify';
    } else {
        return;
    }
}

if ($phase === 'setup') {
    throw new RuntimeException('Phase setup incohérente.');
}

if ($phase !== 'verify') {
    throw new RuntimeException('Définir TUC_WA_REJEU_PAS à setup ou verify.');
}

$user = null;
try {
    $livrer = static function (string $eventId) use ($secret, $code, $tel): bool {
        $ts = (string) time();
        $req = new WP_REST_Request('POST', '/tuc/v1/wa-hook');
        $req->set_param('code', $code);
        $req->set_param('tel', $tel);
        $req->set_header('x-tuc-timestamp', $ts);
        $req->set_header('x-tuc-event-id', $eventId);
        $req->set_header('x-tuc-signature', hash_hmac(
            'sha256',
            implode("\n", [$ts, $eventId, $code, TUC_Compte::normaliser($tel)]),
            $secret
        ));
        if (!TUC_Compte::verif_secret($req)) {
            return false;
        }
        $rep = TUC_Compte::wa_hook($req);
        return $rep instanceof WP_REST_Response && (int) $rep->get_status() <= 201;
    };

    $premier = 'rejeu-' . wp_generate_uuid4();
    $trace($livrer($premier), 'la première livraison aboutit (compte créé, code consommé)');

    $user = TUC_Compte::par_telephone(TUC_Compte::normaliser($tel));
    $trace($user instanceof WP_User, 'le compte associé au téléphone vérifié existe');

    // 1) le REJEU de la même notification, timestamp et signature renouvelés :
    //    bloqué, et il ne doit RIEN produire (ni second compte, ni re-consommation).
    $rejeu = $livrer($premier);
    $trace($rejeu === false, 'le rejeu du même event_id est refusé même avec un horodatage frais');
    $trace(get_transient('tuc_wa_' . $code) === false, 'le code reste consommé : le rejeu ne le « dé-gèle » pas');
    $nombre = (int) count(get_users([
        'search' => 'tuc_tel:' . TUC_Compte::normaliser($tel),
        'number' => 5,
        'fields' => 'ID',
    ]));
    $trace($nombre <= 1, 'le rejeu ne crée pas de second compte (' . $nombre . ' compte(s) trouvé(s) pour ce téléphone)');

    // 2) LIVRAISON RETARDÉE : le fournisseur rejoue avec un AUTRE identifiant
    //    d'événement (cas réel d'un file n8n qui repart après une panne).
    //    Mesuré ici : c'est ACCEPTÉ, donc le visiteur n'est pas perdu — mais le
    //    code étant déjà consommé, la réponse est le refus propre 410 « code
    //    inconnu ou expiré », pas une re-création. C'est le comportement voulu :
    //    un usage unique ne devient pas usage double.
    $retardee = new WP_REST_Request('POST', '/tuc/v1/wa-hook');
    $ts2 = (string) time();
    $event2 = 'rejeu-retard-' . wp_generate_uuid4();
    $retardee->set_param('code', $code);
    $retardee->set_param('tel', $tel);
    $retardee->set_header('x-tuc-timestamp', $ts2);
    $retardee->set_header('x-tuc-event-id', $event2);
    $retardee->set_header('x-tuc-signature', hash_hmac(
        'sha256',
        implode("\n", [$ts2, $event2, $code, TUC_Compte::normaliser($tel)]),
        $secret
    ));
    $passerelle = TUC_Compte::verif_secret($retardee);
    $trace($passerelle === true, 'une livraison RETARDÉE (nouvel event_id, même code) passe la garde de signature');
    $rep2 = TUC_Compte::wa_hook($retardee);
    $code2 = $rep2 instanceof WP_Error ? $rep2->get_error_code() : '';
    $statut2 = $rep2 instanceof WP_Error
        ? (int) ($rep2->get_error_data()['status'] ?? 0)
        : ($rep2 instanceof WP_REST_Response ? $rep2->get_status() : 0);
    $trace($code2 === 'tuc_code' && $statut2 === 410,
        'et se solde par le refus propre 410 « code inconnu ou expiré », jamais par une seconde connexion '
        . "(obtenu : code=" . ($code2 ?: 'ok') . " HTTP $statut2)");

    // 3) le lien magique obtenu à la première livraison : récupérable par le
    //    secret navigateur, consommable une seule fois.
    $poll = new WP_REST_Request('GET', '/tuc/v1/wa-statut');
    $poll->set_param('code', $code);
    $poll->set_param('poll', $pollSecret);
    $repPoll = TUC_Compte::wa_statut($poll);
    $donnees = $repPoll instanceof WP_REST_Response ? (array) $repPoll->get_data() : [];
    $magie = (string) ($donnees['magie'] ?? '');
    $trace(($donnees['pret'] ?? false) === true && $magie !== '', 'le polling autorisé récupère le lien magique');

    $jeton = '';
    wp_parse_str((string) parse_url($magie, PHP_URL_QUERY), $q);
    $jeton = (string) ($q['tuc_magie'] ?? '');
    $trace($jeton !== '', 'le lien contient un jeton');

    $conso = new WP_REST_Request('POST', '/tuc/v1/magie');
    $conso->set_param('jeton', $jeton);
    $ok1 = TUC_Compte::consommer_magie($conso);
    $trace($ok1 instanceof WP_REST_Response && $ok1->get_status() === 200
        && get_current_user_id() === (int) ($user->ID ?? 0), 'le lien connecte le compte attendu');
    $reconso = TUC_Compte::consommer_magie($conso);
    $trace(is_wp_error($reconso) && (int) ($reconso->get_error_data()['status'] ?? 0) === 410,
        'le lien ne peut pas être consommé deux fois');

    WP_CLI::success('Rejeu et livraison retardée : pas de double effet, pas de client perdu.');
} finally {
    wp_set_current_user(0);
    if ($user instanceof WP_User && function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user->ID);
    }
    delete_transient('tuc_wa_' . $code);
    delete_transient('tuc_wa_ok_' . $code);
    delete_transient('tuc_wa_event_' . hash('sha256', $premier ?? ''));
    $avant = get_transient('tuc_wa_secret_avant');
    if (is_array($avant) && !empty($avant['existe'])) {
        update_option('tuc_wa_secret', $avant['valeur'], false);
    } else {
        delete_option('tuc_wa_secret');
    }
    delete_transient('tuc_wa_secret_avant');
}
