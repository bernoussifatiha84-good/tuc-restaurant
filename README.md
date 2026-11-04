# tuc-restaurant

Marketplace de restaurants **TUC** — Casablanca.
Livraison (le resto livre) · À emporter · Commande à table par QR.

Le repo contient **deux choses** :

```
wordpress/tuc-theme/      thème du site public (WordPress + Hostinger)
.github/workflows/        CI/CD : pousse sur develop → déploie en staging
```

---

## Déploiement

| Branche | Environnement | URL |
|---|---|---|
| `develop` | staging (`dev`) | site de test |
| `release-x.y.z` | production (`prd`) | `tuc.ma` |

**Convention de version** : `<version_theme>.<version_plugin>` → `0.1.0`.

Le workflow fait, dans l'ordre :
1. `php -l` sur tous les fichiers PHP
2. contrôle d'équilibre des accolades CSS
3. `node --check` sur tous les JS
4. `rsync` du thème (et du plugin s'il existe) vers Hostinger, port **65002**
5. `wp cache flush` + `wp rewrite flush`
6. appel de `/wp-json/tuc/v1/sante` — **si ça ne répond pas `{"ok":true}`, le déploiement échoue**

---

## Secrets GitHub à créer

`Settings → Secrets and variables → Actions` — **deux environnements** : `dev` et `prd`.

| Secret | Exemple | Notes |
|---|---|---|
| `SSH_HOST` | `srv123.hostinger.com` | l'hôte SSH, pas le domaine |
| `SSH_USER` | `u123456789` | fourni par hPanel |
| `SSH_PORT` | `65002` | port SSH Hostinger mutualisé |
| `SSH_PRIVATE_KEY` | `-----BEGIN OPENSSH…` | clé **privée**, jamais commitée |
| `WP_ROOT` | `domains/tuc.ma/public_html` | chemin absolu ou relatif à `~/` |
| `SITE_URL` | `https://dev.tuc.ma` | sert au health check |

### Créer la paire de clés

```bash
ssh-keygen -t ed25519 -C "github-actions@tuc" -f tuc_deploy -N ""
# clé PUBLIQUE -> hPanel > Avancé > Accès SSH > Gérer les clés
cat tuc_deploy.pub
# clé PRIVÉE -> secret GitHub SSH_PRIVATE_KEY
cat tuc_deploy
```

⚠️ La clé publique doit être **autorisée côté hPanel**. Sans ça : `Permission denied (publickey)`.

---

## Installation locale du thème

```bash
cd wordpress
zip -r tuc-theme.zip tuc-theme
# Apparence > Thèmes > Ajouter > Téléverser
```

Puis `Réglages > Lecture > Page d'accueil` = une page statique vide : `front-page.php` prend le relais.

---

## Architecture

- `front-page.php` rend le HTML. **Aucune logique métier.**
- `inc/data.php` contient les données de démo (28 restos, 8 promos). ⚠️ **temporaire** : en production ces données viennent du CPT `tuc_resto`, rempli par l'OCR de la photo du menu.
- `inc/rest.php` expose `/wp-json/tuc/v1/{restos,promos,sante}` en lecture seule.
- `assets/css/tuc.css` + `assets/js/tuc.js` : 12 Ko au total, zéro dépendance.
- Polices **auto-hébergées** (`assets/fonts/`) : Manrope + IBM Plex Sans Arabic, aucune requête vers Google.

---

## Ce qui n'est pas dans ce repo

- ❌ Paiement en ligne — le client paie en cash au serveur.
- ❌ Flotte de livraison — le restaurant livre, TUC ne livre pas.
- ❌ Exclusivité — un restaurant peut être sur Glovo en même temps.
