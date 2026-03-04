# Architecture Statistiques

## Vue d'ensemble

Le module statistiques collecte et affiche les métriques des comptes sociaux et publications. Il est structuré en 4 sous-rubriques accessibles à tous les utilisateurs authentifiés.

## Sous-rubriques

| Route | Vue | Description |
|-------|-----|-------------|
| `/stats` | Vue d'ensemble | KPIs résumés, filtres période/comptes |
| `/stats/audience` | Audience | Évolution followers par compte (graphique + tableau) |
| `/stats/publications` | Publications | Top posts, engagement moyen, tendances |
| `/stats/platforms` | Plateformes | Comparaison cross-platform |

## Snapshots followers

### Table `social_account_snapshots`

Stocke l'historique des followers pour chaque compte social.

```
id, social_account_id, date, granularity, followers_count, created_at
```

- `granularity` : `daily`, `weekly` ou `monthly`
- Index unique : `(social_account_id, date, granularity)`
- Les snapshots sont immuables (pas de `updated_at`)

### Règle de capture

- Le snapshot est pris **uniquement** lors du cron `followers:sync` à **06h00**
- Les mises à jour manuelles (bouton "Actualiser" dans le dashboard) mettent à jour `social_accounts.followers_count` mais **ne créent pas de snapshot**
- `updateOrCreate` sur `(social_account_id, date, granularity='daily')` pour éviter les doublons

### Downsampling (rétention progressive)

| Ancienneté | Granularité conservée | Règle |
|---|---|---|
| 0 - 12 mois | **Quotidienne** | Aucune agrégation |
| 12 - 24 mois | **Hebdomadaire** | Garder la dernière valeur de chaque semaine (dimanche), supprimer les autres daily |
| 24+ mois | **Mensuelle** | Garder la dernière valeur de chaque mois, supprimer les autres weekly |

- Commande : `php artisan snapshots:downsample`
- Planification : 1er du mois à 03h00

### Estimation de stockage

```
10 comptes × 365 jours = 3 650 lignes/an (~110 Ko)
Après downsampling n-1 : ~520 lignes weekly
Après downsampling n-2 : ~120 lignes monthly
10 comptes sur 5 ans ≈ 5 000 lignes (~150 Ko)
```

## Métriques collectées par plateforme

| Plateforme | Followers | Views | Likes | Comments | Shares | Autres |
|---|---|---|---|---|---|---|
| Facebook | page followers_count | post insights | reactions | comments | - | - |
| Instagram | followers_count | views | likes | comments | shares | saved |
| Twitter/X | public_metrics.followers_count | impressions | likes | replies | retweets | - |
| YouTube | subscriberCount | viewCount | likeCount | commentCount | - | - |
| Threads | threads_insights | views | likes | replies | reposts+quotes | - |
| Bluesky | followersCount | - | likes | replies | reposts+quotes | - |
| Telegram | getChatMemberCount | - | - | - | - | - |
| Reddit | - | - | score (upvotes) | num_comments | - | - |

## Sources de données publications

Les stats publications agrègent deux sources :
- **PostPlatform** : posts publiés via l'app (avec `status='published'` et `external_id`)
- **ExternalPost** : posts historiques importés depuis les plateformes

Dédupliqués par `platform_id + external_id` pour éviter les doublons.

## Sync des métriques publications

Fréquence de sync basée sur l'âge du post :
- < 48h : toutes les heures
- 2-7 jours : toutes les 6h
- 7+ jours : configurable (défaut 24h)
- Au-delà de `max_days` : sync manuelle uniquement
