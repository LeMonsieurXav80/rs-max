<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportNocoDbCommand extends Command
{
    protected $signature = 'import:nocodb
        {--admin-email= : Email du compte admin}
        {--scheduled-only : Importer uniquement les posts programmés}';

    protected $description = 'Importe les clients et publications depuis NocoDB';

    private string $baseUrl;
    private string $token;
    private string $publicationsTable;
    private string $clientsTable;

    public function handle(): int
    {
        $this->baseUrl = 'https://nocodb.lemonsieurxav.com';
        $this->token = '--wNTxOjbXjgh5mxgu4za9-zGYjUvgOYLORFaec-';
        $this->publicationsTable = 'mcrulyxuizuiov1';
        $this->clientsTable = 'm8mj2qbo0u4v1z1';

        $this->info('Import NocoDB -> RS-Max');
        $this->newLine();

        // 1. Import clients -> users + social accounts
        $this->info('1/3 Import des clients...');
        $userMap = $this->importClients();

        // 2. Set admin
        $adminEmail = $this->option('admin-email');
        if ($adminEmail) {
            $admin = User::where('email', $adminEmail)->first();
            if ($admin) {
                $admin->update(['is_admin' => true]);
                $this->info("   Admin: {$admin->name} ({$admin->email})");
            }
        }

        // 3. Import publications -> posts
        $this->info('2/3 Import des publications...');
        $this->importPublications($userMap);

        $this->newLine();
        $this->info('3/3 Résumé :');
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Users créés', User::count()],
                ['Comptes sociaux', SocialAccount::count()],
                ['Posts importés', Post::count()],
            ]
        );

        $this->info('Import terminé !');
        return Command::SUCCESS;
    }

    private function importClients(): array
    {
        $response = Http::withHeaders(['xc-token' => $this->token])
            ->get("{$this->baseUrl}/api/v1/db/data/noco/ppnwfbcqzc7rc5b/{$this->clientsTable}", [
                'limit' => 100,
            ]);

        $clients = $response->json('list', []);
        $platforms = Platform::all()->keyBy('slug');
        $userMap = [];

        foreach ($clients as $client) {
            $clientId = $client['id'] ?? $client['Id'] ?? null;
            $name = $client['name'] ?? $clientId;
            if (!$clientId) continue;

            // Create user for this client
            $user = User::firstOrCreate(
                ['email' => strtolower($clientId) . '@rs-max.local'],
                [
                    'name' => $name,
                    'password' => bcrypt('changeme-' . $clientId),
                    'default_language' => $client['default_language'] ?? 'fr',
                    'auto_translate' => (bool) ($client['auto_translate'] ?? false),
                ]
            );

            $userMap[$clientId] = $user;
            $this->info("   Client: {$name} -> User #{$user->id}");

            // Import social accounts from JSON config fields
            $this->importSocialAccountsForClient($user, $client, $platforms);
        }

        return $userMap;
    }

    private function importSocialAccountsForClient(User $user, array $client, $platforms): void
    {
        $configMap = [
            'telegram' => 'telegram_configs',
            'facebook' => 'facebook_configs',
            'twitter' => 'twitter_configs',
            'instagram' => 'instagram_configs',
        ];

        $languageMap = [
            'telegram' => 'telegram_language',
            'facebook' => 'facebook_language',
            'twitter' => 'twitter_language',
            'instagram' => 'instagram_language',
        ];

        foreach ($configMap as $platformSlug => $configField) {
            if (empty($client[$configField])) continue;

            $platform = $platforms->get($platformSlug);
            if (!$platform) continue;

            $configs = is_string($client[$configField])
                ? json_decode($client[$configField], true)
                : $client[$configField];

            if (!$configs || !is_array($configs)) continue;

            $language = $client[$languageMap[$platformSlug]] ?? $client['default_language'] ?? 'fr';

            // Branding
            $branding = null;
            $showBranding = false;
            if (!empty($client['branding_configs'])) {
                $brandingConfigs = is_string($client['branding_configs'])
                    ? json_decode($client['branding_configs'], true)
                    : $client['branding_configs'];
                if ($brandingConfigs) {
                    $branding = json_encode($brandingConfigs);
                    $showBranding = true;
                }
            }

            // For Telegram, create one account per channel
            if ($platformSlug === 'telegram') {
                foreach ($configs as $channelName => $channelConfig) {
                    if (!is_array($channelConfig) || empty($channelConfig['bot_token'])) continue;

                    SocialAccount::firstOrCreate(
                        ['user_id' => $user->id, 'platform_id' => $platform->id, 'name' => $channelName],
                        [
                            'credentials' => [
                                'bot_token' => $channelConfig['bot_token'],
                                'chat_id' => $channelConfig['chat_id'] ?? '',
                            ],
                            'languages' => [$language],
                            'branding' => $branding,
                            'show_branding' => $channelConfig['show_branding'] ?? $showBranding,
                            'is_active' => true,
                        ]
                    );
                }
            } else {
                // Other platforms: use 'main' config
                $mainConfig = $configs['main'] ?? $configs;
                if (empty($mainConfig) || !is_array($mainConfig)) continue;

                $credentials = match ($platformSlug) {
                    'facebook' => [
                        'page_id' => $mainConfig['page_id'] ?? '',
                        'access_token' => $mainConfig['token'] ?? $mainConfig['access_token'] ?? '',
                    ],
                    'instagram' => [
                        'account_id' => $mainConfig['account_id'] ?? '',
                        'access_token' => $mainConfig['access_token'] ?? '',
                    ],
                    'twitter' => [
                        'api_key' => $mainConfig['api_key'] ?? '',
                        'api_secret' => $mainConfig['api_secret'] ?? '',
                        'access_token' => $mainConfig['access_token'] ?? '',
                        'access_token_secret' => $mainConfig['access_token_secret'] ?? '',
                    ],
                    default => $mainConfig,
                };

                SocialAccount::firstOrCreate(
                    ['user_id' => $user->id, 'platform_id' => $platform->id, 'name' => 'main'],
                    [
                        'credentials' => $credentials,
                        'languages' => [$language],
                        'branding' => $branding,
                        'show_branding' => $mainConfig['show_branding'] ?? $showBranding,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    private function importPublications(array $userMap): void
    {
        $offset = 0;
        $limit = 100;
        $imported = 0;
        $platforms = Platform::all()->keyBy('slug');

        $filter = $this->option('scheduled-only') ? '(etat,eq,Programme)' : '';

        do {
            $params = ['limit' => $limit, 'offset' => $offset, 'sort' => 'Id'];
            if ($filter) $params['where'] = $filter;

            $response = Http::withHeaders(['xc-token' => $this->token])
                ->get("{$this->baseUrl}/api/v1/db/data/noco/ppnwfbcqzc7rc5b/{$this->publicationsTable}", $params);

            $publications = $response->json('list', []);
            $pageInfo = $response->json('pageInfo', []);

            foreach ($publications as $pub) {
                $clientId = $pub['client_id'] ?? null;
                $user = $userMap[$clientId] ?? null;
                if (!$user) continue;

                $status = match ($pub['etat'] ?? '') {
                    'Programme' => 'scheduled',
                    'Publie' => 'published',
                    'Erreur' => 'failed',
                    'Brouillon' => 'draft',
                    default => 'draft',
                };

                // Parse media
                $media = null;
                if (!empty($pub['Media']) && is_array($pub['Media'])) {
                    $media = array_map(fn($m) => [
                        'url' => !empty($m['signedPath'])
                            ? "{$this->baseUrl}/{$m['signedPath']}"
                            : ($m['url'] ?? $m['path'] ?? ''),
                        'mimetype' => $m['mimetype'] ?? 'image/jpeg',
                        'size' => $m['size'] ?? 0,
                        'title' => $m['title'] ?? '',
                    ], $pub['Media']);
                }

                $post = Post::create([
                    'user_id' => $user->id,
                    'content_fr' => $pub['francais'] ?? '',
                    'content_en' => $pub['anglais'] ?? null,
                    'hashtags' => $pub['hashtag'] ?? null,
                    'auto_translate' => true,
                    'media' => $media,
                    'telegram_channel' => $pub['telegram_canaux'] ?? null,
                    'status' => $status,
                    'scheduled_at' => !empty($pub['date_publication']) ? $pub['date_publication'] : null,
                    'published_at' => $status === 'published' && !empty($pub['date_publication']) ? $pub['date_publication'] : null,
                ]);

                // Create post_platform entries for each target platform
                $reseaux = $pub['reseaux'] ?? '';
                if (is_string($reseaux)) {
                    $reseaux = array_map('trim', explode(',', $reseaux));
                }

                foreach ($reseaux as $reseau) {
                    $platformSlug = match (strtolower(trim($reseau))) {
                        'telegram' => 'telegram',
                        'facebook' => 'facebook',
                        'twitter' => 'twitter',
                        'instagram' => 'instagram',
                        'pinterest' => 'pinterest',
                        'youtube' => 'youtube',
                        default => null,
                    };

                    if (!$platformSlug) continue;
                    $platform = $platforms->get($platformSlug);
                    if (!$platform) continue;

                    // Find the user's account for this platform
                    $account = SocialAccount::where('user_id', $user->id)
                        ->where('platform_id', $platform->id)
                        ->first();

                    if (!$account) continue;

                    PostPlatform::create([
                        'post_id' => $post->id,
                        'social_account_id' => $account->id,
                        'platform_id' => $platform->id,
                        'status' => $status === 'published' ? 'published' : 'pending',
                        'published_at' => $status === 'published' ? $post->published_at : null,
                    ]);
                }

                $imported++;
            }

            $offset += $limit;
            $this->info("   {$imported} publications importées...");

        } while ($pageInfo['isLastPage'] ?? true ? false : true);

        $this->info("   Total: {$imported} publications importées");
    }
}
