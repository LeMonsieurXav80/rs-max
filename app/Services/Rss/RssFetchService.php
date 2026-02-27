<?php

namespace App\Services\Rss;

use App\Models\RssFeed;
use App\Models\RssItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RssFetchService
{
    /**
     * Fetch a single RSS feed and upsert new items.
     * Returns the number of new items added.
     */
    public function fetchFeed(RssFeed $feed): int
    {
        try {
            // Multi-part sitemap: fetch numbered parts sequentially
            if ($feed->is_multi_part_sitemap) {
                return $this->fetchMultiPartSitemap($feed);
            }

            return $this->fetchSingleUrl($feed, $feed->url);

        } catch (\Exception $e) {
            Log::error('RssFetchService: Exception', [
                'feed' => $feed->name,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Fetch a single URL and upsert items.
     */
    private function fetchSingleUrl(RssFeed $feed, string $url): int
    {
        $response = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'RS-Max/1.0 RSS Reader'])
            ->get($url);

        if (! $response->successful()) {
            Log::warning('RssFetchService: HTTP error', [
                'feed' => $feed->name,
                'url' => $url,
                'status' => $response->status(),
            ]);

            return 0;
        }

        $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

        if (! $xml) {
            Log::warning('RssFetchService: Invalid XML', ['feed' => $feed->name, 'url' => $url]);

            return 0;
        }

        $items = $this->parseItems($xml, $feed);

        return $this->upsertItems($feed, $items);
    }

    /**
     * Upsert parsed items into the database.
     */
    private function upsertItems(RssFeed $feed, array $items): int
    {
        $newCount = 0;

        foreach ($items as $item) {
            $created = RssItem::firstOrCreate(
                [
                    'rss_feed_id' => $feed->id,
                    'guid' => $item['guid'],
                ],
                array_merge($item, [
                    'rss_feed_id' => $feed->id,
                    'fetched_at' => now(),
                ])
            );

            if ($created->wasRecentlyCreated) {
                $newCount++;
            }
        }

        $feed->update(['last_fetched_at' => now()]);

        Log::info('RssFetchService: Feed fetched', [
            'feed' => $feed->name,
            'total_items' => count($items),
            'new_items' => $newCount,
        ]);

        return $newCount;
    }

    /**
     * Fetch multi-part sitemaps (e.g. post-sitemap1.xml, post-sitemap2.xml, ...).
     */
    private function fetchMultiPartSitemap(RssFeed $feed): int
    {
        // Match pattern: https://example.com/post-sitemap1.xml
        if (! preg_match('/^(.+?)(\d+)(\.xml)$/i', $feed->url, $matches)) {
            // URL doesn't match numbered pattern, fall back to single fetch
            return $this->fetchSingleUrl($feed, $feed->url);
        }

        $base = $matches[1];
        $startNum = (int) $matches[2];
        $ext = $matches[3];
        $totalNew = 0;

        for ($n = $startNum; ; $n++) {
            $url = $base.$n.$ext;

            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'RS-Max/1.0 RSS Reader'])
                ->get($url);

            if (! $response->successful()) {
                Log::info('RssFetchService: Multi-part sitemap ended', [
                    'feed' => $feed->name,
                    'last_part' => $n - 1,
                    'stopped_at' => $url,
                ]);
                break;
            }

            $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
            if (! $xml) {
                break;
            }

            $items = $this->parseItems($xml, $feed);
            $newCount = $this->upsertItems($feed, $items);
            $totalNew += $newCount;

            Log::info('RssFetchService: Multi-part sitemap part fetched', [
                'feed' => $feed->name,
                'part' => $n,
                'url' => $url,
                'new_items' => $newCount,
            ]);
        }

        return $totalNew;
    }

    /**
     * Fetch all active feeds.
     * Returns array of [feed_name => new_count].
     */
    public function fetchAllActiveFeeds(): array
    {
        $results = [];
        $feeds = RssFeed::where('is_active', true)->get();

        foreach ($feeds as $feed) {
            $results[$feed->name] = $this->fetchFeed($feed);
        }

        return $results;
    }

    /**
     * Parse RSS/Atom/Sitemap items from XML.
     */
    private function parseItems(\SimpleXMLElement $xml, RssFeed $feed): array
    {
        $items = [];

        // Sitemap index format (<sitemapindex> with <sitemap><loc>)
        if ($xml->getName() === 'sitemapindex' || isset($xml->sitemap)) {
            return $this->parseSitemapIndex($xml, $feed);
        }

        // Sitemap format (<urlset> with <url><loc>)
        if ($xml->getName() === 'urlset' || isset($xml->url)) {
            return $this->parseSitemapUrls($xml);
        }

        // RSS 2.0 format
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $entry) {
                $items[] = $this->parseRssItem($entry);
            }

            return $items;
        }

        // Atom format
        $namespaces = $xml->getNamespaces(true);
        if (isset($xml->entry) || (isset($namespaces['']) && str_contains($namespaces[''], 'Atom'))) {
            foreach ($xml->entry as $entry) {
                $items[] = $this->parseAtomEntry($entry, $namespaces);
            }

            return $items;
        }

        // RSS 1.0 / RDF format
        if (isset($xml->item)) {
            foreach ($xml->item as $entry) {
                $items[] = $this->parseRssItem($entry);
            }
        }

        return $items;
    }

    private function parseRssItem(\SimpleXMLElement $entry): array
    {
        $guid = (string) ($entry->guid ?: $entry->link ?: md5((string) $entry->title));
        $content = '';

        // Try content:encoded first (full article), then description
        $namespaces = $entry->getNamespaces(true);
        if (isset($namespaces['content'])) {
            $contentNs = $entry->children($namespaces['content']);
            if (isset($contentNs->encoded)) {
                $content = (string) $contentNs->encoded;
            }
        }

        $description = (string) ($entry->description ?? '');

        // Extract image from media:content, media:thumbnail, or enclosure
        $imageUrl = null;
        if (isset($namespaces['media'])) {
            $media = $entry->children($namespaces['media']);
            if (isset($media->content)) {
                $attrs = $media->content->attributes();
                if ($attrs && str_starts_with((string) ($attrs['type'] ?? ''), 'image/')) {
                    $imageUrl = (string) $attrs['url'];
                }
            }
            if (! $imageUrl && isset($media->thumbnail)) {
                $attrs = $media->thumbnail->attributes();
                if ($attrs) {
                    $imageUrl = (string) $attrs['url'];
                }
            }
        }
        if (! $imageUrl && isset($entry->enclosure)) {
            $attrs = $entry->enclosure->attributes();
            if ($attrs && str_starts_with((string) ($attrs['type'] ?? ''), 'image/')) {
                $imageUrl = (string) $attrs['url'];
            }
        }

        $publishedAt = null;
        $pubDate = (string) ($entry->pubDate ?? '');
        if ($pubDate) {
            try {
                $publishedAt = \Carbon\Carbon::parse($pubDate);
            } catch (\Exception $e) {
                // ignore bad dates
            }
        }

        return [
            'guid' => $guid,
            'title' => strip_tags((string) ($entry->title ?? '')),
            'url' => (string) ($entry->link ?? ''),
            'content' => $content ?: null,
            'summary' => $description ? strip_tags($description) : null,
            'author' => strip_tags((string) ($entry->author ?? $this->getDcCreator($entry) ?? '')),
            'image_url' => $imageUrl,
            'published_at' => $publishedAt,
        ];
    }

    private function parseAtomEntry(\SimpleXMLElement $entry, array $namespaces): array
    {
        $guid = (string) ($entry->id ?? '');

        // Get link href
        $url = '';
        foreach ($entry->link as $link) {
            $attrs = $link->attributes();
            $rel = (string) ($attrs['rel'] ?? 'alternate');
            if ($rel === 'alternate' || $rel === '') {
                $url = (string) ($attrs['href'] ?? '');
                break;
            }
        }
        if (! $url && isset($entry->link)) {
            $attrs = $entry->link->attributes();
            $url = (string) ($attrs['href'] ?? '');
        }

        if (! $guid) {
            $guid = $url ?: md5((string) ($entry->title ?? ''));
        }

        // Content
        $content = (string) ($entry->content ?? '');
        $summary = (string) ($entry->summary ?? '');

        $publishedAt = null;
        $dateStr = (string) ($entry->published ?? $entry->updated ?? '');
        if ($dateStr) {
            try {
                $publishedAt = \Carbon\Carbon::parse($dateStr);
            } catch (\Exception $e) {
                // ignore
            }
        }

        // Author
        $author = '';
        if (isset($entry->author->name)) {
            $author = (string) $entry->author->name;
        }

        return [
            'guid' => $guid,
            'title' => strip_tags((string) ($entry->title ?? '')),
            'url' => $url,
            'content' => $content ?: null,
            'summary' => $summary ? strip_tags($summary) : null,
            'author' => $author,
            'image_url' => null,
            'published_at' => $publishedAt,
        ];
    }

    /**
     * Parse sitemap index: follows each sub-sitemap and collects URLs.
     */
    private function parseSitemapIndex(\SimpleXMLElement $xml, RssFeed $feed): array
    {
        $allItems = [];
        $namespaces = $xml->getNamespaces(true);
        $children = $xml->children($namespaces[''] ?? '');

        foreach ($children->sitemap ?? $xml->sitemap as $sitemap) {
            $loc = (string) ($sitemap->loc ?? '');
            if (! $loc) {
                continue;
            }

            try {
                $response = Http::timeout(30)
                    ->withHeaders(['User-Agent' => 'RS-Max/1.0 RSS Reader'])
                    ->get($loc);

                if (! $response->successful()) {
                    continue;
                }

                $subXml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
                if (! $subXml) {
                    continue;
                }

                $subItems = $this->parseItems($subXml, $feed);
                $allItems = array_merge($allItems, $subItems);

                // Cap at 500 URLs total across all sub-sitemaps
                if (count($allItems) >= 500) {
                    $allItems = array_slice($allItems, 0, 500);
                    break;
                }
            } catch (\Exception $e) {
                Log::warning('RssFetchService: Sitemap index sub-fetch failed', [
                    'url' => $loc,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $allItems;
    }

    /**
     * Parse sitemap <urlset> format.
     */
    private function parseSitemapUrls(\SimpleXMLElement $xml): array
    {
        $items = [];
        $namespaces = $xml->getNamespaces(true);
        $children = $xml->children($namespaces[''] ?? '');

        $urls = $children->url ?? $xml->url;

        foreach ($urls as $urlNode) {
            $loc = (string) ($urlNode->loc ?? '');
            if (! $loc) {
                continue;
            }

            // Skip common non-article URLs
            if ($this->shouldSkipSitemapUrl($loc)) {
                continue;
            }

            $publishedAt = null;
            $lastmod = (string) ($urlNode->lastmod ?? '');
            if ($lastmod) {
                try {
                    $publishedAt = \Carbon\Carbon::parse($lastmod);
                } catch (\Exception $e) {
                    // ignore
                }
            }

            $items[] = [
                'guid' => $loc,
                'title' => $this->titleFromUrl($loc),
                'url' => $loc,
                'content' => null,
                'summary' => null,
                'author' => null,
                'image_url' => null,
                'published_at' => $publishedAt,
            ];

            // Cap at 500 URLs per sitemap
            if (count($items) >= 500) {
                break;
            }
        }

        return $items;
    }

    /**
     * Derive a readable title from a URL path.
     * e.g. https://example.com/blog/my-great-article → "My Great Article"
     */
    private function titleFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $path = rtrim($path, '/');

        // Get last path segment
        $segment = basename($path);

        // Remove file extension
        $segment = preg_replace('/\.\w+$/', '', $segment);

        // Replace hyphens/underscores with spaces
        $segment = str_replace(['-', '_'], ' ', $segment);

        // Title case
        $title = mb_convert_case(trim($segment), MB_CASE_TITLE);

        return $title ?: parse_url($url, PHP_URL_HOST) ?? $url;
    }

    /**
     * Skip common non-article sitemap URLs.
     */
    private function shouldSkipSitemapUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';

        // Skip root, very short paths, and common non-article pages
        if ($path === '/' || $path === '') {
            return true;
        }

        $skipPatterns = [
            '/wp-login',
            '/wp-admin',
            '/feed',
            '/login',
            '/register',
            '/cart',
            '/checkout',
            '/account',
            '/search',
        ];

        foreach ($skipPatterns as $pattern) {
            if (str_starts_with($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function getDcCreator(\SimpleXMLElement $entry): ?string
    {
        $namespaces = $entry->getNamespaces(true);
        if (isset($namespaces['dc'])) {
            $dc = $entry->children($namespaces['dc']);
            if (isset($dc->creator)) {
                return (string) $dc->creator;
            }
        }

        return null;
    }
}
