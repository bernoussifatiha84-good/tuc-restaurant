# Décisions du propriétaire

Consignées pour que le code et les tests s'y réfèrent. Une décision change le
contrat ; elle ne se déduit pas du code existant.

## 1. Alerte sonore — tous les rôles caisse, tous les modes

**Décision :** le patron et la cuisine doivent entendre toute nouvelle commande.

Avant, le bip et la vibration exigeait cinq conditions simultanées, dont le rôle
`tuc_serveur` et `mode === 'table'`. Conséquence : un restaurateur tenant sa caisse
n'entendait rien, et une commande emporter ou livraison ne sonnait pour personne.

Après :

| Critère | Règle |
|---|---|
| Capacité `CAP_AUDIBLE_ORDER_ALERTS` | accordée à `tuc_restaurateur`, `tuc_gestionnaire`, `tuc_caissier`, `tuc_serveur` |
| Mode de commande | **aucun filtrage** — table, emporter, livraison |
| Onglet affiché | **aucun filtrage** — l'alerte est calculée sur la liste complète |
| Rattachement `tuc_resto_id` | **obligatoire et conservé** |
| Autre restaurant, ou sans affectation | **refusé**, notification silencieuse |

`TUC_Roles::SCHEMA_VERSION` passe de 3 à 4 : sans ce bump, `synchroniser()` sort
immédiatement sur une installation existante et les nouvelles capacités ne sont
jamais accordées. C'est le piège qui aurait rendu le correctif inopérant en UAT.

Le rôle n'est plus codé en dur dans `profil_alerte()` : **la capacité décide**. Ajouter
un rôle alertable se fait désormais dans `TUC_Roles`, pas dans la logique d'alerte.

Côté caisse, la détection d'arrivée est déplacée de `charger()` — qui ne voit que
l'onglet courant — vers `chargerCompteurs()`, qui interroge tous les statuts. Une
baseline est posée au premier relevé complet pour ne pas sonner sur l'existant.

L'opt-in de l'appareil reste requis : le son ne se déclenche qu'après activation
par l'utilisateur, contrainte des navigateurs mobiles.

## 2. WhatsApp — un numéro de site, pour la vérification seulement

**Décision :** `wa.me` sert à confirmer une création de compte ou à demander un
code. **Les commandes vont au tableau de bord du restaurant, pas à un téléphone.**

Vérifié conforme dans le code : le panier client poste sur `/tuc/v1/commande`
(`assets/pro/client-cart.js`), qui crée la commande en base et alimente la caisse.
Le front n'utilise `wa-lien` et `wa-statut` que pour la vérification.

`get_option('tuc_wa_numero')` est une option **globale au site**. C'est voulu : c'est
le numéro de TUC, pas celui d'un restaurant. Il n'y a pas de numéro par restaurant,
et il n'en faut pas pour ce parcours.

Sans cette option, trois endpoints renvoient **503** et bloquent : création de
compte client, aperçu de panier, inscription restaurant. Ce n'est pas une
dégradation silencieuse, c'est un refus explicite.

**À régler sur chaque environnement**, hors dépôt — le dépôt est public, aucune
valeur réelle n'y est committée :

```bash
wp option update tuc_wa_numero '<indicatif><numéro, chiffres seuls>'
wp option get tuc_wa_numero
```

Le message est pré-rempli et envoyé par le client depuis son propre WhatsApp :
aucun abonnement API, aucun coût par message.

### Point ouvert : l'endpoint `/wa-panier`

`/tuc/v1/wa-panier` est encore enregistré, appelable anonymement, et construit un
lien `wa.me` contenant **tout le panier** vers le numéro du site. Rien dans le
front ne l'appelle, mais il est couvert par des tests de régression.

Il contredit la décision ci-dessus : il offre un chemin de commande par WhatsApp.
**À supprimer ou à restreindre**, avec ses tests. Non fait dans ce lot pour ne pas
mélanger une suppression d'API publique avec un changement d'alerte.

### Bouton « appeler » sur la fiche restaurant

Demandé : un bouton d'appel avec le numéro **du restaurant**, distinct du WhatsApp
du site. Nécessite un champ téléphone par restaurant et son affichage. Pas encore
implémenté.

## 3. Moteur de base — UAT aligné sur MySQL

**Décision :** l'UAT doit tourner sur le moteur de la cible, pour que valider en
UAT préfigure la production.

État constaté : CI sur MariaDB 10.11 (`compose.yaml`), `dev` sur MySQL, UAT sur
SQLite. La roadmap visait déjà « SQLite UAT et MySQL cible »
(`docs/ROADMAP-MARKETPLACE.md`) ; l'alignement lève l'écart.

**Ce n'est pas un interrupteur.** `PLUGINS.md` avertit que
`sqlite-database-integration` fournit le drop-in `db.php` qui fait tourner la base
UAT. Le désactiver sans migrer rend le site indisponible. Séquence exigée :
sauvegarde vérifiée de la base SQLite, export, import MySQL, bascule du drop-in,
contrôle de santé, et seulement ensuite retrait du plugin SQLite.

Opération serveur, hors de portée du dépôt.

## 4. Compte de test UAT

**Décision :** créer un compte porteur d'un rôle caisse, rattaché à un restaurant
publié via `tuc_resto_id`, pour que le parcours `/caisse/` puisse être exercé.

Sans ce rattachement, aucune alerte sonore n'est possible — la règle d'isolation
le refuse, et c'est voulu. Le test métier n'a pas de sens avant.
