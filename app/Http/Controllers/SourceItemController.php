<?php

namespace App\Http\Controllers;

use App\Models\RedditItem;
use App\Models\RedditSource;
use App\Models\RssFeed;
use App\Models\RssItem;
use App\Models\WpItem;
use App\Models\WpSource;
use App\Models\YtItem;
use App\Models\YtSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SourceItemController extends Controller
{
    public function sources(Request $request): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $request->validate([
            'type' => 'required|in:rss,wordpress,youtube,reddit',
        ]);

        $type = $request->input('type');

        $sources = match ($type) {
            'rss' => RssFeed::where('is_active', true)->orderBy('name')->get(['id', 'name', 'url', 'category']),
            'wordpress' => WpSource::where('is_active', true)->orderBy('name')->get(['id', 'name', 'url']),
            'youtube' => YtSource::where('is_active', true)->orderBy('name')->get(['id', 'name', 'channel_name', 'thumbnail_url']),
            'reddit' => RedditSource::where('is_active', true)->orderBy('name')->get(['id', 'name', 'subreddit']),
        };

        return response()->json([
            'sources' => $sources->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'subtitle' => match ($type) {
                    'rss' => $s->category ?? $s->url,
                    'wordpress' => $s->url,
                    'youtube' => $s->channel_name ?? '',
                    'reddit' => 'r/' . $s->subreddit,
                },
            ]),
        ]);
    }

    public function items(Request $request): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $request->validate([
            'type' => 'required|in:rss,wordpress,youtube,reddit',
            'source_id' => 'required|integer',
            'search' => 'nullable|string|max:255',
        ]);

        $type = $request->input('type');
        $sourceId = $request->input('source_id');
        $search = $request->input('search');

        $query = match ($type) {
            'rss' => RssItem::where('rss_feed_id', $sourceId),
            'wordpress' => WpItem::where('wp_source_id', $sourceId),
            'youtube' => YtItem::where('yt_source_id', $sourceId),
            'reddit' => RedditItem::where('reddit_source_id', $sourceId),
        };

        if ($search) {
            $query->where('title', 'like', '%' . $search . '%');
        }

        $items = $query->orderByDesc('published_at')->limit(50)->get();

        $mapped = $items->map(fn ($item) => [
            'id' => $item->id,
            'title' => $item->title,
            'url' => $item->url,
            'image_url' => match ($type) {
                'rss', 'wordpress' => $item->image_url ?? null,
                'youtube' => $item->thumbnail_url ?? null,
                'reddit' => $item->thumbnail_url ?? null,
            },
            'published_at' => $item->published_at?->format('d/m/Y'),
            'extra' => match ($type) {
                'youtube' => $item->view_count ? number_format($item->view_count) . ' vues' : null,
                'reddit' => $item->score ? $item->score . ' pts, ' . $item->num_comments . ' com.' : null,
                default => $item->author ?? null,
            },
        ]);

        return response()->json(['items' => $mapped]);
    }
}
