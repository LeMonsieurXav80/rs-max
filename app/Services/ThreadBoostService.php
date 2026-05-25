<?php

namespace App\Services;

use App\Models\Thread;
use App\Models\ThreadSegment;
use App\Models\ThreadSegmentPlatform;
use Illuminate\Database\Eloquent\Collection;

class ThreadBoostService
{
    /**
     * Insère un segment "boost" (promotion d'un autre fil) au milieu d'un fil existant.
     * Décale automatiquement les positions des segments suivants.
     *
     * @param  Thread  $thread  Le fil dans lequel insérer le boost.
     * @param  Collection  $accounts  Comptes sociaux pour créer les ThreadSegmentPlatform.
     * @param  int  $sourceThreadId  ID du fil source à promouvoir.
     * @param  string  $promoText  Texte de promotion (sera complété par l'URL source si pertinent).
     */
    public function insertBoostSegment(Thread $thread, Collection $accounts, int $sourceThreadId, string $promoText): ?ThreadSegment
    {
        $sourceThread = Thread::with(['segments' => fn ($q) => $q->orderBy('position'), 'segments.segmentPlatforms.socialAccount.platform'])
            ->find($sourceThreadId);

        if (! $sourceThread) {
            return null;
        }

        $firstSegment = $sourceThread->segments->first();
        if (! $firstSegment) {
            return null;
        }

        // URL source : on prend la 1re platform_url non nulle du segment 0.
        $sourceUrl = $firstSegment->segmentPlatforms
            ->whereNotNull('platform_url')
            ->where('status', 'published')
            ->pluck('platform_url')
            ->first();

        if (! $sourceUrl) {
            // Fil source pas encore publié — on abandonne plutôt que d'insérer un boost cassé.
            return null;
        }

        // Position : milieu du fil. Si N segments, on insère en position floor(N/2)+1.
        $existingSegments = $thread->segments()->orderBy('position')->get();
        $count = $existingSegments->count();
        $boostPosition = (int) floor($count / 2) + 1;

        // Décale les segments existants en position ≥ boostPosition de +1.
        // reorder() écrase l'ORDER BY ASC par défaut de la relation Thread::segments() :
        // sans ça, le shift se ferait en ASC et la 1re update créerait un duplicate
        // sur l'index unique (thread_id, position).
        $thread->segments()
            ->where('position', '>=', $boostPosition)
            ->reorder('position', 'desc')
            ->get()
            ->each(fn ($s) => $s->update(['position' => $s->position + 1]));

        // Construit le contenu : le texte de promo + URL (l'URL sera embedée nativement
        // sur X et Bluesky au moment de la publication, sinon affichée comme lien).
        $content = trim($promoText)."\n\n".$sourceUrl;

        $segment = ThreadSegment::create([
            'thread_id' => $thread->id,
            'position' => $boostPosition,
            'content_fr' => $content,
            'is_boost' => true,
            'boost_source_thread_id' => $sourceThread->id,
            'boost_source_url' => $sourceUrl,
        ]);

        foreach ($accounts as $account) {
            ThreadSegmentPlatform::create([
                'thread_segment_id' => $segment->id,
                'social_account_id' => $account->id,
                'platform_id' => $account->platform_id,
                'status' => 'pending',
            ]);
        }

        return $segment;
    }

    /**
     * Trouve l'external_id du segment 0 du fil source pour une plateforme cible donnée
     * (pour permettre nativeQuote au moment de la publication).
     *
     * @return array{external_id: string, url: string}|null
     */
    public function findSourceForPlatform(int $sourceThreadId, int $platformId): ?array
    {
        $firstSegment = ThreadSegment::where('thread_id', $sourceThreadId)
            ->orderBy('position')
            ->first();

        if (! $firstSegment) {
            return null;
        }

        $segmentPlatform = $firstSegment->segmentPlatforms()
            ->where('platform_id', $platformId)
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->first();

        if (! $segmentPlatform) {
            return null;
        }

        return [
            'external_id' => $segmentPlatform->external_id,
            'url' => $segmentPlatform->platform_url ?? '',
        ];
    }
}
