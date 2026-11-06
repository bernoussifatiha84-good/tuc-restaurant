# Espace Pro et panier TUC — MVP de la branche PR

**État :** code dans la branche `arena/87e5dbce-tuc-restaurant`; non déployé en UAT ou production. Aucun compte métier n’a été affecté automatiquement. Ce document ne constitue pas une validation de mise en service.

## Interfaces

- Dans l’administration WordPress, **Restaurants → Équipe TUC** permet à un administrateur d’affecter un compte existant à un restaurant publié et à un rôle TUC. Le rôle `Serveur` reste sans accès métier activé.
- `/espace-pro/` est une interface mobile de gestion du menu. Les rôles restaurateur/gestionnaire doivent être explicitement rattachés à un restaurant. Les rôles caissier/serveur n’y ont pas accès.
- La fiche publique d’un restaurant affiche le menu, propose la personnalisation des options, un panier local et la confirmation d’une commande cash.

## API du menu

Les routes `/wp-json/tuc/v1/pro/...` exigent une session WordPress et un nonce REST. La capacité `tuc_manage_menu` ainsi que le `tuc_resto_id` explicite sont vérifiés côté serveur. L’identifiant de restaurant n’est jamais pris dans le corps de requête pour décider du tenant.

| Méthode | Route | Effet |
| --- | --- | --- |
| GET | `/tuc/v1/pro/restaurant` | Restaurant affecté et paramètres nécessaires au menu |
| GET | `/tuc/v1/pro/menu` | Plats publiés et brouillons du seul restaurant courant |
| POST | `/tuc/v1/pro/menu` | Crée un brouillon par défaut; publication explicite possible |
| PUT/PATCH | `/tuc/v1/pro/menu/{id}` | Met à jour un plat appartenant au restaurant courant |
| DELETE | `/tuc/v1/pro/menu/{id}` | Archive en brouillon; ne détruit pas le plat |
| POST | `/tuc/v1/pro/menu/{id}/photo` | JPEG/PNG/WebP, 5 Mo maximum, attaché au plat tenant-scoped |
| GET public | `/tuc/v1/menu/{slug}` | Menu publié uniquement et paramètres de commande publics non sensibles |

Le CPT `tuc_menu_item` reste accessible par son API WordPress native aux administrateurs seulement. Les rôles TUC gèrent les plats par les routes métier dédiées.

## Options et tarification

Une définition version 2 a cette forme :

```json
{
  "version": 2,
  "groups": [{
    "id": "format",
    "name": "Format",
    "required": true,
    "min": 1,
    "max": 1,
    "visible_if": null,
    "choices": [
      {"id": "regular", "name": "Régulier", "price_delta": 0, "available": true},
      {"id": "xl", "name": "XL", "price_delta": 10, "available": true}
    ]
  }]
}
```

Une sélection client ne transporte que `group_id` et les identifiants des choix. Le serveur valide les groupes, min/max, conditions et disponibilités, puis recalcule les suppléments; tout prix envoyé par le client est ignoré. Les anciennes listes plates `{id, nom, prix}` restent interprétables sans migration forcée. La commande conserve un instantané canonique des groupes et des libellés choisis.

Le champ nouveau `tuc_promo_pct` est une remise simple de 0 à 100 % sur le prix de base, avant suppléments. Il ne remplace pas et ne migre pas l’ancien champ `tuc_promo`. Les promotions datées, cumulées ou à règles avancées ne sont pas incluses.

## Parcours commande

La fiche client charge les plats publiés depuis l’API publique; le panier stocké localement ne contient pas le numéro de téléphone. À la confirmation, le navigateur envoie restaurant, mode, table, lignes/options, numéro et total annoncé. `/tuc/v1/commande` revalide les données, recalcule les prix/frais/minimum, refuse les tables/modes invalides, applique l’idempotence et crée une commande transmise à la caisse TUC. La commande est payée sur place; aucun paiement en ligne n’est ajouté.

## Hors périmètre et validation restante

- Pas de gestion des horaires, catégories, commandes dans l’Espace Pro, duplication, compression photo côté navigateur ou historique des modifications.
- Le prototype n’a pas été testé sur appareils réels ni avec des restaurateurs; les traductions RTL ne sont pas activées.
- Le workflow WordPress Docker smoke test est passé sur le commit `2dd07d7` (run [42141785151](https://github.com/bernoussifatiha84-good/tuc-restaurant/actions/runs/42141785151)), y compris PHP lint, régressions WordPress/TUC et E2E n8n. La couverture CI ne remplace pas un téléversement photo valide ni un test sur appareil réel.
- WooCommerce et ses données/options restent hors de ce changement; aucune désactivation, suppression ou migration n’est autorisée sans validation explicite et sauvegarde vérifiable.
