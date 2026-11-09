<?php
/**
 * B1 — purger la demo avant un pilote.
 * Ne supprime QUE les objets marques par tools/seed-demo.php.
 * Mode lecture seule par defaut ; --appliquer pour ecrire.
 */
if (!defined('ABSPATH')) { exit; }
$appliquer = in_array('--appliquer', $args ?? [], true);
$slugs = ['demo-table-du-port','demo-jardin-olive','demo-maison-grill','demo-laiterie-atlas',
  'demo-source-lait','demo-atelier-tacos','demo-comptoir-burger','demo-four-a-bois',
  'demo-cafe-des-arts','demo-terrasse-the','demo-torrefaction','demo-brunch-club'];
$total = ['restos' => 0, 'plats' => 0, 'commandes' => 0];
foreach ($slugs as $slug) {
    $r = get_page_by_path($slug, OBJECT, 'tuc_resto');
    if (!$r) { continue; }
    $plats = get_posts(['post_type'=>'tuc_menu_item','numberposts'=>0,'post_status'=>'any',
      'meta_key'=>'tuc_resto_id','meta_value'=>$r->ID,'fields'=>'ids']);
    $cmds = get_posts(['post_type'=>'tuc_commande','numberposts'=>0,'post_status'=>'any',
      'meta_key'=>'tuc_resto','meta_value'=>$r->ID,'fields'=>'ids']);
    echo sprintf("  %-24s plats=%d commandes=%d\n", $slug, count($plats), count($cmds));
    $total['restos']++; $total['plats'] += count($plats); $total['commandes'] += count($cmds);
    if ($appliquer) {
        foreach ($plats as $id) { wp_delete_post($id, true); }
        foreach ($cmds as $id) { wp_delete_post($id, true); }
        wp_delete_post($r->ID, true);
    }
}
echo $appliquer ? "SUPPRIME" : "SIMULATION (ajouter --appliquer)", " : ",
  $total['restos'], " resto(s), ", $total['plats'], " plat(s), ", $total['commandes'], " commande(s)\n";
