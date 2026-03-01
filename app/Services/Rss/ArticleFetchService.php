<?php

namespace App\Services\Rss;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ArticleFetchService
{
    /**
     * Fetch the article content from a URL.
     * Extracts the main text content, stripping navigation, ads, etc.
     * Returns null if the article cannot be fetched.
     */
    public function fetchArticleContent(string $url): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; RS-Max/1.0; +https://rs-max.app)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);

            if (! $response->successful()) {
                Log::info('ArticleFetchService: HTTP error', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $html = $response->body();

            return $this->extractContent($html);

        } catch (\Exception $e) {
            Log::info('ArticleFetchService: Exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fetch the page title from a URL (lightweight, reads only first 10KB).
     */
    public function fetchPageTitle(string $url): ?string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; RS-Max/1.0; +https://rs-max.app)',
                    'Accept' => 'text/html',
                    'Range' => 'bytes=0-10240',
                ])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            // Extract <title> tag
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
                $title = html_entity_decode(strip_tags(trim($matches[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Remove site name suffix (e.g. "Article Title | Site Name" or "Article Title - Site Name")
                $title = preg_replace('/\s*[\|–—-]\s*[^|–—-]+$/', '', $title);

                return trim($title) ?: null;
            }

            // Fallback: try og:title
            if (preg_match('/<meta\s+(?:property|name)=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
                return html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Fetch both title and content from a URL.
     * Returns ['title' => ..., 'content' => ...].
     */
    public function fetchPageMeta(string $url): array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; RS-Max/1.0; +https://rs-max.app)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);

            if (! $response->successful()) {
                return ['title' => null, 'content' => null];
            }

            $html = $response->body();

            $title = null;
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
                $title = html_entity_decode(strip_tags(trim($matches[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $title = preg_replace('/\s*[\|–—-]\s*[^|–—-]+$/', '', $title);
                $title = trim($title) ?: null;
            }

            // Extract og:image
            $image = null;
            if (preg_match('/<meta\s+(?:property|name)=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $imgMatch)) {
                $image = trim($imgMatch[1]);
            } elseif (preg_match('/<meta\s+content=["\']([^"\']+)["\']\s+(?:property|name)=["\']og:image["\']/i', $html, $imgMatch)) {
                $image = trim($imgMatch[1]);
            }

            return [
                'title' => $title,
                'content' => $this->extractContent($html),
                'image' => $image,
            ];
        } catch (\Exception $e) {
            return ['title' => null, 'content' => null, 'image' => null];
        }
    }

    /**
     * Extract main content from HTML.
     * Uses a simple heuristic approach: look for <article>, <main>, or the largest text block.
     */
    private function extractContent(string $html): ?string
    {
        // Remove scripts, styles, nav, header, footer, aside
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<nav\b[^>]*>.*?<\/nav>/is', '', $html);
        $html = preg_replace('/<header\b[^>]*>.*?<\/header>/is', '', $html);
        $html = preg_replace('/<footer\b[^>]*>.*?<\/footer>/is', '', $html);
        $html = preg_replace('/<aside\b[^>]*>.*?<\/aside>/is', '', $html);

        // Try to find <article> content first
        if (preg_match('/<article\b[^>]*>(.*?)<\/article>/is', $html, $matches)) {
            $content = $matches[1];
        }
        // Try <main>
        elseif (preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $matches)) {
            $content = $matches[1];
        }
        // Try common content class names
        elseif (preg_match('/<div\b[^>]*class="[^"]*(?:post-content|entry-content|article-content|content-body|story-body)[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
            $content = $matches[1];
        }
        // Fallback: use the <body> content
        elseif (preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            $content = $matches[1];
        } else {
            $content = $html;
        }

        // Strip remaining HTML tags, keeping paragraph breaks
        $content = preg_replace('/<br\s*\/?>/i', "\n", $content);
        $content = preg_replace('/<\/p>/i', "\n\n", $content);
        $content = preg_replace('/<\/h[1-6]>/i', "\n\n", $content);
        $content = preg_replace('/<\/li>/i', "\n", $content);
        $content = strip_tags($content);

        // Clean up whitespace
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        $content = trim($content);

        // Truncate to 10k chars max
        if (mb_strlen($content) > 10000) {
            $content = mb_substr($content, 0, 10000).'...';
        }

        // If content is too short, it's probably not useful
        if (mb_strlen($content) < 50) {
            return null;
        }

        return $content;
    }
}
