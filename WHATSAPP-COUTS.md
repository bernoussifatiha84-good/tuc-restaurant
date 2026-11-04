# WhatsApp : qui coûte quoi

> 4 novembre 2026 · chiffres Meta pour le **Maroc**, arrêtés au 1er octobre 2026.
> Taux utilisé : 1 USD ≈ 9,3 MAD.

---

## 1. D'abord, la confusion à lever : **c'est la même chose**

Tu demandes *« tu parles de WhatsApp API ou Cloud API ? »*

| Nom que tu entends | Ce que c'est |
|---|---|
| **WhatsApp Business API** | le produit de Meta |
| **WhatsApp Cloud API** | **la même chose**, dans sa version hébergée par Meta |
| **On-Premises API** | l'ancienne version auto-hébergée — **Meta l'abandonne** |

Depuis 2023, il n'y a plus qu'**un seul chemin officiel** : la Cloud API.
Donc quand je dis « API WhatsApp », je parle de la **Cloud API**. Pas deux produits.

**Trois façons d'y accéder :**

| Voie | Qui ajoute quoi | Abonnement |
|---|---|---|
| **En direct chez Meta** | rien, c'est toi qui codes | **0 MAD** |
| **Via un BSP** (Wasel, EnvoiSMS, Wati…) | interface, boîte de réception, constructeur de bots | **200 à 1 500 MAD/mois** |

---

## 2. Ce qui coûte « des centaines de dirhams » : **le message**, pas l'API

### Le tarif officiel Meta pour un destinataire au Maroc

| Catégorie | Prix par message |
|---|---|
| Marketing | 0,0414 $ |
| **Utility** (confirmation, statut) | **0,0230 $** |
| **Service** (réponse dans la fenêtre 24 h) | **0,0230 $** |
| Authentication (OTP) | 0,0230 $ |

Soit **≈ 0,21 MAD par message**.

**Et la franchise :** chaque numéro a **1 000 messages *service* gratuits par mois**.
Compteur remis à zéro le 1er de chaque mois, non reportable.
Les messages **entrants** du client sont **toujours gratuits**.

### Ce que ça donne en dirhams

| Messages / mois | dont facturés | Coût Meta |
|---|---|---|
| 1 000 | 0 | **0 MAD** |
| 2 000 | 1 000 | 214 MAD |
| 5 000 | 4 000 | **856 MAD** |
| 10 000 | 9 000 | 1 925 MAD |
| 50 000 | 49 000 | 10 481 MAD |

**La franchise de 1 000 ne se cumule pas** entre plusieurs numéros : c'est par numéro,
et on n'en a qu'un.

---

## 3. Ce que ça veut dire pour TUC, scénario par scénario

Prenons une commande = **3 messages** (accusé de réception, « en préparation »,
« prête »).

| Scénario | Messages/mois | Coût Meta | Verdict |
|---|---|---|---|
| 5 restos × 5 commandes/jour | 3 375 | **≈ 500 MAD** | déjà cher pour un lancement gratuit |
| 10 restos × 10 commandes/jour | 9 000 | **≈ 1 700 MAD** | non |
| 50 restos × 20 commandes/jour | 90 000 | **≈ 18 700 MAD** | impossible |

**Conclusion : WhatsApp ne peut PAS porter les notifications de commande à l'échelle.**
Ce n'est pas une question de choix technique, c'est une question de coût linéaire
avec le volume — l'inverse de ce qu'on veut pour un modèle gratuit.

---

## 4. ✅ Et c'est exactement pour ça que ton idée du dashboard tombe juste

| Canal de notification | Coût à 90 000 commandes/mois |
|---|---|
| **Web Push (dashboard caisse)** | **0 MAD** — c'est ton propre serveur |
| WhatsApp Cloud API | ≈ 18 700 MAD |

Le Web Push vers la PWA de caisse **n'a aucun coût variable**. Il n'y a pas de
facturation par notification. C'est la raison technique, pas juste une préférence.

**Donc l'architecture finale est :**

| Étape | Canal | Coût |
|---|---|---|
| Le client passe commande | message WhatsApp pré-rempli, qu'il envoie | **0 MAD** |
| n8n reçoit et route | webhook (opérateur ou Cloud API) | 0 à ~0,21 MAD/message |
| La caisse est prévenue | **Web Push dans le dashboard** | **0 MAD** |
| La caisse confirme / modifie / annule | boutons dans le dashboard | **0 MAD** |
| Le client est prévenu « c'est prêt » | **notification sur sa page de suivi** | **0 MAD** |
| Le client est prévenu (WhatsApp, optionnel) | Cloud API | ~0,21 MAD |

**Avec cette architecture, le budget WhatsApp reste sous les 1 000 gratuits**
tant qu'on reste sous ~330 commandes/mois. Au-delà, on passe le client en
notification web et WhatsApp devient un extra, pas une dépendance.

---

## 5. La nuance que je dois te dire clairement

Les **1 000 gratuits sont des messages *service*** — c'est-à-dire des réponses à
un message que **le client t'a envoyé**. Or :

- à table, le client écrit → on répond : **gratuit** ✅
- une notification que **TU** déclenches (« votre commande est prête ») n'est
  gratuite que si elle tombe **dans les 24 h** du dernier message du client ;
  au-delà elle exige un **template *utility***, facturé **dès le premier**.

Donc un restaurant qui prévient un client 2 jours après son passage :
**payant**. D'où l'intérêt du dashboard côté client aussi.

---

## 6. Décision recommandée

| Maintenant | Plus tard (si volume) |
|---|---|
| **`wa.me` + dashboard PWA** | Cloud API **en direct chez Meta** |
| 0 MAD, 0 abonnement, 0 BSP | 0 MAD d'abonnement, ~0,21 MAD/message |
| automatisation via n8n | webhook automatique |

**Ne pas prendre de BSP tout de suite** : 200 à 1 500 MAD/mois d'abonnement pour
un service qui n'a pas encore un seul client payant, c'est non.

**Et ne jamais** utiliser Baileys/WAHA/Evolution pour économiser 0,21 MAD :
le numéro serait banni et tu perdrais tous les comptes clients.
