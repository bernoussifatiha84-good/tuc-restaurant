# WhatsApp : ce qui est vrai, ce qui ne l'est pas

> 4 novembre 2026 · réponses aux cinq objections, avec sources.
> **Une de mes affirmations précédentes était fausse. Je la corrige ici.**

---

## 1. « Comment les vidéos n8n répondent-elles aux messages sans API ? »

**Elles utilisent l'API.** Les vidéos ne le disent pas parce que c'est le titre
qui vend, pas l'architecture.

| Ce que montre la vidéo | Ce qu'il y a vraiment dessous |
|---|---|
| « n8n répond automatiquement » | **WhatsApp Cloud API** (Meta a un webhook) |
| « 100 % gratuit » | gratuit **jusqu'à 1 000 messages service/mois** |
| « sans code » | sans code **de programmation**, mais il faut un compte Meta Business, un numéro dédié et une vérification d'entreprise |

Toute automatisation qui **reçoit** un message a besoin d'un webhook. `wa.me` n'en a pas
— c'est une simple page web avec un texte pré-rempli. Donc :

- **si un logiciel reçoit le message** → c'est une API (Cloud API officielle, ou une passerelle non officielle) ;
- **si c'est un humain qui reçoit** → c'est `wa.me`, et l'automatisation commence seulement quand il clique « transférer à n8n ».

Les deux fonctionnent. Elles ne coûtent pas la même chose.

---

## 2. 🔴 Je me suis trompé sur les 24 h — voici la vérité 2026

J'ai écrit « tu ne peux écrire qu'à quelqu'un qui t'a écrit dans les 24 h ». **C'est périmé.**

Depuis le **1er octobre 2026** (source Meta, relayée par plusieurs BSP) :

| Type de message | Coût |
|---|---|
| Message **entrant** du client | **gratuit** |
| Réponse libre dans la fenêtre 24 h | **1 000/mois gratuites par numéro**, ensuite tarif *utility* |
| Template *utility* (confirmation, statut) dans la fenêtre | **facturé** depuis le 1er octobre |
| Template *marketing* | facturé, le plus cher |
| Template *authentication* (OTP) | facturé, moins cher |

**Ce que ça change pour TUC, concrètement :**

- le client écrit → on répond : **gratuit** dans le quota de 1 000/mois ;
- à 5 restaurants × 30 commandes/jour = **4 500 messages/mois** → on dépasse le quota ;
- au-delà, chaque message coûte le tarif *utility* du Maroc (quelques centimes).

**Conclusion honnête** : `wa.me` reste **gratuit à vie**. La Cloud API devient payante
au-delà de 1 000 messages/mois. Je ne peux pas te promettre le contraire.

---

## 3. « Le numéro non contrôlé / le code à usage unique » — expliqué simplement

### Le problème

Si le site pouvait faire « crée un compte pour 0612345678 », alors **n'importe qui**
créerait un compte avec le numéro de quelqu'un d'autre. Et ce quelqu'un recevrait
les commandes d'un autre.

### La solution, en 4 temps

```
1. Le client clique « Commander » sur tuc.ma/kfc
2. Le site génère un code :  A7F3
3. WhatsApp s'ouvre avec :  « TUC · A7F3 — Table 12 — 1× 6 en 1 Box — 109 MAD »
4. Le client appuie sur ENVOYER
```

**C'est le message qui prouve l'identité**, pas la saisie. Parce que :

- le message part **du téléphone du client** ;
- n8n reçoit le message **et le numéro de l'expéditeur**, que WhatsApp certifie ;
- n8n lit le code `A7F3` et sait **de quel restaurant, de quelle table, de quel panier** il s'agit.

**Le code est la clé. Le numéro de l'expéditeur est la preuve.**

Si quelqu'un envoie `A7F3` depuis un autre téléphone, il crée **son** compte avec
**son** numéro. Il ne peut pas usurper celui du voisin. Et le code expire en 30 minutes.

---

## 4. « wa.me n'a pas de webhook » — expliqué

Un **webhook**, c'est une adresse que WhatsApp appelle automatiquement quand un
message arrive. C'est ce qui permet à n8n de « voir » le message sans humain.

`wa.me` est juste un **lien** : `https://wa.me/2126…?text=bonjour`. Il ouvre WhatsApp
avec le texte prêt. Il n'y a **personne derrière pour prévenir n8n**.

Deux façons de contourner :

| Méthode | Qui reçoit | Automatisation | Coût | Conforme CGU |
|---|---|---|---|---|
| `wa.me` + un opérateur | un humain (téléphone ou WhatsApp Web) | l'opérateur clique « transférer à n8n » | **0 €** | ✅ |
| WhatsApp Cloud API | un serveur | webhook automatique | **0 € jusqu'à 1 000/mois**, puis payant | ✅ |
| Baileys / WAHA / Evolution | un serveur | webhook automatique | 0 € | ❌ **non** |

**Nous, on est sur `wa.me`.** Donc l'automatisation commence au moment où quelqu'un
nous transmet le message. C'est le prix de la gratuité.

---

