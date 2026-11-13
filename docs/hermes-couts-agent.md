# Coût en jetons de l'agent Community Manager (Hermès)

Suivi de la consommation du modèle par les files Hermès de RS-Max, et des réglages faits le 13/11/2026 pour la réduire.
Les deux instances (lemonsieurxav, profil `community-manager` ; DIX, profil `rsmax-dix`) consomment **le même
abonnement ChatGPT Pro** : leur coût s'additionne sur un seul quota hebdomadaire.

## Où lire

| Quoi | Où |
|---|---|
| Un réveil du modèle par ligne : job, jetons d'entrée (cache compris), de sortie, silencieux ou non | `cron/usage_audit.jsonl` du profil |
| Appels, cache et messages par session | `state.db` du profil (`sessions`, `messages`), à ouvrir en `?mode=ro` |
| Déclenchements du cron, dont ceux évités par la prélecture (`no_change`) | `cron/executions.db` (≈ 17 h conservées) |
| Gestes confirmés | `account_action_events` de RS-Max, ou le bilan `report` en dry-run |
| Quota de l'abonnement | réglages Codex de chatgpt.com, page Usage, ou `/usage` dans une session Hermès |
| Journal quotidien (jetons **et** gestes de la veille) | `workspace/cost-history.jsonl` du profil, écrit par le bilan de 08 h |

Commandes, depuis le profil (`docker exec -u hermes -w /opt/data/profiles/<profil> -e HERMES_HOME=/opt/data/profiles/<profil> <gateway> …`) :

```bash
python3 scripts/rsmax_cost_report.py            # 7 derniers jours, par jour et par file
python3 scripts/rsmax_cost_report.py --history  # journal quotidien, jetons par geste compris
```

Le bilan Telegram de 08 h et 20 h porte une ligne **« Coût agent »** sur les mêmes 12 h : réveils, jetons,
jetons par réveil, part en cache, gestes confirmés et jetons par geste.

`hermes insights` existe mais ouvre la base vive en écriture (`SessionDB`) : préférer le relevé ci-dessus.

## Comment se forme le coût

À chaque appel d'outil, le modèle reçoit **tout** le contexte : prompt système, message de cycle (skill + prompt +
prélecture) et tous les résultats déjà obtenus. Le coût d'un réveil croît donc à peu près comme le carré du nombre
d'appels. Un bloc lu tôt et volumineux se paie une fois par appel suivant.

## État de référence, 13/11/2026 (mesuré)

Fenêtre : 24 h jusqu'au 13/11 à 10 h 30 UTC. Gestes : `account_action_events`, statut `confirmed`, gestes de
community manager seulement (réponses, likes, commentaires, suivis), hors publications.

**lemonsieurxav** (`gpt-6.1-sol`)

| File | Fréquence | Réveils évités | Réveils/j | Jetons/réveil (moy. / max) | Jetons/j | Silencieux | Gestes | Jetons/geste |
|---|---|---|---|---|---|---|---|---|
| X | 2 min | 63 % | 267 | 162 k / 1,0 M | 43,5 M | 82 × 27 k | 358 | 121 k |
| Threads | 5 min | 49 % | 126 | 131 k / 545 k | 16,5 M | 80 × **72 k** | 41 | 403 k |
| API | 5 min | 69 % | 127 | 117 k / 683 k | 14,9 M | 50 × 40 k | 262 | 57 k |
| TikTok | 5 min | 68 % | 65 | 155 k / 741 k | 10,1 M | 25 × 35 k | 31 | 326 k |
| **Total** | | | **585** | 145 k | **85,0 M** (82 % cache) | 10,9 M | **692** | **123 k** |

Sur 7 jours : 1 600 réveils, 346 M jetons, 2 309 gestes (150 k/geste). Sessions les plus chères : 1,5 à 2,1 M jetons,
35 à 42 appels (9–11/11, avant le découpage des files).

