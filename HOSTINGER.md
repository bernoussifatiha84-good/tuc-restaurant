# ✅ DÉPLOYÉ — https://chocolate-gazelle-457691.hostingersite.com

## État réel au 4 novembre 2026

| Élément | Valeur |
|---|---|
| Site provisoire | `chocolate-gazelle-457691.hostingersite.com` |
| Thème actif | **TUC Market 0.1.0** |
| WordPress | 7.1.4 |
| CI/CD | **vert** — run `41910279842` |
| `/wp-json/tuc/v1/sante` | `{"ok":true,"theme":"0.1.0","restos":25}` |
| Poids de la page | **72 Ko** HTML · 1,5 Mo de thème |
| Temps de réponse | **0,26 s** |

### Secrets configurés dans l'environnement `dev`

| Nom | Valeur |
|---|---|
| `SSH_HOST` | `145.223.38.175` |
| `SSH_USER` | `u987613820` |
| `SSH_PRIVATE_KEY` | clé ed25519 `arena-agent@tuc.ma` ✅ autorisée sur hPanel |
| `WP_ROOT` | `domains/chocolate-gazelle-457691.hostingersite.com/public_html` |
| `SITE_URL` | `https://chocolate-gazelle-457691.hostingersite.com` |
| `SSH_PORT` *(variable)* | `65002` |

### Pour passer en production

1. Créer `tuc.ma` dans hPanel (ou pointer le domaine vers le compte)
2. Installer WordPress dessus
3. Remplir `WP_ROOT` + `SITE_URL` (+ recoller `SSH_PRIVATE_KEY`) dans l'environnement **`prd`**
4. `git checkout -b release-0.2.0 && git push origin release-0.2.0`

---

# Guide de référence (SSH)

