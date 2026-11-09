# Inscription 100 % wa.me — conception et limites

> 4 novembre 2026 · déployé sur l'UAT et testé de bout en bout.

---

## 1. Ton flux, tel que je l'ai compris

```
COMMANDE À TABLE
  client clique « Commander »
        ↓
  il écrit sur le WhatsApp DU SITE (pas du restaurant)   ← message pré-rempli
        ↓
  n8n reçoit → crée le compte avec SON numéro
        ↓
  il reçoit : identifiant = son numéro + lien magique
        ↓
  il peut changer son mot de passe ensuite
        ↓
  sa commande part au WhatsApp DU RESTAURANT

MENU SANS COMMANDE
  bouton « Créer mon profil » → même flux, mais pas de commande
```

**Aucune saisie. Ni nom, ni e-mail. Un clic, et c'est fait.**

---

## 2. Ce qui est construit et vérifié

| Étape | Route | Résultat mesuré sur l'UAT |
|---|---|---|
| ① le client clique | `POST /tuc/v1/wa-lien` | `wa.me/2126…?text=TUC — JE VEUX CRÉER MON COMPTE ref:QCL3HAFW` |
| ② n8n crée le compte | `POST /tuc/v1/wa-hook` | `compte créé: True · login: tuc_212612345678` |
| ③ le site récupère le lien | `GET /tuc/v1/wa-statut` | `pret: True` + lien magique |
| ④ lien magique | `POST /tuc/v1/magie` | usage **unique**, expire en **15 min** |
| ⑤ changer le mot de passe | `POST /tuc/v1/motdepasse` | 8 caractères minimum |

**Sécurité mesurée :**

| Tentative | Résultat |
|---|---|
| hook sans secret | **401** |
| hook avec un mauvais secret | **401** |
| même code rejoué une 2ᵉ fois | **410** |
| même numéro deux fois | le compte existant est réutilisé, pas dupliqué |

---

## 3. 🔴 Le point d'architecture qui casse — et la correction

### Ce qui ne peut pas marcher

Un compte ne peut pas être créé « pour » un numéro qu'on ne contrôle pas.
Sinon n'importe qui s'inscrit avec le numéro de quelqu'un d'autre.

**Donc l'identité, c'est le numéro qui a ENVOYÉ le message.** C'est pour ça que :

```
le navigateur génère un CODE          (ref:QCL3HAFW)
        ↓ il l'envoie dans le message pré-rempli
n8n reçoit le message ET le numéro de l'expéditeur
        ↓
il appelle le hook avec {code, tel_de_l_expediteur}
        ↓
le compte est lié à ce numéro-là — pas à un numéro saisi
```

C'est la seule construction qui prouve que le client possède le numéro.

### 🔴 Le second problème, plus grave : le numéro du site

> *« il envoie la commande au WhatsApp du site, pas du restaurant »*

**Un seul WhatsApp ne peut pas servir 500 restaurants.** Le client A et le client B
écrivent au même numéro. La conversation mélange 500 files d'attente. Il faut
lire chaque message pour savoir à quel restaurant il appartient — et n8n ne peut
pas répondre à une caisse au nom d'une autre.

**Et la commande doit sonner CHEZ LE RESTAURANT**, sinon il ne la voit pas.

### La correction : le WhatsApp du site devient un **portail**, pas une boîte

```
   client ──wa.me──► WhatsApp DU SITE ──► n8n ──► crée le compte
                                                └─► RELAIE la commande
                                                    au WhatsApp DU RESTO
```

- le numéro du site **identifie** le client et **route** la commande ;
- le restaurant reçoit la commande sur **son** numéro, comme prévu au départ ;
- le numéro du site ne devient jamais une boîte de réception de commandes.

Dès la 2ᵉ commande, le client peut écrire **directement au restaurant** :
son compte est connu, sa fiche est pré-remplie.

**Alternative à trancher** : si tu veux vraiment zéro relais, alors chaque
restaurant affiche **son propre QR wa.me** sur sa page, et le numéro du site ne
sert qu'à l'inscription. C'est plus simple, mais on perd la trace TUC.

---

## 4. Pourquoi pas la Cloud API WhatsApp : la contrainte des 24 h

C'est le point technique qui décide de tout le projet :

> **Hors Cloud API**, un message que TU envoies (comme les identifiants) n'est
> possible que dans les **24 heures** qui suivent le dernier message du client.
> Au-delà, il faut un **template pré-approuvé par Meta**.

