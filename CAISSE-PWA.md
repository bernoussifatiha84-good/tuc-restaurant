# PWA CAISSE — module 6, le P0

> **Pourquoi ce module est passé avant le panier** : une commande qui n'arrive
> nulle part n'est pas une commande. Et porter cette arrivée en messages coûte
> 420 000 MAD/mois à 1 000 restos × 20 commandes/jour (`WHATSAPP-COUTS.md` §7).
> Le dashboard ne coûte rien et son coût ne dépend pas du volume de commandes.

---

## 1. Ce qui est en place

| Élément | URL / fichier | État |
|---|---|---|
| Écran caisse | `/caisse/` | ✅ UAT |
| Service worker | `/caisse/sw.js` | ✅ UAT |
| Manifeste PWA | `/caisse/manifest.webmanifest` | ✅ UAT |
| Icônes 192 / 512 | `/caisse/icone-192.png`, `-512` | ✅ |
| Polices | `/caisse/fonts/manrope.woff2`, `plexar-400/700` | ✅ servis par le plugin |
| REST caisse | `/wp-json/tuc/v1/caisse/{moi,commandes,statut,ouverture,push,push-test}` | ✅ |
| Web Push | `TUC_Push` (RFC 8188 + 8291 + VAPID RFC 8292) | ✅ bout en bout |
| Maquette autonome | `assets/caisse/demo-caisse.html` | ✅ un fichier, deux boutons Desktop/Mobile |

**Poids mesuré sur l'UAT** : HTML **5,8 Ko** + **une** police 24,3 Ko =
**30 Ko** au total, `DOMContentLoaded` **516 ms**, `load` **600 ms**.
À comparer aux 523 Ko du site public.

---

## 2. Les routes REST

Toutes exigent `edit_posts` (admin ou gérant). Aucune n'est publique.

| Route | Méthode | Rôle |
|---|---|---|
| `/caisse/moi` | GET | resto rattaché, clé VAPID, nonce REST, nb d'appareils |
| `/caisse/commandes?statut=…` | GET | 40 dernières commandes du resto (`toutes` = pas de filtre) |
| `/caisse/statut` | POST | `{id, statut}` — vérifie que la commande appartient au resto |
| `/caisse/ouverture` | POST | `{ferme: bool}` — pause / reprise du service |
| `/caisse/push` | POST | `{endpoint, p256dh, auth}` — abonnement de l'appareil |
| `/caisse/push` | DELETE | `{endpoint}` — désabonnement |
| `/caisse/push-test` | POST | envoie une notification de test au compte |

### Statuts et transitions

`nouvelle → acceptee → preparation → prete → servie`, plus `refusee` / `annulee`.
Chaque changement est horodaté dans `tuc_historique` avec l'identifiant de
l'utilisateur qui l'a fait.

---

## 3. Le Web Push, sans dépendance

Aucun plugin, aucune bibliothèque, aucun abonnement. Trois couches :

1. **Chiffrement `aes128gcm`** (RFC 8188 + RFC 8291) : ECDH P-256 éphémère,
   HKDF-SHA-256, AES-128-GCM, un seul enregistrement, délimiteur de padding
   `0x02`.
2. **Signature VAPID ES256** (RFC 8292) : JWT `{aud, exp, sub}` signé avec la
   clé privée, conversion **DER → r‖s** parce que JWT n'accepte pas la forme DER
   renvoyée par OpenSSL.
3. **Abonnements** stockés en `user_meta` (`tuc_push_abonnements`, 5 appareils
   max par compte) : pas de table, donc rien à migrer et ça passe tel quel sur
   SQLite. Les endpoints morts (404 / 410) sont purgés automatiquement.

### Validation

- **Vecteur de test officiel du RFC 8291 §5** : corps produit **identique**
  octet pour octet à celui du RFC (« When I grow up, I want to be a
  watermelon », 145 octets).
- **Aller-retour de bout en bout** : charge utile chiffrée côté serveur,
  déchiffrée côté « navigateur » simulé → identique.
- **UAT** : abonnement réel enregistré auprès de `jmt17.google.com`, envoyé,
  reçu, déchiffré par le service worker (message `tuc-rafraichir` reçu par la
  page).

### Clés VAPID

Générées à la **première visite** (`TUC_Push::init()`), pas à l'activation :
l'activation d'un plugin n'est pas rejouée à chaque déploiement. Une clé
régénérée invaliderait silencieusement tous les abonnements existants.

---

## 4. Ce qui a été corrigé en route (et qui comptait)

| Bug | Conséquence réelle | Correctif |
|---|---|---|
| `.liste` sans `min-width:0` | la carte mesurait **548 px** dans un écran de **390** : prix et total hors champ | `min-width:0` sur les conteneurs flex, `nowrap` sur les prix |
| options affichées par leur identifiant | la caisse lisait « +sauce-bbq » au lieu de « Sauce BBQ +4 » | libellé résolu une seule fois, dans `TUC_Commande::creer()`, là où le prix est calculé |
| `Refuser` en croix rouge collée au bouton vert | se tape par erreur en plein service | lien discret + confirmation en deux temps |
| formulaire de connexion sans `method`/`action` | **le mot de passe partait en GET** (URL, historique, journaux) | `POST` vers `wp-login.php` + repli sans JavaScript |
| première connexion refusée | WordPress exige son cookie `wordpress_test_cookie`, absent à la première visite → « les cookies sont bloqués » | `GET` préalable, et message d'erreur repris mot pour mot de WordPress |
| fumigène CI comparant la version du thème | alerte à chaque déploiement, alors que c'est le plugin qui bouge | contrôle sur la version **du plugin** |

---

## 5. Ce qui n'est volontairement pas fait

- **Pas de KDS v1** (écran cuisine séparé) : une seule file suffit pour un
  resto pilote. À rouvrir quand un resto aura deux postes (comptoir + cuisine).
- **Pas d'impression de ticket** : ça suppose une imprimante pilotable, donc un
  agent local — donc un VPS ou une appli. Reporté.
- **Pas de son** : le son d'alerte est une promesse qu'on ne peut pas tenir sur
  iOS (pas de son sur les notifications web). Une pastille, une vibration et un
  écran qui se rafraîchit valent mieux qu'un son qui ne sonnera pas.
- **Pas de rattachement multi-restos** : au lancement, un seul resto publié ⇒
  les admins du site sont la caisse. `tuc_resto_id` en `user_meta` existe déjà
  pour le jour où il y en aura plusieurs.

---

## 6. Ce qu'il reste à faire

1. **Brancher le client** : quand le panier (module ③) postera sur
   `/tuc/v1/commande`, la caisse sera prévenue automatiquement — le hook
   `tuc_commande_creee` est déjà en place.
2. **Page `/suivi/` côté client** : même mécanique de push, pour « c'est
   confirmé / c'est prêt » côté client. C'est ce qui remplace le message
   WhatsApp sortant.
3. **Vraie clé VAPID par environnement** : aujourd'hui les clés sont générées
   à la volée dans les options du site. Pour la prod, les poser explicitement
   (`wp option update tuc_vapid_priv …`) afin qu'une restauration de base ne
   les perde pas.
4. **HTTPS et icône** : déjà en place sur l'UAT. Sur `tuc.ma`, rien à changer.
5. **Tester sur un vrai téléphone** : iOS exige « Partager → Sur l'écran
   d'accueil » avant d'autoriser le Web Push. À écrire dans la fiche
   d'onboarding du restaurant (3 lignes, pas un manuel).
