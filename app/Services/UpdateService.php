<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class UpdateService
{
    public function checkForUpdate(): bool
    {
        try {
            $localHash = $this->getLocalHash();
            if (! $localHash) {
                return false;
            }

            $remoteHash = $this->getRemoteHash();
            if (! $remoteHash) {
                return false;
            }

            $updateAvailable = $localHash !== $remoteHash;

            Setting::set('update_available', $updateAvailable ? '1' : '0');
            Setting::set('update_remote_hash', $remoteHash);
            Setting::set('update_checked_at', now()->toIso8601String());

            if ($updateAvailable) {
                $changelog = $this->getChangelog($localHash, $remoteHash);
                Setting::set('update_changelog', $changelog);
            }

            return $updateAvailable;
        } catch (\Throwable $e) {
            Log::warning('UpdateService: check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getLocalHash(): ?string
    {
        // 1. From git-version.txt (Docker build)
        $versionFile = storage_path('app/git-version.txt');
        if (file_exists($versionFile)) {
            $hash = trim(file_get_contents($versionFile));
            if ($hash && $hash !== 'unknown') {
                return $hash;
            }
        }

        // 2. From SOURCE_COMMIT env (Coolify)
        if (! empty(env('SOURCE_COMMIT'))) {
            return substr(env('SOURCE_COMMIT'), 0, 7);
        }

        // 3. From local git
        $result = Process::run('git rev-parse --short HEAD');
        if ($result->successful()) {
            return trim($result->output());
        }

        return null;
    }

    public function getRemoteHash(): ?string
    {
        $repo = config('services.deploy.git_repo');
        $branch = config('services.deploy.git_branch', 'main');

        if (! $repo) {
            return null;
        }

        $ref = "refs/heads/{$branch}";
        $result = Process::run("git ls-remote {$repo} {$ref}");
        if ($result->successful()) {
            $output = trim($result->output());
            if (preg_match('/^([a-f0-9]+)/', $output, $matches)) {
                return substr($matches[1], 0, 7);
            }
        }

        return null;
    }

    public function getChangelog(string $localHash, string $remoteHash): string
    {
        $repo = config('services.deploy.git_repo');
        $branch = config('services.deploy.git_branch', 'main');

        if ($repo) {
            // Add/update temporary remote for fetching
            Process::run("git remote remove _deploy 2>/dev/null");
            Process::run("git remote add _deploy {$repo}");
            Process::run("git fetch _deploy {$branch} --quiet 2>/dev/null");

            $result = Process::run("git log --oneline {$localHash}.._deploy/{$branch} 2>/dev/null");
            Process::run("git remote remove _deploy 2>/dev/null");

            if ($result->successful() && trim($result->output())) {
                return trim($result->output());
            }
        }

        return "Nouvelle version disponible ({$remoteHash})";
    }

    public function deploy(): array
    {
        $apiUrl = config('services.deploy.api_url');
        $apiToken = config('services.deploy.api_token');
        $appUuid = config('services.deploy.app_uuid');

        if (! $apiUrl || ! $apiToken || ! $appUuid) {
            return ['success' => false, 'error' => 'Configuration de deploiement manquante (DEPLOY_API_URL, DEPLOY_API_TOKEN, DEPLOY_APP_UUID)'];
        }

        try {
            $response = Http::withToken($apiToken)
                ->post("{$apiUrl}/api/v1/applications/{$appUuid}/deploy");

            if ($response->successful()) {
                Setting::set('update_available', '0');
                Setting::set('update_deployed_at', now()->toIso8601String());
                return ['success' => true, 'error' => null];
            }

            return ['success' => false, 'error' => $response->body()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function isUpdateAvailable(): bool
    {
        return (bool) Setting::get('update_available', false);
    }

    public function getUpdateInfo(): array
    {
        return [
            'available' => $this->isUpdateAvailable(),
            'remote_hash' => Setting::get('update_remote_hash'),
            'changelog' => Setting::get('update_changelog'),
            'checked_at' => Setting::get('update_checked_at'),
            'local_hash' => $this->getLocalHash(),
            'deploy_configured' => (bool) config('services.deploy.api_url'),
        ];
    }
}
