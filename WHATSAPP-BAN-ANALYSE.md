# Baileys / WAHA / Evolution : ce que WhatsApp voit vraiment

> 5 novembre 2026 · réponse à ta question, avec sources.
> **Tu as partiellement raison, et j'ai overstatementé.** Correction ci-dessous.

---

## 1. Ce que j'ai dit de travers

J'ai écrit : *« détection = bannissement définitif du numéro »*, en laissant entendre que
la détection était purement technique et ignorait le comportement.

**C'est trop absolu.** La réalité est en deux couches, et le comportement pèse lourd.

---

## 2. Comment WhatsApp détecte — les deux couches

### Couche 1 — l'empreinte du protocole *(indépendante du comportement)*

Baileys **ne pilote pas un navigateur** : il parle directement le protocole binaire
de WhatsApp par WebSocket. Résultat :

| Ce qui diffère d'un vrai WhatsApp Web | Conséquence |
|---|---|
| la séquence de négociation du chiffrement | reconnaissable |
| l'ordre des en-têtes du handshake | reconnaissable |
| le rythme d'établissement de session | reconnaissable |
| la version du client annoncée | **ne correspond à aucun build WhatsApp réel** |

Meta maintient une base d'empreintes de clients non officiels. **Cette couche se
déclenche avant même le premier message.** C'est ce qui fait dire aux comparatifs
que « Baileys dure 2 à 8 semaines ».

⚠️ Nuance importante : `whatsapp-web.js` pilote **un vrai Chromium headless**. Son
empreinte est donc plus proche d'un client réel. **Baileys, non** — c'est du
protocole nu, et c'est précisément ce que la couche 1 cherche.

### Couche 2 — le comportement *(celle que tu décris)*

| Signal | Ce qui déclenche |
|---|---|
| **Premier contact vers des inconnus** | *« le signal n°1, de loin »* |
| **Ratio de réponses entrantes faible** | une paroi d'envois sans réponses lisibles = spam |
| **Blocages et signalements** | **le signal le plus décisif en pratique** |
| Rythme robotique | intervalles fixes à la seconde |
| Appareil lié qui ne dort jamais | session 24/7 sans usage humain |
| Reconnexions fréquentes | chaque re-pairing laisse une empreinte |

---

## 3. Donc : outil ou comportement ?

**Les deux. Mais tu as raison sur la pondération.**

| Affirmation | Vérité |
|---|---|
| « on est bannis parce qu'on harcèle » | **Vrai dans la majorité des cas pratiques** — premier contact + signalements |
| « on est bannis juste parce qu'on utilise Baileys » | **Faux en théorie** (des comptes tiennent des mois), **vrai en tendance** (2–8 semaines) |

Une formulation honnête : **la couche 1 te met sur la liste ; la couche 2 décide si on
te retire.** Ton usage — répondre uniquement à des gens qui t'ont écrit — **évite les
signaux les plus lourds**. C'est exact, et je l'admets.

---

## 4. Sauf que pour TUC, l'argument décisif est un autre

### 🔑 « On n'envoie jamais en premier » — c'est justement ce qui rend la Cloud API **gratuite** pour nous

C'est le point que tu as toi-même soulevé, et il se retourne :

> La franchise de **1 000 messages/mois** de la Cloud API concerne les messages
> **service** — c'est-à-dire **les réponses à un message que le client t'a envoyé**.

**Ton modèle est le modèle exact qui entre dans la gratuité.**

| | Baileys | Cloud API |
|---|---|---|
| Coût à 330 commandes/mois (réponses uniquement) | **0 MAD** | **0 MAD** |
| Conformité | ❌ | ✅ |
| Risque de perdre le numéro | réel | **aucun** |

**On ne paie rien dans les deux cas. Donc pourquoi prendre le risque ?**

### Et à plus gros volume, ça reste tenable

| Commandes/mois | Messages (~3/commande) | Coût Cloud API |
|---|---|---|
| 330 | 1 000 | **0 MAD** |
| 1 000 | 3 000 | 428 MAD |
| 3 000 | 9 000 | 1 700 MAD |

À 1 000 commandes/mois, on parle de **428 MAD** — à comparer aux **299 + 499 MAD/mois
par restaurant** des packs. C'est du bruit comptable.

### La vraie asymétrie du risque

| Si le numéro tombe | Conséquence |
|---|---|
| Tous les comptes clients liés à ce numéro | **perdus** |
| Les QR déjà imprimés par les restaurants | **pointent vers un numéro mort** |
| Le flux d'inscription | **mort** |
| L'appel | possible mais *« réussit rarement pour les comptes pris avec des clients non officiels »* |

**Le coût d'une chute est total ; le coût de la Cloud API est nul.** Le rapport
risque/bénéfice est absurde.

---

## 5. Si tu veux quand même tester Baileys — la seule façon acceptable

Je ne vais pas te dire « interdit ». Mais si tu le fais :

```
✅ un numéro DÉDIÉ, jamais le numéro de production
✅ ce numéro n'est jamais imprimé sur un QR
✅ les comptes clients ne sont JAMAIS liés à ce numéro
✅ volume bas, réponses uniquement, jamais de premier contact
✅ un plan de bascule : si ban → Cloud API en 48 h, sans perte
❌ jamais le numéro qui figure sur les QR des restaurants
```

C'est la règle du **fusible** : on teste avec un numéro qu'on peut perdre,
la production reste sur le canal sûr.

---

## 6. Décision recommandée, sans détour

| Étape | Canal | Coût | Risque |
|---|---|---|---|
| **Lancement (0–300 commandes/mois)** | `wa.me` + dashboard PWA | **0 MAD** | **aucun** |
| **Montée (300–1 000)** | Cloud API **en direct chez Meta** | 0 → 428 MAD | aucun |
| **Échelle (1 000+)** | Cloud API + templates | 428 → 1 700 MAD | aucun |
| Test technique isolé | Baileys sur numéro jetable | 0 MAD | contenu au fusible |

**Il n'y a aucune étape où Baileys est nécessaire.** Pas parce que c'est immoral,
parce que **ça n'économise rien** dans notre configuration.

---

## 7. Ce que l'audit change dans le plan

| Avant | Après |
|---|---|
| « Baileys = ban, point final » | **trop absolu** — le comportement pèse lourd |
| « Cloud API = payant » | **faux à notre volume** : réponses seules = gratuit jusqu'à 1 000/mois |
| Priorité | **la PWA caisse d'abord** : le Web Push ne coûte rien et ne dépend de personne |
