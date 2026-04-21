<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\AiAssistService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApiController extends Controller
{
    /**
     * GET /api/me — Infos utilisateur courant.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ]);
    }

    /**
     * GET /api/accounts — Liste des comptes sociaux actifs de l'utilisateur.
     */
    public function accounts(Request $request): JsonResponse
    {
        $user = $request->user();

        $accounts = $user->activeSocialAccounts()
            ->with(['platform', 'persona'])
            ->orderBy('name')
            ->get()
            ->map(fn (SocialAccount $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'platform' => $a->platform->slug,
                'platform_name' => $a->platform->name,
                'persona' => $a->persona ? [
                    'id' => $a->persona->id,
                    'name' => $a->persona->name,
                ] : null,
                'languages' => $a->languages ?? ['fr'],
                'followers_count' => $a->followers_count,
            ]);

        return response()->json(['accounts' => $accounts]);
    }

    /**
     * GET /api/personas — Liste des personas disponibles.
     */
    public function personas(): JsonResponse
    {
        $personas = Persona::orderBy('name')->get()->map(fn (Persona $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
        ]);

        return response()->json(['personas' => $personas]);
    }

    /**
     * POST /api/bulk-schedule — Génère du contenu IA et planifie des posts en masse.
     *
     * Body JSON :
     * {
     *   "account_id": 3,
     *   "count": 30,
     *   "start_date": "2026-04-21",
     *   "end_date": "2026-05-21",       // optionnel, défaut: start + count jours
     *   "time_from": "09:00",
     *   "time_to": "18:00",
     *   "instructions": "Tweets sur le SEO local, ton décontracté",
     *   "weekdays_only": false,          // optionnel
     *   "hashtags": "#seo #local",       // optionnel
     *   "persona_id": null               // optionnel, override la persona du compte
     * }
     */
    public function bulkSchedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|integer|exists:social_accounts,id',
            'count' => 'required|integer|min:1|max:100',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'time_from' => 'required|date_format:H:i',
            'time_to' => 'required|date_format:H:i|after:time_from',
            'instructions' => 'required|string|max:2000',
            'weekdays_only' => 'nullable|boolean',
            'hashtags' => 'nullable|string|max:1000',
            'persona_id' => 'nullable|integer|exists:personas,id',
        ]);

        $user = $request->user();
        $count = $validated['count'];

        // Vérifier accès au compte
        $account = $user->activeSocialAccounts()
            ->with(['platform', 'persona'])
            ->find($validated['account_id']);

        if (! $account) {
            return response()->json(['error' => 'Compte non trouvé ou inactif.'], 403);
        }

        // Résoudre la persona
        $persona = null;
        if (! empty($validated['persona_id'])) {
            $persona = Persona::find($validated['persona_id']);
        }
        $persona = $persona ?? $account->persona;

        if (! $persona) {
            return response()->json([
                'error' => 'Aucune persona configurée pour ce compte. Assignez une persona avant de générer du contenu.',
            ], 422);
        }

        // Calculer les créneaux horaires
        $startDate = Carbon::parse($validated['start_date']);
        $endDate = ! empty($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : $startDate->copy()->addDays($count);
        $weekdaysOnly = $validated['weekdays_only'] ?? false;

        $slots = $this->generateTimeSlots(
            $startDate,
            $endDate,
            $validated['time_from'],
            $validated['time_to'],
            $count,
            $weekdaysOnly
        );

        if (count($slots) < $count) {
            return response()->json([
                'error' => "Impossible de placer {$count} posts entre {$startDate->format('d/m/Y')} et {$endDate->format('d/m/Y')}. "
                    .'Seulement '.count($slots).' créneaux disponibles. Élargissez la période ou réduisez le nombre.',
            ], 422);
        }

        // Générer le contenu et créer les posts
        $aiService = app(AiAssistService::class);
        $platformSlug = $account->platform->slug;
        $created = [];
        $errors = [];

        for ($i = 0; $i < $count; $i++) {
            $scheduledAt = $slots[$i];

            // Appel IA avec instructions + numéro pour variété
            $prompt = $validated['instructions'];
            $prompt .= "\n\nPost ".($i + 1)."/{$count}.";
            $prompt .= "\nGénère un contenu UNIQUE et DIFFÉRENT des précédents.";
            if ($i > 0) {
                $prompt .= "\nVarie le style, l'angle, et le ton pour éviter la répétition.";
            }

            try {
                $content = $aiService->generate($prompt, $persona, $account);

                if (! $content) {
                    $errors[] = ['index' => $i + 1, 'error' => 'Échec génération IA'];

                    continue;
                }

                $post = DB::transaction(function () use ($user, $content, $scheduledAt, $account, $validated) {
                    $post = Post::create([
                        'user_id' => $user->id,
                        'content_fr' => $content,
                        'hashtags' => $validated['hashtags'] ?? null,
                        'auto_translate' => true,
                        'status' => 'scheduled',
                        'source_type' => 'manual',
                        'scheduled_at' => $scheduledAt,
                    ]);

                    PostPlatform::create([
                        'post_id' => $post->id,
                        'social_account_id' => $account->id,
                        'platform_id' => $account->platform_id,
                        'status' => 'pending',
                    ]);

                    return $post;
                });

                $created[] = [
                    'id' => $post->id,
                    'scheduled_at' => $scheduledAt->toIso8601String(),
                    'content_preview' => mb_substr($content, 0, 100).(mb_strlen($content) > 100 ? '...' : ''),
                ];

                // Délai entre les appels IA pour éviter le rate limiting
                if ($i < $count - 1) {
                    usleep(500_000); // 500ms
                }

            } catch (\Exception $e) {
                Log::error('API bulk-schedule: erreur post '.($i + 1), [
                    'error' => $e->getMessage(),
                ]);
                $errors[] = ['index' => $i + 1, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'success' => true,
            'account' => $account->name,
            'platform' => $platformSlug,
            'total_requested' => $count,
            'total_created' => count($created),
            'total_errors' => count($errors),
            'posts' => $created,
            'errors' => $errors,
        ], count($created) > 0 ? 201 : 500);
    }

    /**
     * Distribue $count créneaux horaires entre startDate et endDate,
     * avec des heures aléatoires entre timeFrom et timeTo.
     */
    private function generateTimeSlots(
        Carbon $startDate,
        Carbon $endDate,
        string $timeFrom,
        string $timeTo,
        int $count,
        bool $weekdaysOnly
    ): array {
        // Collecter tous les jours disponibles
        $availableDays = [];
        $current = $startDate->copy();

        while ($current->lte($endDate)) {
            if (! $weekdaysOnly || $current->isWeekday()) {
                $availableDays[] = $current->copy();
            }
            $current->addDay();
        }

        if (empty($availableDays)) {
            return [];
        }

        // Répartir les posts sur les jours disponibles
        [$fromH, $fromM] = explode(':', $timeFrom);
        [$toH, $toM] = explode(':', $timeTo);
        $fromMinutes = (int) $fromH * 60 + (int) $fromM;
        $toMinutes = (int) $toH * 60 + (int) $toM;

        $slots = [];
        $dayCount = count($availableDays);

        // Distribuer uniformément les posts sur les jours
        for ($i = 0; $i < $count; $i++) {
            $dayIndex = (int) floor($i * $dayCount / $count);
            $day = $availableDays[$dayIndex]->copy();

            // Heure aléatoire dans la plage
            $randomMinute = random_int($fromMinutes, $toMinutes);
            $day->setTime((int) floor($randomMinute / 60), $randomMinute % 60, random_int(0, 59));

            $slots[] = $day;
        }

        // Trier chronologiquement
        usort($slots, fn (Carbon $a, Carbon $b) => $a->timestamp <=> $b->timestamp);

        return $slots;
    }
}
