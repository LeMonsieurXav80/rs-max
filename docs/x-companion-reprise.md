# X public avec RS-Max, Companion et Hermès

État vérifié le 12 novembre 2026, en fin de journée. RS-Max `bd582c4` tourne sur l’instance principale (lemonsieurxav). DIX tourne en `d74250a` : la correction `bd582c4` y attend son déploiement. Companion `0.18.40` est actif sur le NAS (`nas-wildycaro`, `nas-x`) et sur le VPS DIX (`vps-dix`). Le Mac doit recharger l’extension dans chaque profil Chrome.

Hermès agit seul sur deux comptes pilotes : Wildy Caro (7) sur l’instance principale, Cyril Certain (23) sur DIX. Chaque profil Hermès a un cycle « RS-Max — File X » toutes les deux minutes, de 06 h à 22 h Lisbonne. L’agent ne démarre que si la prélecture `rsmax_x_watch.py` trouve du travail.

## Ce que fait le community manager sur X

| Geste | Agent (NAS / VPS) | Companion local (Mac) |
| --- | --- | --- |
| Publications planifiées RS-Max | `x.post` sur le profil du compte, médias avant texte, preuve après rechargement | — |
| Mentions et réponses reçues | `inbox --pending`, `reply-context`, `reply --commit`, like automatique | « X — gérer les mentions » |
| Remerciements en fin d’échange | Like automatique, sans réponse | idem, refus serveur d’une relance sans question |
| Flux Abonnements et recherches de la fiche | `x-read feed.read` / `search.read`, puis `thread.read` | « X — Ouvrir une recherche RS-Max », « Examiner les publications récentes » |
| Commenter, liker une publication | `x-context` puis `x-action reply` / `like` | « X — Réagir à cette publication » |
| Liker un commentaire | `x-action like --target ID` sur un post de `visible_replies` ou de la branche | « Réagir » ouvert sur la page du commentaire |
| Suivre un profil | `priority_x_follow`, `profile.read`, `x-action follow` | « X — Suivre ce profil » |

Les messages privés ne sont jamais traités. Chaque réponse exige la branche complète, de la publication d’origine à la cible (20 messages au plus), et le persona actif du compte. Le Vault d’un compte (DIX : fiches PubMed actives de `Recherches/`) arrive dans `vault` avec `vault.guard` : ne citer que les sources, aucun conseil médical, dosage ni information interne.

Tous les gestes sont journalisés 7 jours dans `bot_action_logs`. La colonne `source` distingue l’agent (`agent`), le worker distant (`browser`) et Companion local (`extension`). Les reçus anti-doublon et les compteurs durables survivent à la purge.

## Corrections du 12 novembre

- **Collecte des mentions.** L’onglet Mentions de X ne montrait plus les réponses récentes depuis le 11/11 vers 17 h (33 manquées en 18 h pour Wildy Caro). `TwitterBrowserInboxService` lit aussi la recherche « Récent » `to:compte` et fusionne par identifiant.
- **Remerciements.** Une relance sans question reste sans réponse mais reçoit un like, dans le quota du compte.
- **Gaspillage.** Une réponse du compte vue sous une cible est mémorisée 15 jours et entre dans le préfiltre. Un fil déjà commenté et aimé est refusé sans onglet (`skip_item:true`). Le catalogue des comptes n’est journalisé qu’une fois par demi-heure et par compte (259 lignes par quart d’heure auparavant).
- **Likes de commentaires.** `prepare` accepte like et signet sur tout post lu dans le fil. La réponse reste réservée à la cible dont le contexte est chargé.
- **Publications planifiées.** Une publication refusée avant le clic est relancée 3 fois au plus, à 3 minutes d’intervalle, si elle a moins de 2 h de retard. Un résultat incertain n’est jamais relancé.
- **Companion 0.18.39.** Preuve d’une publication dont X raccourcit le lien (`t.co`, texte « domaine/chemin… »). L’éditeur dispose de 15 s pour intégrer le texte collé.
- **Companion 0.18.40.** Seul un post daté après le clic est retenu comme réponse. Un ancien post du compte apparu dans la page faisait échouer la preuve.
- **Réponse de découverte incertaine.** Si l’identifiant capturé est faux, `VerifyXEngagementReply` relit la cible et retient l’unique réponse du compte au texte exact, datée de la tentative, avant le contrôle de parenté. Aucun second geste.
- **Hermès DIX.** Le SOUL du profil `rsmax-dix` et l’objectif du compte 23 n’autorisaient que des brouillons. Ils autorisent désormais le cycle autonome. La base anti-doublon du profil appartenait à `root` et bloquait toute écriture.
- **Tests Chromium.** `x-mentions.cjs` et `x-backlog.cjs` étaient restés sur l’ancienne API (identité par `twid`, réservations `/extension/x/assisted/reactions`). Les trois scénarios X passent avec l’extension réelle.

## Diagnostiquer une baisse des réponses

1. Comparer la dernière mention en base avec une lecture `x.tweets` de `https://x.com/search?q=to%3ACOMPTE&f=live`. Une file vide peut venir de X, pas de l’agent.
2. Relever les motifs dans `agent_review_reason` : `already_handled` (réponse du compte visible sous la cible), `not_relevant` (relance sans question, aimée), `missing_facts`, `x_target_not_found`. Les deux premiers sont gelés 7 jours.
3. Dans `logs --account ID --platform twitter --limit 50`, distinguer réponses confirmées, likes confirmés et lectures. Le profil de la tâche indique le worker réel.
4. Une tâche refusée « Navigateur du NAS injoignable » signifie que la file du profil était occupée plus d’une minute. `nas-wildycaro` partage TikTok, Threads et Planète de Caro ; `nas-x` ne sert que Wildy Caro.

## Maintenir Companion

Déployer la même version sur le Mac, le NAS et le VPS concerné en conservant `config.local.js` par installation. Sauvegarder les fichiers remplacés dans `companion-before-<version>`. Réserver les files avec `php artisan browser:maintenance PROFIL… --lease=90 --wait=30`, attendre `PRÊT`, redémarrer Chromium deux fois, puis vérifier la version dans `metadata.steps[task.received].details.version` d’une tâche suivante. Les patchs sont dans `companion/patches/`.

Déploiement DIX : image en couches `127.0.0.1:5019/rs-max-dix:<sha>-<nom>` (FROM l’image précédente, COPY des dossiers modifiés), puis tag dans l’application Coolify DIX. Aucun déploiement automatique.

Voir la [documentation API](API.md), [les profils Companion](companion-workers.md) et le [skill Hermès](../hermes/skills/community-manager-x/SKILL.md).
