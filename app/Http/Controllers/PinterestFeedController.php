<?php

namespace App\Http\Controllers;

use App\Models\PinterestFeed;
use App\Models\PinterestPin;
use App\Models\SocialAccount;
use App\Models\WpItem;
use App\Models\WpSource;
use App\Services\Pinterest\PinterestApiService;
use App\Services\Pinterest\PinterestImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PinterestFeedController extends Controller
{
    public function index(Request $request): View
    {
        $pinterestAccounts = SocialAccount::whereHas('platform', fn ($q) => $q->where('slug', 'pinterest'))
            ->with('platform')
            ->get();

        $feeds = PinterestFeed::with('socialAccount.platform')
            ->withCount('pins')
            ->orderBy('name')
            ->get();

        $wpSources = WpSource::orderBy('name')->get();

        return view('pinterest-feeds.index', compact('pinterestAccounts', 'feeds', 'wpSources'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'social_account_id' => 'required|exists:social_accounts,id',
            'name' => 'required|string|max:255',
            'board_id' => 'nullable|string|max:255',
            'board_name' => 'nullable|string|max:255',
            'template' => 'required|string|in:overlay,split,bold_text,numbered',
            'colors' => 'nullable|array',
            'colors.background' => 'nullable|string|max:7',
            'colors.text' => 'nullable|string|max:7',
            'language' => 'required|string|max:10',
            'interest' => 'nullable|string|max:50',
            'wp_categories' => 'nullable|array',
        ]);

        $slug = Str::slug($validated['name']) . '-' . Str::random(6);

        $feed = PinterestFeed::create([
            'social_account_id' => $validated['social_account_id'],
            'name' => $validated['name'],
            'slug' => $slug,
            'board_id' => $validated['board_id'],
            'board_name' => $validated['board_name'],
            'template' => $validated['template'],
            'colors' => $validated['colors'] ?? ['background' => '#1a1a2e', 'text' => '#ffffff'],
            'language' => $validated['language'],
            'interest' => $validated['interest'] ?? null,
        ]);

        // Attach WordPress categories
        if (! empty($validated['wp_categories'])) {
            $this->syncCategories($feed, $validated['wp_categories']);
        }

        return back()->with('success', "Flux Pinterest \"{$feed->name}\" créé. URL: {$feed->feed_url}");
    }

    public function update(Request $request, PinterestFeed $feed): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'board_id' => 'nullable|string|max:255',
            'board_name' => 'nullable|string|max:255',
            'template' => 'required|string|in:overlay,split,bold_text,numbered',
            'colors' => 'nullable|array',
            'colors.background' => 'nullable|string|max:7',
            'colors.text' => 'nullable|string|max:7',
            'language' => 'required|string|max:10',
            'interest' => 'nullable|string|max:50',
            'wp_categories' => 'nullable|array',
        ]);

        $feed->update([
            'name' => $validated['name'],
            'board_id' => $validated['board_id'],
            'board_name' => $validated['board_name'],
            'template' => $validated['template'],
            'colors' => $validated['colors'] ?? $feed->colors,
            'language' => $validated['language'],
            'interest' => $validated['interest'] ?? $feed->interest,
        ]);

        if (isset($validated['wp_categories'])) {
            $this->syncCategories($feed, $validated['wp_categories']);
        }

        return back()->with('success', "Flux \"{$feed->name}\" mis à jour.");
    }

    public function destroy(PinterestFeed $feed): RedirectResponse
    {
        $name = $feed->name;
        $feed->delete();

        return back()->with('success', "Flux \"{$name}\" supprimé.");
    }

    /**
     * AJAX: Get boards for a Pinterest account.
     */
    public function boards(Request $request): JsonResponse
    {
        try {
            $account = SocialAccount::findOrFail($request->input('social_account_id'));
            $service = new PinterestApiService;
            $boards = $service->getBoards($account);

            return response()->json($boards);
        } catch (\Exception $e) {
            Log::error('Pinterest boards fetch failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Impossible de récupérer les tableaux Pinterest.'], 500);
        }
    }

    /**
     * AJAX: Generate pins for a feed from WordPress items.
     */
    public function generatePins(Request $request, PinterestFeed $feed): JsonResponse
    {
        $categories = DB::table('pinterest_feed_wp_category')
            ->where('pinterest_feed_id', $feed->id)
            ->get();

        if ($categories->isEmpty()) {
            return response()->json(['error' => 'Aucune catégorie WordPress configurée.'], 422);
        }

        // Get WordPress items matching the feed categories
        $wpSourceIds = $categories->pluck('wp_source_id')->unique();
        $wpCategoryIds = $categories->pluck('wp_category_id')->toArray();

        $items = WpItem::whereIn('wp_source_id', $wpSourceIds)
            ->orderByDesc('published_at')
            ->get();

        // Filter items by category (stored in the WP source's categories JSON)
        // Since WP items don't have a direct category column, we include all items from matching sources
        $newPins = 0;

        foreach ($items as $item) {
            // Check if pin already exists for this item + feed (any version)
            $existingVersions = PinterestPin::where('pinterest_feed_id', $feed->id)
                ->where('source_type', 'wp_item')
                ->where('source_id', $item->id)
                ->count();

            if ($existingVersions > 0) {
                continue;
            }

            PinterestPin::create([
                'pinterest_feed_id' => $feed->id,
                'source_type' => 'wp_item',
                'source_id' => $item->id,
                'guid' => 'pin-wp-' . $item->id . '-v1',
                'version' => 1,
                'title_original' => $item->title,
                'link_url' => $item->url,
                'source_image_url' => $item->image_url,
                'template' => $feed->template,
                'status' => 'pending',
            ]);

            $newPins++;
        }

        return response()->json([
            'created' => $newPins,
            'total' => $feed->pins()->count(),
        ]);
    }

    /**
     * AJAX: Generate image + AI title for a specific pin.
     */
    public function generatePinImage(Request $request, PinterestPin $pin): JsonResponse
    {
        $feed = $pin->feed;

        // Fetch Pinterest trending keywords for this feed's interest/region
        $trendingKeywords = $this->fetchTrendingKeywords($feed);

        // Generate AI title + description with trends context
        $aiContent = $this->generateAiContent($pin->title_original, $feed->template, $feed->language, $trendingKeywords);
        if (! $aiContent || ! $aiContent['title']) {
            $pin->update(['status' => 'failed', 'error_message' => 'Échec génération contenu IA']);

            return response()->json(['error' => 'AI content generation failed'], 500);
        }

        // Generate image
        $imageService = new PinterestImageService;
        $imagePath = $imageService->generate(
            $feed->template,
            $aiContent['title'],
            $pin->source_image_url,
            $feed->colors ?? [],
            $this->extractNumber($aiContent['title']),
        );

        if (! $imagePath) {
            $pin->update(['status' => 'failed', 'error_message' => 'Échec génération image']);

            return response()->json(['error' => 'Image generation failed'], 500);
        }

        $pin->update([
            'title_generated' => $aiContent['title'],
            'description' => $aiContent['description'],
            'generated_image_path' => $imagePath,
            'status' => 'generated',
        ]);

        return response()->json([
            'title' => $aiContent['title'],
            'description' => $aiContent['description'],
            'image_url' => $pin->generated_image_url,
            'status' => 'generated',
        ]);
    }

    /**
     * AJAX: Add a pin to the RSS feed.
     */
    public function addToFeed(PinterestPin $pin): JsonResponse
    {
        if ($pin->status !== 'generated') {
            return response()->json(['error' => 'Le pin doit être généré avant d\'être ajouté au flux.'], 422);
        }

        $pin->update([
            'status' => 'in_feed',
            'added_to_feed_at' => now(),
        ]);

        return response()->json(['status' => 'in_feed']);
    }

    /**
     * AJAX: Create a new version (repost) of an existing pin.
     */
    public function repost(PinterestPin $pin): JsonResponse
    {
        $feed = $pin->feed;

        $maxVersion = PinterestPin::where('pinterest_feed_id', $feed->id)
            ->where('source_type', $pin->source_type)
            ->where('source_id', $pin->source_id)
            ->max('version');

        $newVersion = ($maxVersion ?? 0) + 1;

        $newPin = PinterestPin::create([
            'pinterest_feed_id' => $feed->id,
            'source_type' => $pin->source_type,
            'source_id' => $pin->source_id,
            'guid' => "pin-{$pin->source_type}-{$pin->source_id}-v{$newVersion}",
            'version' => $newVersion,
            'title_original' => $pin->title_original,
            'link_url' => $pin->link_url,
            'source_image_url' => $pin->source_image_url,
            'template' => $feed->template,
            'status' => 'pending',
        ]);

        return response()->json([
            'pin_id' => $newPin->id,
            'version' => $newVersion,
        ]);
    }

    /**
     * AJAX: Batch generate images for all pending pins in a feed.
     */
    public function batchGenerate(PinterestFeed $feed): JsonResponse
    {
        $pendingPins = $feed->pins()->where('status', 'pending')->limit(10)->get();
        $results = ['success' => 0, 'failed' => 0];

        // Fetch trends once for the entire batch
        $trendingKeywords = $this->fetchTrendingKeywords($feed);

        $imageService = new PinterestImageService;

        foreach ($pendingPins as $pin) {
            $aiContent = $this->generateAiContent($pin->title_original, $feed->template, $feed->language, $trendingKeywords);
            if (! $aiContent || ! $aiContent['title']) {
                $pin->update(['status' => 'failed', 'error_message' => 'Échec contenu IA']);
                $results['failed']++;

                continue;
            }

            $imagePath = $imageService->generate(
                $feed->template,
                $aiContent['title'],
                $pin->source_image_url,
                $feed->colors ?? [],
                $this->extractNumber($aiContent['title']),
            );

            if (! $imagePath) {
                $pin->update(['status' => 'failed', 'error_message' => 'Échec image']);
                $results['failed']++;

                continue;
            }

            $pin->update([
                'title_generated' => $aiContent['title'],
                'description' => $aiContent['description'],
                'generated_image_path' => $imagePath,
                'status' => 'generated',
            ]);

            $results['success']++;
        }

        return response()->json($results);
    }

    /**
     * Serve the RSS XML feed (public route, no auth).
     */
    public function serveFeed(string $slug): Response
    {
        $feed = PinterestFeed::where('slug', $slug)->where('is_active', true)->firstOrFail();

        $pins = $feed->pins()
            ->where('status', 'in_feed')
            ->whereNotNull('generated_image_path')
            ->whereNotNull('title_generated')
            ->orderByDesc('added_to_feed_at')
            ->get();

        $xml = $this->buildRssXml($feed, $pins);

        return response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * AJAX: List pins for a feed.
     */
    public function pins(PinterestFeed $feed): JsonResponse
    {
        $pins = $feed->pins()
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($pin) => [
                'id' => $pin->id,
                'title_original' => $pin->title_original,
                'title_generated' => $pin->title_generated,
                'description' => $pin->description,
                'link_url' => $pin->link_url,
                'source_image_url' => $pin->source_image_url,
                'generated_image_url' => $pin->generated_image_url,
                'template' => $pin->template,
                'version' => $pin->version,
                'status' => $pin->status,
                'error_message' => $pin->error_message,
                'added_to_feed_at' => $pin->added_to_feed_at?->format('d/m/Y H:i'),
                'created_at' => $pin->created_at->format('d/m/Y H:i'),
            ]);

        return response()->json($pins);
    }

    private function syncCategories(PinterestFeed $feed, array $categories): void
    {
        DB::table('pinterest_feed_wp_category')
            ->where('pinterest_feed_id', $feed->id)
            ->delete();

        foreach ($categories as $cat) {
            if (empty($cat['wp_source_id']) || empty($cat['wp_category_id'])) {
                continue;
            }

            DB::table('pinterest_feed_wp_category')->insert([
                'pinterest_feed_id' => $feed->id,
                'wp_source_id' => $cat['wp_source_id'],
                'wp_category_id' => $cat['wp_category_id'],
                'wp_category_name' => $cat['wp_category_name'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function fetchTrendingKeywords(PinterestFeed $feed): array
    {
        $account = $feed->socialAccount;
        if (! $account) {
            return [];
        }

        $cacheKey = "pinterest_trends_{$feed->language}_{$feed->interest}";
        $cached = cache($cacheKey);
        if ($cached) {
            return $cached;
        }

        $service = new PinterestApiService;
        $keywords = $service->getTrendingKeywords($account, $feed->language, $feed->interest, 'growing', 15);

        // Also fetch monthly trends and merge
        if ($feed->interest) {
            $monthly = $service->getTrendingKeywords($account, $feed->language, $feed->interest, 'monthly', 10);
            $keywords = array_unique(array_merge($keywords, $monthly));
        }

        // Cache for 6 hours (trends don't change that fast)
        if (! empty($keywords)) {
            cache([$cacheKey => $keywords], now()->addHours(6));
        }

        return $keywords;
    }

    private function generateAiContent(string $originalTitle, string $template, string $language, array $trendingKeywords = []): ?array
    {
        $maxTitleChars = match ($template) {
            'bold_text' => 60,
            'numbered' => 80,
            'overlay' => 70,
            'split' => 70,
            default => 70,
        };

        $langName = match ($language) {
            'fr' => 'français',
            'en' => 'anglais',
            'es' => 'espagnol',
            'de' => 'allemand',
            'it' => 'italien',
            'pt' => 'portugais',
            default => $language,
        };

        $prompt = "Transforme ce titre d'article en contenu Pinterest optimisé pour le clic.\n\n";
        $prompt .= "Titre original : {$originalTitle}\n\n";

        // Inject trending keywords if available
        if (! empty($trendingKeywords)) {
            $trendsStr = implode(', ', array_slice($trendingKeywords, 0, 20));
            $prompt .= "Tendances Pinterest actuelles en croissance : {$trendsStr}\n";
            $prompt .= "Si certains de ces mots-clés sont pertinents pour l'article, intègre-les naturellement dans le titre et/ou la description pour maximiser la visibilité.\n\n";
        }

        $prompt .= "Génère un JSON avec deux champs :\n";
        $prompt .= "1. \"title\" : titre Pinterest accrocheur ({$maxTitleChars} caractères max)\n";
        $prompt .= "2. \"description\" : description Pinterest SEO (150-300 caractères) avec des mots-clés pertinents et 3-5 hashtags\n\n";
        $prompt .= "Règles :\n";
        $prompt .= "- Langue : {$langName}\n";
        $prompt .= "- Titre : utilise des chiffres si possible (ex: '7 façons de...'), accrocheur\n";
        $prompt .= "- Description : informative, inclut des mots-clés pour le SEO Pinterest, termine par des hashtags pertinents\n";
        $prompt .= "- Réponds UNIQUEMENT avec le JSON valide, rien d'autre";

        try {
            $response = Http::withToken(config('services.openai.api_key'))
                ->timeout(20)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 300,
                    'temperature' => 0.8,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $content = trim($response->json('choices.0.message.content', ''));
            // Clean markdown code blocks if present
            $content = preg_replace('/^```json?\s*/i', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);

            $parsed = json_decode($content, true);
            if (! $parsed || empty($parsed['title'])) {
                // Fallback: treat entire response as title, no description
                $title = trim($content, '"\' ');

                return [
                    'title' => mb_strlen($title) <= $maxTitleChars + 10 ? $title : mb_substr($title, 0, $maxTitleChars),
                    'description' => null,
                ];
            }

            $title = trim($parsed['title'], '"\'');
            $title = mb_strlen($title) <= $maxTitleChars + 10 ? $title : mb_substr($title, 0, $maxTitleChars);
            $description = isset($parsed['description']) ? mb_substr(trim($parsed['description']), 0, 500) : null;

            return [
                'title' => $title,
                'description' => $description,
            ];
        } catch (\Exception $e) {
            Log::error('Pinterest AI content generation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function extractNumber(string $title): ?string
    {
        if (preg_match('/^(\d+)/', $title, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function buildRssXml(PinterestFeed $feed, $pins): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">' . "\n";
        $xml .= "  <channel>\n";
        $xml .= '    <title>' . htmlspecialchars($feed->name) . "</title>\n";
        $xml .= '    <link>' . htmlspecialchars(config('app.url')) . "</link>\n";
        $xml .= '    <description>Pinterest feed: ' . htmlspecialchars($feed->name) . "</description>\n";
        $xml .= '    <language>' . htmlspecialchars($feed->language) . "</language>\n";

        foreach ($pins as $pin) {
            $imageUrl = $pin->generated_image_url;
            if (! $imageUrl) {
                continue;
            }

            $xml .= "    <item>\n";
            $xml .= '      <title>' . htmlspecialchars($pin->title_generated) . "</title>\n";
            $xml .= '      <link>' . htmlspecialchars($pin->link_url) . "</link>\n";
            $xml .= '      <guid isPermaLink="false">' . htmlspecialchars($pin->guid) . "</guid>\n";

            if ($pin->description) {
                $xml .= '      <description>' . htmlspecialchars($pin->description) . "</description>\n";
            }

            $xml .= '      <enclosure url="' . htmlspecialchars($imageUrl) . '" type="image/jpeg" />' . "\n";
            $xml .= '      <media:content url="' . htmlspecialchars($imageUrl) . '" medium="image" type="image/jpeg" />' . "\n";

            if ($pin->added_to_feed_at) {
                $xml .= '      <pubDate>' . $pin->added_to_feed_at->toRfc2822String() . "</pubDate>\n";
            }

            $xml .= "    </item>\n";
        }

        $xml .= "  </channel>\n";
        $xml .= "</rss>\n";

        return $xml;
    }
}