Conséquence concrète :

| Situation | Possible ? |
|---|---|
| Le client écrit, on répond dans la minute | ✅ oui |
| Le client s'inscrit, on envoie ses identifiants | ✅ oui (il vient d'écrire) |
| Le resto veut prévenir « votre commande est prête » 3 h après | ⚠️ template nécessaire |
| Le client n'a rien envoyé depuis 2 jours | ❌ template meta obligatoire |

**Ce que ça change pour nous :** le flux d'inscription fonctionne **parfaitement**
(il est déclenché par un message du client). Le flux de notification de commande
doit envoyer le message **dans la foulée** de la commande, ce qui est le cas.

**Reste un risque** : `wa.me` n'a **pas de webhook**. Celui qui reçoit le message,
c'est un humain avec un téléphone. Pour automatiser, il faut soit :
- **un opérateur** qui clique « transférer le message à n8n » (manuel, mais gratuit et 100 % conforme) ;
- soit **WhatsApp Business + API** (payant, templates, numéro dédié).

⚠️ **Je ne recommande pas** les passerelles non officielles (Baileys, WAHA, Evolution) :
elles violent les CGU WhatsApp et le numéro finit **banni**. On perdrait tous les comptes clients.

---

## 5. Ce qui manque avant que ce soit utilisable

| # | Manque | Pourquoi c'est bloquant |
|---|---|---|
| 1 | **Page `/connexion/`** qui consomme `?tuc_magie=` | le lien existe, la page n'existe pas encore |
| 2 | **Le numéro WhatsApp réel du site** (aujourd'hui : `212600000000`, un placeholder) | évidemment |
| 3 | **L'instance n8n** — elle exige un VPS, GitHub ne fait pas tourner n8n | décision VPS reportée |
| 4 | Le **relais vers le WhatsApp du resto** | sinon le resto ne reçoit rien |
| 5 | La **mémoire de commande** : le client ne peut pas écrire en 1 clic | le cœur du « zéro mot » |
| 6 | Le **cookie signé** du panier | le rate-limit actuel vise un téléphone optionnel |
| 7 | La **page `/creer-mon-compte/`** (menu sans commande) | un bouton, rien de plus |

### Le point 5 mérite une décision

Un message pré-rempli est un **texte figé** écrit à l'avance. Il ne peut pas
contenir un panier de 3 plats, sauf si le client clique un lien qui **pré-remplit
le message avec son panier encodé**.

Deux stratégies :

| Stratégie | Message pré-rempli | Automatisation |
|---|---|---|
| **A. Tout dans le texte** | `TUC kfc table 12: 1×6en1 Box, 2×Frites — 158 MAD` | ✅ n8n parse le texte, zéro état côté serveur |
| **B. Tout dans un lien** | `TUC commande #a7f3` + le serveur a déjà le panier | ✅ plus propre, mais le panier doit exister côté serveur avant |

**Je recommande B** : le panier est déjà en base (module 3), le message ne porte
qu'une référence courte. Le client n'écrit toujours rien, et un panier de 15 plats
ne produit pas un message illisible.

---

## 6. Ce que ça change au plan

| Lot | Contenu | Impact de cette décision |
|---|---|---|
| A | Groupes d'options | inchangé |
| B | Panier + 1 resto + minimum | **+ cookie signé**, + référence courte |
| **C** | Timeout + notifications | **+ compte wa.me + relais vers le resto** |
| D | nouveau | **page `/connexion/` + `/creer-mon-compte/`** |

Le compte wa.me n'est pas un module séparé : c'est **la porte d'entrée du lot C**.
Créer le compte sans le relais de commande ne sert à rien ; relayer sans compte
ne permet pas de retrouver le client.

---

## 7. Réponse à ta question : « juste cliquer sur un bouton, sans écrire un seul mot »

**Oui, c'est faisable — avec une nuance à connaître.**

Le premier clic **ouvre WhatsApp** avec le message pré-rempli. Le client doit
appuyer sur **Envoyer** (une seconde). On ne peut pas l'éviter : `wa.me` est un
lien, pas une API. Prétendre le contraire serait mentir.

Donc le parcours réel est :

```
1 clic « Créer mon compte »  →  WhatsApp s'ouvre, message déjà écrit
1 appui « Envoyer »          →  c'est fait, on renvoie les identifiants
```

**Deux gestes, zéro mot écrit.** C'est le maximum possible sans API payante.
