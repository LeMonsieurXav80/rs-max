<?php

namespace App\Services\WordPress;

use App\Models\WpItem;
use App\Models\WpSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
class WordPressFetchService
{
    private const EXCLUDED_POST_TYPES = [
        'attachment',
        'nav_menu_item',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_navigation',
        'wp_global_styles',
        'wp_font_family',
        'wp_font_face',
        'revision',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_area',
    ];

    /**
     * Test the connection to a WordPress site and return available post types.
     */
    public function testConnection(string $url, ?string $username = null, ?string $password = null): array
    {
        $baseUrl = rtrim($url, '/');

        try {
            $request = Http::timeout(15)->acceptJson();

            if ($username && $password) {
                $request = $request->withBasicAuth($username, $password);
            }

            // Try to fetch site info
            $siteResponse = $request->get("{$baseUrl}/wp-json");

            if (! $siteResponse->successful()) {
                return [
                    'success' => false,
                    'error' => "L'API REST WordPress n'est pas accessible (HTTP {$siteResponse->status()}). Vérifiez l'URL et que l'API REST est activée.",
                ];
            }

            $siteName = $siteResponse->json('name', 'Site WordPress');

            // Fetch available post types and categories
            $postTypes = $this->fetchAvailablePostTypes($baseUrl, $username, $password);
            $categories = $this->fetchAvailableCategories($baseUrl, $username, $password);

            return [
                'success' => true,
                'site_name' => $siteName,
                'post_types' => $postTypes,
                'categories' => $categories,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'success' => false,
                'error' => "Impossible de se connecter au site. Vérifiez l'URL et que le site est accessible.",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Erreur : {$e->getMessage()}",
            ];
        }
    }

    /**
     * Fetch all available post types from a WordPress site.
     */
    public function fetchAvailablePostTypes(string $baseUrl, ?string $username = null, ?string $password = null): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        $request = Http::timeout(15)->acceptJson();

        if ($username && $password) {
            $request = $request->withBasicAuth($username, $password);
        }

        $response = $request->get("{$baseUrl}/wp-json/wp/v2/types");

        if (! $response->successful()) {
            return [];
        }

        $types = $response->json();
        $result = [];

