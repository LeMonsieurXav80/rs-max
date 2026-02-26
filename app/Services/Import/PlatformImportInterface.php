<?php

namespace App\Services\Import;

use App\Models\SocialAccount;
use Illuminate\Support\Collection;

interface PlatformImportInterface
{
    /**
     * Import historical posts from a social account.
     *
     * @param  SocialAccount  $account  The social account to import from
     * @param  int  $limit  Maximum number of posts to import (default: 50)
     * @return Collection Collection of imported ExternalPost models
     *
     * @throws \Exception If the import fails
     */
    public function importHistory(SocialAccount $account, int $limit = 50): Collection;

    /**
     * Get the API quota cost for importing N posts.
     *
     * @param  int  $postCount  Number of posts to import
     * @return array ['cost' => int, 'description' => string]
     */
    public function getQuotaCost(int $postCount): array;
}