**DIX** (`gpt-6-astra`, profil créé le 12/11 à 12 h : 22 h d'historique)

| File | Fréquence | Réveils évités | Réveils/j | Jetons/réveil | Jetons/j | Silencieux | Gestes | Jetons/geste |
|---|---|---|---|---|---|---|---|---|
| X | 2 min | 73 % | 166 | 173 k / 456 k | 28,8 M | 45 × 45 k | 45 | **640 k** |

**Quota** : fenêtre hebdomadaire ouverte le 11/11 à 07 h 01 UTC, **43 %** consommés au bout de 51,5 h sur 168 ;
Hermès y a enregistré 259 M jetons, dont 78 % pour le Community Manager. À ce rythme, quota épuisé vers le 16/11.

### Ventilation d'une session (part du contexte renvoyé, 24 h)

| Bloc | lemonsieurxav | DIX |
|---|---|---|
| Skill `community-manager-x` injectée (26,5 k car.) | 27,9 % | 28,0 % |
| Même skill relue par `skill_view` | 0 | **22,9 %** |
| Prompt système (SOUL + Hermès) | 13,4 % | 15,3 % |
| Prompt de cycle, tous réseaux (12,2 k car.) | 12,8 % | 13,0 % |
| `accounts` (7 comptes, cadence complète) | 13,4 % | 5,2 % |
| `strategy` (dont historique horaire) | 6,9 % | 3,9 % |
| `reply-context(s)`, dont 80 % de persona répété | 8,9 % | ≈ 0 |
| Prélecture | 4,7 % | 4,1 % |
| Autres outils (`logs`, `x-read`, `inbox`…) | 8,6 % | 6,0 % |
| Extraits du **Vault** | **0,5 %** | **0,1 %** |
| Schémas d'outils | non mesurable (≈ 0) | ≈ 0 |

Le Vault n'est donc pas en cause. Le socle (skill, système, prompt) renvoyé à chaque appel fait 54 % du coût
(79 % sur DIX), les relectures d'`accounts`, `strategy` et du persona environ 30 %.

## Changements du 13/11/2026

| # | Changement | Cause mesurée | Gain attendu (estimé) |
|---|---|---|---|
| 1 | Une skill par file, `rsmax-cycle-{x,api,threads,tiktok}`, assemblée par `hermes/build_cycles.py` depuis `hermes/cycles/` ; prompt de cycle réduit à 300 caractères (`hermes/cycles/build/prompt-<file>.txt`) | Chaque file chargeait 38,7 k caractères de règles de tous les réseaux | Message de cycle −70 à −80 % : ≈ 25 M/j lemonsieurxav, 6 M/j DIX |
| 2 | Plus de « Charger community-manager-x » ; toolset cron limité à `terminal` sur DIX | Relecture de la skill par `skill_view` à chaque réveil sur DIX | ≈ 6,6 M/j DIX |
| 3 | La prélecture suffit : ne plus appeler `accounts`, `strategy`, `date` en cycle ; `now_lisbon` ajouté à la prélecture ; `accounts` et `strategy` compacts par défaut (`--full` pour tout) | Appelés dans 337 et 280 réveils sur 585, alors que la prélecture les fournit | ≈ 15 M/j lemonsieurxav, 2 M/j DIX |
| 4 | Persona transmis une fois par session Hermès (`HERMES_SESSION_ID`), ensuite `same_as_previous` (`--full-persona` pour le redemander) | 7 à 10 k car. de persona à chaque `reply-context` | ≈ 5 M/j lemonsieurxav |
| 5 | Une cible lue sans décision doit recevoir `review` ; garde-fou serveur : au 4e contexte en 3 h sans réponse, `agent_no_decision`, écartée 6 h | Un compliment séducteur sur Threads relu toutes les 5 min (≈ 72 k jetons à chaque fois) | ≈ 6 M/j |
| 6 | Plan Threads transmis une seule fois (`discovery_plan: "priority_threads"`) | Plan et ses 15 URL en double dans la prélecture et son diff | faible |
| 7 | `agent.max_turns: 20` dans les deux profils | Le profil déclarait `max_iterations: 12`, clé que le cron ne lit pas : aucun plafond, sessions jusqu'à 42 appels | protège des pics (jusqu'à 2 M jetons) |
| 8 | File X toutes les **4 minutes** au lieu de 2, sur les deux instances | Choix de Xavier du 13/11/2026 pour réduire le coût | quelques M/j, à mesurer |
| 9 | SOUL réduite (4,1 k → 1,6 k car. sur lemonsieurxav) : les procédures par réseau sont dans les skills de file | Prompt système renvoyé à chaque appel | ≈ 7 M/j |

