# Meta Ads — CPM constaté pour l'EMV

Branche le compte publicitaire Meta en **lecture seule** pour valoriser l'EMV avec
le CPM réellement payé, au lieu du barème de `config/emv.php`.

## Pourquoi un jeton « utilisateur système »

Un jeton **utilisateur** (celui qu'on récupère par OAuth) meurt à chaque changement
de mot de passe ou reset de sécurité Meta, même déclaré `expires_at: 0` :

```
code 190, subcode 460
"The session has been invalidated because the user changed their password
 or Facebook has changed the session for security reasons."
```

C'est exactement ce qui a tué le jeton du MCP `meta-ads-dix` (constaté le 09/09/2026).

Un jeton **utilisateur système** appartient au portefeuille Business, pas à une
personne physique : il n'expire pas et survit aux changements de mot de passe.
C'est pour ça qu'il est traité ici comme une clé API (chiffrée dans `settings`)
et **pas** comme un compte social — il ne passe par aucun flux OAuth et ne vit pas
dans `social_accounts`.

## Pas d'App Review

L'App Review de Meta ne concerne que l'accès aux données d'utilisateurs **sans rôle
sur l'app**. Ici, l'utilisateur système appartient au portefeuille qui possède à la
fois l'app et le compte publicitaire : Meta traite ça comme un accès à ses propres
actifs, en **accès standard**, immédiat.

Ce que Meta peut demander en revanche, c'est la **vérification du Business**
(documents d'entreprise) — autre chose que l'App Review, et généralement déjà faite
dès qu'un compte publicitaire tourne.

## Générer le jeton

1. **Business Manager** → *Paramètres d'entreprise* → *Utilisateurs* → **Utilisateurs système**
2. **Ajouter** → nom (`rs-max-lecture`), rôle **Employé** (suffisant en lecture)
3. **Ajouter des actifs** → *Comptes publicitaires* → cocher le compte → activer
   **Afficher les performances** (lecture seule ; *Gérer la campagne* n'est pas nécessaire)
4. **Générer un nouveau jeton** → choisir l'app → cocher les scopes :
   - **`ads_read`** — suffit pour le CPM constaté et la lecture des campagnes ;
   - **`ads_management`** — **en plus**, si tu veux piloter les campagnes
     (pause / reprise / budget) via `/api/meta-ads/*`.
5. Copier le jeton — **il n'est affiché qu'une fois**

Choisis les scopes maintenant : **on n'élargit pas un jeton existant**, il faut en
générer un nouveau. Si tu comptes piloter les campagnes un jour, prends
`ads_management` tout de suite — l'écriture reste de toute façon fermée côté RS-Max
tant que `META_ADS_WRITE_ENABLED` vaut `false`.

À l'étape 3, le pilotage demande aussi **Gérer la campagne** sur l'actif, pas
seulement *Afficher les performances*.

## Configurer RS-Max

`/settings` → onglet **Statistiques** → bloc *Meta Ads — CPM constaté* :

| Champ | Valeur |
|---|---|
| Jeton utilisateur système | le jeton copié (chiffré, jamais réaffiché) |
| Compte publicitaire | `act_123456789` (le préfixe `act_` est ajouté s'il manque) |
| Source du CPM pour l'EMV | *CPM constaté sur Meta Ads* |

Puis **Tester la connexion** : liste les comptes publicitaires visibles par le jeton
et affiche le CPM constaté par régie. C'est le moyen le plus rapide de retrouver
l'identifiant du compte si tu ne l'as pas sous la main.

## Ce que ça change dans le calcul

`MetaAdsService::observedCpm()` interroge `/act_{id}/insights` ventilé par
`publisher_platform` sur 90 jours, et recalcule le CPM depuis `spend / impressions`
plutôt que de lire le champ `cpm` (indépendant d'un arrondi côté Meta).
Résultat mis en cache **6 h** — le CPM constaté bouge lentement, inutile d'appeler
Graph à chaque affichage de page.

Trois garde-fous :

- **Seules les régies Meta sont couvertes** — `facebook`, `instagram`, `threads`.
  Tous les autres réseaux gardent leur barème. `messenger` et `audience_network`
  sont ignorés (pas d'équivalent publiable dans RS-Max).
- **Devise différente = barème conservé.** Un CPM en USD appliqué à une valorisation
  en EUR serait faux sans le dire ; le cas est loggué en `warning`.
- **L'origine est affichée.** Chaque ligne porte `cpm_source` (`reference` ou
  `meta_observed`), et l'UI marque « constaté » — un chiffre montré à un partenaire
  doit pouvoir dire d'où il sort.

Le cache est purgé à chaque enregistrement des réglages et à chaque test de connexion.

## Diagnostic

```bash
php artisan tinker
>>> app(\App\Services\Meta\MetaAdsService::class)->adAccounts();
>>> app(\App\Services\Meta\MetaAdsService::class)->observedCpm();
```

Une erreur `code 190` signale un jeton mort : si c'est un jeton **utilisateur**,
c'est la cause structurelle — repasser sur un utilisateur système.
