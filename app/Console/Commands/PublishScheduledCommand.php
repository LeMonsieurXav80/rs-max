<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\PublishingService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PublishScheduledCommand extends Command
{
    protected $signature = 'posts:publish-scheduled';
    protected $description = 'Publie les posts programmes dont la date est passee';

    public function handle(PublishingService $publishingService): int
    {
        $posts = Post::readyToPublish()->with('postPlatforms.socialAccount.platform')->get();

        if ($posts->isEmpty()) {
            $this->info('Aucun post a publier.');
            return Command::SUCCESS;
        }

        $this->info("Publication de {$posts->count()} post(s)...");

        foreach ($posts as $post) {
            $this->info("  -> Post #{$post->id}: " . Str::limit($post->content_fr, 50));
            $publishingService->publish($post);
        }

        $this->info('Termine.');
        return Command::SUCCESS;
    }
}