Les gains se recoupent et ne s'additionnent pas : objectif −50 à −60 % de jetons par jour à gestes constants.

Non retenu : mettre le Vault en cache (0,5 %) et retirer `context_from: self` (0,9 %).

### Réglages cibles des profils

```yaml
# config.yaml des profils community-manager (lemonsieurxav) et rsmax-dix (DIX)
agent:
  max_turns: 20
platform_toolsets:
  cron:
  - terminal
```

| Job | Skill | Prompt | Horaire |
|---|---|---|---|
| RS-Max — File X | `rsmax-cycle-x` | `prompt-x.txt` | `*/4 * * * *` |
| RS-Max — File API | `rsmax-cycle-api` | `prompt-api.txt` | `1-59/5 * * * *` |
| RS-Max — File Threads | `rsmax-cycle-threads` | `prompt-threads.txt` | `2-59/5 * * * *` |
| RS-Max — File TikTok | `rsmax-cycle-tiktok` | `prompt-tiktok.txt` | `3-59/5 * * * *` |

La skill complète `community-manager-x` reste en place pour les missions.

## Déployer une modification des consignes

1. Modifier `hermes/cycles/*.md` (jamais les `SKILL.md` générés), puis `python3 hermes/build_cycles.py`.
   `cli/qa/test_cost_report.py` échoue si une skill générée est périmée ou dépasse 14 000 caractères.
2. Copier `hermes/skills/rsmax-cycle-*` dans `skills/` des deux profils, et `cli/community_manager.py`,
   `hermes/scripts/*.py` dans `scripts/`.
3. Si le prompt change : `hermes cron edit <job> --prompt "$(cat hermes/cycles/build/prompt-<file>.txt)"`.
4. Noter la date dans le journal ci-dessous pour pouvoir attribuer la variation.

## Retour arrière

Les fichiers remplacés sont sauvegardés dans chaque profil sous `backups/couts-20261113/`. Rétablir l'ancienne
skill d'une file : `hermes cron edit <job> --skill community-manager-x --prompt "$(cat backups/couts-20261113/prompt-<job>.txt)"`.

## Journal des mesures

Une ligne par journée complète (Lisbonne), depuis `rsmax_cost_report.py --history` sur chaque instance.
Comparer à gestes constants : les jetons par geste sont l'indicateur principal, les jetons par jour le second.

| Jour | Instance | Réveils | Jetons/j | Jetons/réveil | Gestes | Jetons/geste | Quota Codex | Changement |
|---|---|---|---|---|---|---|---|---|
| 12/11 (réf.) | lemonsieurxav | 547 | 78,5 M | 144 k | — | — | — | avant |
| 12/11 (réf., 12 h → 24 h) | DIX | 121 | 20,8 M | 172 k | — | — | — | avant |
| 13/11 (24 h glissantes, réf.) | lemonsieurxav | 585 | 85,0 M | 145 k | 692 | 123 k | 43 % (sem.) | avant |
| 13/11 (24 h glissantes, réf.) | DIX | 166 | 28,8 M | 173 k | 45 | 640 k | 43 % (sem.) | avant |
| 13/11 | les deux | | | | | | | changements 1 à 9 déployés |