        foreach ($types as $slug => $typeData) {
            if (in_array($slug, self::EXCLUDED_POST_TYPES)) {
                continue;
            }

            $restBase = $typeData['rest_base'] ?? null;
            if (! $restBase) {
                continue;
            }

            $result[] = [
                'slug' => $slug,
                'name' => $typeData['name'] ?? $slug,
                'rest_base' => $restBase,
                'description' => $typeData['description'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Fetch all available categories from a WordPress site.
     */
    public function fetchAvailableCategories(string $baseUrl, ?string $username = null, ?string $password = null): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        $request = Http::timeout(15)->acceptJson();

        if ($username && $password) {
            $request = $request->withBasicAuth($username, $password);
        }

        $categories = [];
        $page = 1;

        do {
            $response = $request->get("{$baseUrl}/wp-json/wp/v2/categories", [
                'per_page' => 100,
                'page' => $page,
            ]);

            if (! $response->successful()) {
                break;
            }

            $items = $response->json();

            if (empty($items)) {
                break;
            }

            foreach ($items as $cat) {
                $categories[] = [
                    'id' => $cat['id'],
                    'name' => $cat['name'] ?? '',
                    'slug' => $cat['slug'] ?? '',
                    'count' => $cat['count'] ?? 0,
                    'parent' => $cat['parent'] ?? 0,
                ];
            }

            $totalPages = (int) $response->header('X-WP-TotalPages', 1);
            $page++;
        } while ($page <= $totalPages);

        return $categories;
    }

    /**
     * Fetch content from a WordPress source for all selected post types.
     */
    public function fetchSource(WpSource $source): int
    {
        $baseUrl = rtrim($source->url, '/');
        $postTypes = $source->post_types ?? [];

        if (empty($postTypes)) {
            Log::warning('WordPressFetchService: No post types selected', ['source' => $source->name]);

            return 0;
        }

        // Get the rest_base mapping for each post type
        $typeMapping = $this->getTypeMapping($baseUrl, $source->auth_username, $source->auth_password);

        $newCount = 0;

        foreach ($postTypes as $postType) {
            $restBase = $typeMapping[$postType] ?? $postType . 's';

            try {
                $newCount += $this->fetchPostType($source, $baseUrl, $postType, $restBase);
            } catch (\Exception $e) {
                Log::error('WordPressFetchService: Error fetching post type', [
                    'source' => $source->name,
                    'post_type' => $postType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $source->update(['last_fetched_at' => now()]);

        Log::info('WordPressFetchService: Fetch complete', [
            'source' => $source->name,
            'new_items' => $newCount,
        ]);

        return $newCount;
    }

    private function fetchPostType(WpSource $source, string $baseUrl, string $postType, string $restBase): int
    {
        $page = 1;
        $newCount = 0;

        $request = Http::timeout(30)->acceptJson();

        if ($source->auth_username && $source->auth_password) {
            $request = $request->withBasicAuth($source->auth_username, $source->auth_password);
        }

        // Build query params — only published posts
        $params = [
            'per_page' => 100,
            'page' => $page,
            'status' => 'publish',
            '_embed' => 1,
        ];

        // Filter by categories if configured (applies to post type "post")
        $categories = $source->categories ?? [];
        if (! empty($categories) && $postType === 'post') {
            $params['categories'] = implode(',', $categories);
        }

        do {
            $params['page'] = $page;
            $response = $request->get("{$baseUrl}/wp-json/wp/v2/{$restBase}", $params);

            if (! $response->successful()) {
                // If auth fails (401), retry without auth — API may be public
                if ($response->status() === 401 && $source->auth_username) {
                    Log::warning('WordPressFetchService: Auth rejected, retrying without auth', [
                        'source' => $source->name,
                        'post_type' => $postType,
                    ]);
                    $request = Http::timeout(30)->acceptJson();
                    $response = $request->get("{$baseUrl}/wp-json/wp/v2/{$restBase}", $params);

                    if (! $response->successful()) {
                        break;
                    }
                } else {
                    break;
                }
            }

            $posts = $response->json();

            if (empty($posts)) {
                break;
            }

            foreach ($posts as $post) {
                $wpPostId = $post['id'] ?? null;
                if (! $wpPostId) {
                    continue;
                }

                $title = strip_tags($post['title']['rendered'] ?? '');
                $content = $this->cleanHtmlContent($post['content']['rendered'] ?? '');
                $summary = strip_tags($post['excerpt']['rendered'] ?? '');
                $url = $post['link'] ?? '';
                $publishedAt = $post['date'] ?? null;

                // Extract featured image from embedded data
                $imageUrl = null;
                $embedded = $post['_embedded'] ?? [];
                if (! empty($embedded['wp:featuredmedia'][0]['source_url'])) {
                    $imageUrl = $embedded['wp:featuredmedia'][0]['source_url'];
                }

                // Extract author from embedded data
                $author = null;
                if (! empty($embedded['author'][0]['name'])) {
                    $author = $embedded['author'][0]['name'];
                }

                $item = WpItem::updateOrCreate(
                    [
                        'wp_source_id' => $source->id,
                        'wp_post_id' => $wpPostId,
                    ],
                    [
                        'title' => $title,
                        'url' => $url,
                        'content' => $content ?: null,
                        'summary' => $summary ?: null,
                        'author' => $author,
                        'image_url' => $imageUrl,
                        'post_type' => $postType,
                        'published_at' => $publishedAt,
                        'fetched_at' => now(),
                    ]
                );

                if ($item->wasRecentlyCreated) {
                    $newCount++;
                }
            }

            $totalPages = (int) $response->header('X-WP-TotalPages', 1);
            $page++;
        } while ($page <= $totalPages);

        return $newCount;
    }

    private function getTypeMapping(string $baseUrl, ?string $username, ?string $password): array
    {
        $types = $this->fetchAvailablePostTypes($baseUrl, $username, $password);
        $mapping = [];

        foreach ($types as $type) {
            $mapping[$type['slug']] = $type['rest_base'];
        }

        return $mapping;
    }

    private function cleanHtmlContent(string $html): string
    {
        // Remove script and style tags
        $html = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/si', '', $html);

        // Convert common HTML to plain text with some structure
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<\/h[1-6]>/i', "\n\n", $html);
        $html = preg_replace('/<li[^>]*>/i', "- ", $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);

        // Strip remaining HTML tags
        $html = strip_tags($html);

        // Clean up whitespace
        $html = preg_replace('/\n{3,}/', "\n\n", $html);
        $html = trim($html);

        return html_entity_decode($html, ENT_QUOTES, 'UTF-8');
    }
}
