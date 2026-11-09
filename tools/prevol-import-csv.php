<?php
/**
 * Pré-vol des fonctions PURES de l'import CSV.
 *
 * Ne remplace pas `tests/import-csv-contract.php`, qui tourne sous WordPress réel
 * dans la CI. Ici on vérifie seulement ce qui peut l'être sans base : lecture,
 * interprétation des prix, bornes, reconstruction des groupes, génération des
 * modèles. C'est ce qui tourne quand la CI ne peut pas être câblée.
 */
require __DIR__ . '/stub-wordpress.php';

$src = file_get_contents(dirname(__DIR__) . '/wordpress/tuc-market-core/includes/modules/class-tuc-import-csv.php');
// `declare(strict_types=1)` n'est pas une instruction comme les autres : on le
// retire ici, sinon l'eval refuse. La CI charge le fichier par require_once,
// où il occupe bien la première instruction — c'est le seul endroit où ce
// retrait est légitime.
$src = preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/', '<?php ', $src, 1);
$tmp = tempnam(sys_get_temp_dir(), 'tuc-module');
file_put_contents($tmp, $src);
require $tmp;
unlink($tmp);

$ok = 0;
$ko = 0;
function cas(string $nom, bool $cond): void
{
    global $ok, $ko;
    if ($cond) {
        $ok++;
        echo "  OK    $nom\n";
    } else {
        $ko++;
        echo "  ECHEC $nom\n";
    }
}

echo "Pré-vol — fonctions pures de l'import CSV\n";

// ---- lecture du CSV ----
$lu = TUC_Import_CSV::lire_csv("\xEF\xBB\xBFreference;nom;prix\r\ntacos-poulet;Tacos fromagère;45,00\r\n");
cas('1. BOM retiré, en-tête intacte', !is_wp_error($lu) && array_key_first($lu['lignes'][2]) === 'reference');
cas('2. accents préservés', ($lu['lignes'][2]['nom'] ?? '') === 'Tacos fromagère');
$lu2 = TUC_Import_CSV::lire_csv("reference,nom,prix\ntacos,Tacos,45.00\n");
cas('3. séparateur virgule accepté', !is_wp_error($lu2) && ($lu2['lignes'][2]['prix'] ?? '') === '45.00');
cas('4. nombre de colonnes incohérent = refus', is_wp_error(TUC_Import_CSV::lire_csv("a;b;c\n1;2\n")));

// ---- prix ----
cas('5. 45,50 vaut 45.50', TUC_Import_CSV::lire_prix('45,50') === 45.5);
cas('6. 45.50 vaut 45.50', TUC_Import_CSV::lire_prix('45.50') === 45.5);
cas('7. A_VERIFIER est une erreur', is_wp_error(TUC_Import_CSV::lire_prix('A_VERIFIER')));
cas('8. « 45 MAD » est une erreur', is_wp_error(TUC_Import_CSV::lire_prix('45 MAD')));

// ---- groupes ----
$incoherent = ['plat_reference' => 'p', 'groupe_id' => 'taille', 'groupe_nom' => 'Taille',
    'obligatoire' => 'oui', 'min' => '0', 'max' => '1', 'choix_id' => 'm', 'choix_nom' => 'M',
    'supplement_prix' => '0', 'disponible' => 'oui'];
$coherent = $incoherent;
$coherent['min'] = '1';

cas('9. obligatoire=oui avec min=0 refusé', TUC_Import_CSV::analyser_groupes('p', [2 => $incoherent]) !== []);
cas('10. obligatoire=oui avec min=1 accepté', TUC_Import_CSV::analyser_groupes('p', [2 => $coherent]) === []);
$divergent = $coherent;
$divergent['max'] = '2';
cas('11. max divergent entre deux lignes refusé', TUC_Import_CSV::analyser_groupes('p', [2 => $coherent, 3 => $divergent]) !== []);
$treize = [];
for ($i = 1; $i <= 13; $i++) {
    $g = $coherent;
    $g['groupe_id'] = 'g' . $i;
    $treize[$i] = $g;
}
cas('12. 13 groupes refusés', TUC_Import_CSV::analyser_groupes('p', $treize) !== []);
$vingtEtUn = [];
for ($i = 1; $i <= 21; $i++) {
    $g = $coherent;
    $g['choix_id'] = 'c' . $i;
    $vingtEtUn[$i] = $g;
}
cas('13. 21 choix dans UN groupe refusés', TUC_Import_CSV::analyser_groupes('p', $vingtEtUn) !== []);
cas('14. choix_id en double refusé', TUC_Import_CSV::analyser_groupes('p', [2 => $coherent, 3 => $coherent]) !== []);

// ---- arabes : la limite est en OCTETS ----
$arabe = 'طاجين سمك بالخضر';
$ligneArabe = ['reference' => 'arabe', 'nom' => $arabe, 'description' => '', 'prix' => '60',
    'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui'];
cas('15. nom arabe accepté (' . strlen($arabe) . ' octets)', TUC_Import_CSV::analyser(['lignes' => [2 => $ligneArabe]], null, 1)['erreurs'] === 0);
$trop = $ligneArabe;
$trop['nom'] = str_repeat('ب', 250); // 500 octets
cas('16. nom de 500 octets refusé (limite 400 OCTETS)', TUC_Import_CSV::analyser(['lignes' => [2 => $trop]], null, 1)['erreurs'] > 0);

