<?php

namespace App\Console\Commands;

use App\Models\Persona;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\RssFeed;
use App\Models\RssPost;
use App\Models\User;
use App\Services\Rss\ContentGenerationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RssGenerateCommand extends Command
{
    protected $signature = 'rss:generate
        {--feed= : Generate for a specific feed ID}
        {--dry-run : Preview without creating posts}';

    protected $description = 'Generate scheduled posts from RSS feed items using AI';

    public function handle(): int
    {
        $feedId = $this->option('feed');
        $dryRun = $this->option('dry-run');

        // Get admin user for post ownership
        $adminUser = User::where('is_admin', true)->first();
        if (! $adminUser) {
            $this->error('No admin user found.');

            return Command::FAILURE;
        }

        // Load feeds with linked accounts where auto_post=true (or specific feed)
        $query = RssFeed::where('is_active', true)
            ->with(['socialAccounts' => function ($q) {
                $q->wherePivot('auto_post', true);
            }, 'socialAccounts.platform']);

        if ($feedId) {
            $query->where('id', $feedId);
        }

        $feeds = $query->get();

        if ($feeds->isEmpty()) {
            $this->info('No active feeds with auto-post accounts found.');

            return Command::SUCCESS;
        }

        $results = ['generated' => 0, 'skipped' => 0, 'failed' => 0];
        $totalCap = 50; // Max posts per command run
        $generationService = new ContentGenerationService;

        foreach ($feeds as $feed) {
            foreach ($feed->socialAccounts as $account) {
                if ($results['generated'] >= $totalCap) {
                    $this->info("Cap of {$totalCap} posts reached, stopping.");
                    break 2;
                }

                $personaId = $account->pivot->persona_id;
                $maxPerDay = $account->pivot->max_posts_per_day ?? 1;

                if (! $personaId) {
                    $this->warn("  Feed \"{$feed->name}\" → Account \"{$account->name}\": no persona configured, skipping.");
                    $results['skipped']++;

                    continue;
                }

                $persona = Persona::find($personaId);
                if (! $persona) {
                    $results['skipped']++;

                    continue;
                }

                // Find unposted items for this account
                $unpostedItems = $feed->rssItems()
                    ->whereDoesntHave('rssPosts', function ($q) use ($account) {
                        $q->where('social_account_id', $account->id);
                    })
                    ->orderBy('published_at', 'desc')
                    ->limit($totalCap - $results['generated'])
                    ->get();

                if ($unpostedItems->isEmpty()) {
                    $this->info("  Feed \"{$feed->name}\" → Account \"{$account->name}\": no new items.");

                    continue;
                }

                // Count already scheduled today
                $scheduledToday = RssPost::where('social_account_id', $account->id)
                    ->whereDate('created_at', today())
                    ->count();

                $remainingToday = max(0, $maxPerDay - $scheduledToday);

                // Calculate how many to generate this run
                $toGenerate = min($remainingToday, $unpostedItems->count(), $totalCap - $results['generated']);

                if ($toGenerate <= 0) {
                    $this->info("  Feed \"{$feed->name}\" → Account \"{$account->name}\": daily limit reached ({$maxPerDay}/day).");

                    continue;
                }

                $this->info("  Feed \"{$feed->name}\" → Account \"{$account->name}\": generating {$toGenerate} post(s)...");

                // Calculate scheduling: find last scheduled post for this account
                $lastScheduled = Post::where('user_id', $adminUser->id)
                    ->where('source_type', 'rss')
                    ->where('status', 'scheduled')
                    ->whereHas('postPlatforms', function ($q) use ($account) {
                        $q->where('social_account_id', $account->id);
                    })
                    ->max('scheduled_at');

                $nextSlot = $lastScheduled
                    ? Carbon::parse($lastScheduled)
                    : now()->addDay()->setTime(9, 0);

                // Generate time slots
                $timeSlots = $this->generateTimeSlots($maxPerDay);

                $itemsToProcess = $unpostedItems->take($toGenerate);

                foreach ($itemsToProcess as $item) {
                    if ($dryRun) {
                        $this->info("    [DRY RUN] Would generate: \"{$item->title}\" scheduled at {$nextSlot->format('Y-m-d H:i')}");
                        $nextSlot = $this->advanceSlot($nextSlot, $timeSlots, $maxPerDay);
                        $results['generated']++;

                        continue;
                    }

                    // Generate content via AI
                    $content = $generationService->generate($item, $persona, $account);

                    if (! $content) {
                        $this->warn("    Failed to generate content for: \"{$item->title}\"");
                        $results['failed']++;

                        continue;
                    }

                    // Create the post
                    $post = Post::create([
                        'user_id' => $adminUser->id,
                        'content_fr' => $content,
                        'link_url' => $item->url,
                        'source_type' => 'rss',
                        'status' => 'scheduled',
                        'scheduled_at' => $nextSlot,
                        'auto_translate' => true,
                    ]);

                    // Create PostPlatform entry
                    PostPlatform::create([
                        'post_id' => $post->id,
                        'social_account_id' => $account->id,
                        'platform_id' => $account->platform_id,
                        'status' => 'pending',
                    ]);

                    // Create RssPost tracking entry
                    RssPost::create([
                        'rss_item_id' => $item->id,
                        'social_account_id' => $account->id,
                        'persona_id' => $persona->id,
                        'post_id' => $post->id,
                        'generated_content' => $content,
                        'status' => 'generated',
                    ]);

                    $this->info('    '.Str::limit($item->title, 50).' → scheduled '.$nextSlot->format('d/m H:i'));

                    $nextSlot = $this->advanceSlot($nextSlot, $timeSlots, $maxPerDay);
                    $results['generated']++;

                    // Rate limiting: 500ms pause between API calls
                    usleep(500000);
                }
            }
        }

        $this->newLine();
        $this->info("Done: {$results['generated']} generated, {$results['skipped']} skipped, {$results['failed']} failed.");

        return Command::SUCCESS;
    }

    /**
     * Generate time slots for posting within the 08:00-20:00 window.
     */
    private function generateTimeSlots(int $maxPerDay): array
    {
        $startHour = 9;
        $endHour = 20;
        $slots = [];

        if ($maxPerDay <= 1) {
            return [['hour' => 10, 'minute' => 0]];
        }

        $interval = ($endHour - $startHour) / $maxPerDay;

        for ($i = 0; $i < $maxPerDay; $i++) {
            $hour = $startHour + ($interval * $i) + ($interval / 2);
            $slots[] = [
                'hour' => (int) floor($hour),
                'minute' => (int) (($hour - floor($hour)) * 60),
            ];
        }

        return $slots;
    }

    /**
     * Advance to the next scheduling slot.
     */
    private function advanceSlot(Carbon $current, array $timeSlots, int $maxPerDay): Carbon
    {
        // Find the current slot index based on time
        $currentSlotIndex = -1;
        foreach ($timeSlots as $i => $slot) {
            $slotTime = $current->copy()->setTime($slot['hour'], $slot['minute']);
            if ($current->gte($slotTime) && $current->lt($slotTime->addHour())) {
                $currentSlotIndex = $i;
                break;
            }
        }

        $nextIndex = $currentSlotIndex + 1;

        if ($nextIndex >= count($timeSlots)) {
            // Move to next day, first slot
            $next = $current->copy()->addDay();

            return $next->setTime($timeSlots[0]['hour'], $timeSlots[0]['minute']);
        }

        // Same day, next slot
        return $current->copy()->setTime($timeSlots[$nextIndex]['hour'], $timeSlots[$nextIndex]['minute']);
    }
}
