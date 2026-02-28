<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use App\Models\RssFeed;
use App\Models\SocialAccount;
use App\Services\Rss\RssFetchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class RssFeedController extends Controller
{
    public function index(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $feeds = RssFeed::withCount('rssItems')
            ->with('socialAccounts.platform')
            ->orderBy('name')
            ->get();

        return view('rss.index', compact('feeds'));
    }

    public function create(Request $request): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $accounts = SocialAccount::with('platform')
            ->orderBy('name')
            ->get();

        $personas = Persona::where('is_active', true)->orderBy('name')->get();

        return view('rss.create', compact('accounts', 'personas'));
    }

    public function store(Request $request)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2000',
            'description' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:100',
            'is_active' => 'boolean',
            'is_multi_part_sitemap' => 'boolean',
            'accounts' => 'nullable|array',
            'accounts.*.id' => 'exists:social_accounts,id',
            'accounts.*.persona_id' => 'nullable|exists:personas,id',
            'accounts.*.auto_post' => 'boolean',
            'accounts.*.post_frequency' => 'in:hourly,every_6h,daily,weekly',
            'accounts.*.max_posts_per_day' => 'integer|min:1|max:10',
        ]);

        $feed = RssFeed::create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'is_multi_part_sitemap' => $request->boolean('is_multi_part_sitemap', false),
        ]);

        // Sync accounts with pivot data
        if (! empty($validated['accounts'])) {
            $syncData = [];
            foreach ($validated['accounts'] as $accountData) {
                $syncData[$accountData['id']] = [
                    'persona_id' => $accountData['persona_id'] ?? null,
                    'auto_post' => $accountData['auto_post'] ?? false,
                    'post_frequency' => $accountData['post_frequency'] ?? 'daily',
                    'max_posts_per_day' => $accountData['max_posts_per_day'] ?? 1,
                ];
            }
            $feed->socialAccounts()->sync($syncData);
        }

        return redirect()->route('rss-feeds.index')->with('status', 'feed-created');
    }

    public function edit(Request $request, RssFeed $rssFeed): View
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $rssFeed->load('socialAccounts');

        $accounts = SocialAccount::with('platform')
            ->orderBy('name')
            ->get();

        $personas = Persona::where('is_active', true)->orderBy('name')->get();

        // Build linked accounts data for the form
        $linkedAccounts = [];
        foreach ($rssFeed->socialAccounts as $account) {
            $linkedAccounts[$account->id] = [
                'persona_id' => $account->pivot->persona_id,
                'auto_post' => $account->pivot->auto_post,
                'post_frequency' => $account->pivot->post_frequency,
                'max_posts_per_day' => $account->pivot->max_posts_per_day,
            ];
        }

        return view('rss.edit', compact('rssFeed', 'accounts', 'personas', 'linkedAccounts'));
    }

    public function update(Request $request, RssFeed $rssFeed)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2000',
            'description' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:100',
            'is_active' => 'boolean',
            'is_multi_part_sitemap' => 'boolean',
            'accounts' => 'nullable|array',
            'accounts.*.id' => 'exists:social_accounts,id',
            'accounts.*.persona_id' => 'nullable|exists:personas,id',
            'accounts.*.auto_post' => 'boolean',
            'accounts.*.post_frequency' => 'in:hourly,every_6h,daily,weekly',
            'accounts.*.max_posts_per_day' => 'integer|min:1|max:10',
        ]);

        $rssFeed->update([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'is_multi_part_sitemap' => $request->boolean('is_multi_part_sitemap', false),
        ]);

        // Sync accounts with pivot data
        $syncData = [];
        if (! empty($validated['accounts'])) {
            foreach ($validated['accounts'] as $accountData) {
                $syncData[$accountData['id']] = [
                    'persona_id' => $accountData['persona_id'] ?? null,
                    'auto_post' => $accountData['auto_post'] ?? false,
                    'post_frequency' => $accountData['post_frequency'] ?? 'daily',
                    'max_posts_per_day' => $accountData['max_posts_per_day'] ?? 1,
                ];
            }
        }
        $rssFeed->socialAccounts()->sync($syncData);

        return redirect()->route('rss-feeds.index')->with('status', 'feed-updated');
    }

    public function destroy(Request $request, RssFeed $rssFeed)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $rssFeed->delete();

        return redirect()->route('rss-feeds.index')->with('status', 'feed-deleted');
    }

    public function fetchNow(Request $request, RssFeed $rssFeed)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $service = new RssFetchService;
        $count = $service->fetchFeed($rssFeed);

        return redirect()->route('rss-feeds.index')
            ->with('status', 'feed-fetched')
            ->with('fetch_count', $count);
    }

    public function generateNow(Request $request, RssFeed $rssFeed)
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        Artisan::call('rss:generate', ['--feed' => $rssFeed->id]);
        $output = trim(Artisan::output());

        return redirect()->route('rss-feeds.index')
            ->with('status', 'feed-generated')
            ->with('generate_output', $output);
    }
}