// ---- seuil ----
$cher = ['reference' => 'cher', 'nom' => 'Cher', 'description' => '', 'prix' => '4500',
    'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui'];
$analyse = TUC_Import_CSV::analyser(['lignes' => [2 => $cher]], null, 1);
$avertissements = implode(' | ', $analyse['lignes'][0]['avertissements']);
cas('17. 4500 MAD est VALIDE mais averti sur le PRIX', $analyse['erreurs'] === 0 && str_contains($avertissements, 'au-dessus du seuil'));
cas('18. un avertissement ne bloque pas', !$analyse['bloquant']);

// ---- référence dupliquée ----
$base = ['reference' => 'x', 'nom' => 'A', 'description' => '', 'prix' => '10',
    'categorie' => '', 'promo_pct' => '0', 'disponible' => 'oui'];
$double = $base;
$double['nom'] = 'B';
cas('19. référence dupliquée dans le fichier refusée', TUC_Import_CSV::analyser(['lignes' => [2 => $base, 3 => $double]], null, 1)['bloquant']);

// ---- option orpheline ----
$orpheline = TUC_Import_CSV::analyser(
    ['lignes' => [2 => $base]],
    ['lignes' => [3 => ['plat_reference' => 'inconnu', 'groupe_id' => 'g', 'groupe_nom' => 'G',
        'obligatoire' => 'non', 'min' => '0', 'max' => '1', 'choix_id' => 'c', 'choix_nom' => 'C',
        'supplement_prix' => '0', 'disponible' => 'oui']]],
    1
);
cas('20. option rattachée à un plat inexistant bloquée', $orpheline['bloquant']);

// ---- reconstruction des groupes ----
$options = TUC_Import_CSV::construire_options('tacos', [
    2 => ['plat_reference' => 'tacos', 'groupe_id' => 'taille', 'groupe_nom' => 'Taille', 'obligatoire' => 'oui',
          'min' => '1', 'max' => '1', 'choix_id' => 'm', 'choix_nom' => 'M', 'supplement_prix' => '0', 'disponible' => 'oui'],
    3 => ['plat_reference' => 'tacos', 'groupe_id' => 'taille', 'groupe_nom' => 'Taille', 'obligatoire' => 'oui',
          'min' => '1', 'max' => '1', 'choix_id' => 'l', 'choix_nom' => 'L', 'supplement_prix' => '10.00', 'disponible' => 'oui'],
    4 => ['plat_reference' => 'tacos', 'groupe_id' => 'sauce', 'groupe_nom' => 'Sauces', 'obligatoire' => 'non',
          'min' => '0', 'max' => '2', 'choix_id' => 'a', 'choix_nom' => 'A', 'supplement_prix' => '5,00', 'disponible' => 'oui'],
]);
cas('21. deux groupes reconstruits', count($options['groups']) === 2);
cas('22. ordre du fichier conservé (taille avant sauce)', $options['groups'][0]['id'] === 'taille');
cas('23. supplément « 5,00 » devient 5.0', $options['groups'][1]['choices'][0]['price_delta'] === 5.0);
cas('24. version de schéma reprise du cœur', $options['version'] === 2);
cas('25. une ligne d’un autre plat est ignorée',
    TUC_Import_CSV::construire_options('autre', [2 => ['plat_reference' => 'tacos', 'groupe_id' => 'g', 'groupe_nom' => 'G',
        'obligatoire' => 'non', 'min' => '0', 'max' => '1', 'choix_id' => 'c', 'choix_nom' => 'C',
        'supplement_prix' => '0', 'disponible' => 'oui']]) === []);

// ---- génération des modèles ----
$csv = TUC_Import_CSV::csv_plats();
cas('26. plats.csv commence par le BOM', str_starts_with($csv, "\xEF\xBB\xBF"));
cas('27. plats.csv n’a PAS de colonne image', !str_contains(explode("\r\n", $csv)[0], 'image'));
cas('28. plats.csv séparateur point-virgule', str_contains(explode("\r\n", $csv)[0], ';'));
cas('29. plats.csv relisible par notre propre lecteur', !is_wp_error(TUC_Import_CSV::lire_csv($csv)));
$consigne = TUC_Import_CSV::consigne_ia();
cas('30. consigne : 200 caractères pour un nom arabe', str_contains($consigne, '200 caractères maximum'));
cas('31. consigne : aucune colonne image promise', !str_contains($consigne, '- image :'));
cas('32. consigne : séparateur point-virgule annoncé', str_contains($consigne, 'séparateur point-virgule'));

// ---- images ----
$images = TUC_Import_CSV::images_valides();
cas('33. bannières exclues (' . count($images) . ' retenues)', !in_array('hero-banner', $images, true) && !in_array('banner-cafe', $images, true));
// 28 jpg au total, dont 3 bannières (banner-cafe, banner-tacos, hero-banner) => 25.
cas('34. 25 visuels non-bannières (28 jpg moins 3 bannières)', count($images) === 25);

echo "\n$ok OK, $ko ECHEC\n";
exit($ko === 0 ? 0 : 1);