## 5. Pourquoi je ne recommande pas Baileys / WAHA / Evolution

Ce ne sont pas des avis, ce sont des faits vérifiables :

| Risque | Détail |
|---|---|
| **Ban du numéro** | ces outils se connectent en se faisant passer pour WhatsApp Web. Détection = **bannissement définitif du numéro** |
| **Perte de tous les comptes** | tes clients sont liés à ce numéro. Numéro banni = **plus aucun accès**, sans recours |
| **Casse à chaque mise à jour** | WhatsApp change son protocole régulièrement ; les passerelles tombent et doivent être réparées |
| **Contraire aux CGU** | utilisable en test, **pas pour un service qui prend de l'argent** |
| **Sécurité** | tu stockes des sessions WhatsApp complètes sur ton serveur |

### Ce que je propose à la place — dans cet ordre

| Étape | Solution | Coût | Quand |
|---|---|---|---|
| **1. Maintenant** | **`wa.me` + dashboard TUC comme canal principal** | **0 €** | tout de suite |
| 2. À 500 commandes/mois | **WhatsApp Cloud API** (1 000 msg gratuits) | 0 € tant qu'on reste sous le quota | quand le volume justifie |
| 3. À 5 000 commandes/mois | Cloud API + templates *utility* | quelques centaines de MAD/mois | quand le CA le permet |

**Et surtout : ton idée du dashboard change tout** (voir §6). Le WhatsApp devient
secondaire — il ne sert plus qu'à l'inscription et à la confirmation.

---

## 6. ✅ Ton dashboard remplace le relais — j'adopte

Tu as raison, et ça règle le problème d'architecture que j'avais soulevé :

> *« pas besoin de relais : la commande part au **dashboard** du restaurant avec
> push notification. Une commande qu'il peut confirmer, modifier ou annuler. »*

**C'est meilleur que le relais WhatsApp**, pour quatre raisons :

1. un seul numéro WhatsApp (celui de TUC) suffit — **plus de mélange de 500 files** ;
2. la caisse voit **toutes** ses commandes dans un vrai écran, pas dans un fil de discussion ;
3. `confirmer / modifier / annuler` sont des **boutons**, pas des messages libres à parser ;
4. l'historique, le total du jour et les statistiques deviennent possibles.

WhatsApp devient alors **la porte d'entrée du client** uniquement — pas le canal du resto.

### Ce que ça change dans le code

| Avant | Après |
|---|---|
| `POST /commande` → `do_action('tuc_commande_creee')` → *relais WhatsApp* | → **Web Push vers le dashboard** |
| statuts par messages | **`POST /commande/{id}/statut`** — déjà en place, transitions contrôlées |
| pas d'écran caisse | **PWA `/caisse/`** à construire (module 6) |

Le module 6 (PWA caisse + Web Push) **devient le module le plus important**.
C'est lui qui remplace le relais, pas un plugin.

---

## 7. ✅ Le panier en texte dans le message — fait et vérifié

Message réel généré par l'UAT :

```
TUC · 5BB71
kfc · Table 12

1× 6 en 1 Box — 79 MAD
2× Frites — 30 MAD
   + Sauce BBQ +4

Sous-total : 109 MAD
Frais : Gratuit
TOTAL : 109 MAD

Je confirme ma commande.
```

- **le panier est chiffré ligne par ligne** : le client valide en lisant ;
- **la référence `5BB71`** en tête permet à n8n de router en un coup d'œil ;
- le total, les frais et les options sont explicites ;
- le client n'écrit **rien** — il appuie sur Envoyer.

---

## 8. ✅ QR par restaurant, par table, et par mode — fait et vérifié

`GET /tuc/v1/qr/kfc` sur 8 tables → **10 QR générés** :

| Type | URL encodée | Usage |
|---|---|---|
| table 1…8 | `tuc.ma/kfc/?t=1` … `?t=8` | **commande à table** |
| emporter | `tuc.ma/kfc/?mode=emporter` | **click & collect** |
| livraison | `tuc.ma/kfc/?mode=livraison` | **le resto livre** |

Chaque restaurant a donc **son propre jeu de QR**, et peut en créer autant qu'il a
de tables (`tuc_tables`). Le restaurant imprime lui-même — TUC ne vend ni QR ni chevalet.

---

## 9. Ce qui reste à construire

| # | Manque | Priorité |
|---|---|---|
| 1 | **PWA caisse `/caisse/`** + Web Push (remplace le relais) | 🔴 **P0** |
| 2 | **Page `/connexion/`** qui consomme le lien magique | 🔴 P0 |
| 3 | Le **numéro WhatsApp réel** du site (aujourd'hui un placeholder) | 🔴 P0 |
| 4 | **n8n** sur un VPS — GitHub ne fait pas tourner n8n | 🟠 |
| 5 | Groupes d'options min/max (lot A de l'audit) | 🟠 |
| 6 | Panier persistant 1 seul resto (lot B) | 🟠 |

**L'ordre a changé** : la PWA caisse passe avant le panier. Sans elle, une commande
n'arrive nulle part.
