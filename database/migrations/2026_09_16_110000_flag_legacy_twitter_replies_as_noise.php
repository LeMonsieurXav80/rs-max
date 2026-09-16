<?php

use App\Models\ExternalPost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ecarte du flux les reponses X importees AVANT le filtre `exclude`.
 *
 * L'import exclut desormais retweets et reponses a la source, mais les lignes
 * deja en base ont ete ramenees sans ce filtre. Sans rattrapage, la premiere
 * passe d'adoption automatique transformerait chaque « @machin lol, non »
 * en publication RS-Max.
 *
 * Le reperage se fait sur le texte, seule prise disponible a posteriori :
 * chez X une reponse commence par la mention de son destinataire, un retweet
 * par « RT @ ». Une vraie publication ouvrant sur une mention serait ecartee
 * a tort — elle reste visible dans l'onglet « ignorees » de /external, et le
 * bouton de restauration la rend au flux. C'est le sens qui coute le moins
 * cher.
 *
 * Ne touche ni aux publications deja adoptees, ni a celles qu'un humain a
 * ecartees lui-meme : `ignored_reason` existe exactement pour cette
 * distinction.
 */
return new class extends Migration
{
    public function up(): void
    {
        $platformId = DB::table('platforms')->where('slug', 'twitter')->value('id');

        if (! $platformId) {
            return;
        }

        DB::table('external_posts')
            ->where('platform_id', $platformId)
            ->whereNull('adopted_post_id')
            ->whereNull('ignored_at')
            ->where(function ($q) {
                $q->where('content', 'like', '@%')      // reponse
                    ->orWhere('content', 'like', 'RT @%'); // retweet
            })
            ->update([
                'ignored_at' => now(),
                'ignored_reason' => ExternalPost::IGNORED_AUTO_NOISE,
            ]);
    }

    public function down(): void
    {
        $platformId = DB::table('platforms')->where('slug', 'twitter')->value('id');

        if (! $platformId) {
            return;
        }

        DB::table('external_posts')
            ->where('platform_id', $platformId)
            ->where('ignored_reason', ExternalPost::IGNORED_AUTO_NOISE)
            ->where(function ($q) {
                $q->where('content', 'like', '@%')
                    ->orWhere('content', 'like', 'RT @%');
            })
            ->update(['ignored_at' => null, 'ignored_reason' => null]);
    }
};
