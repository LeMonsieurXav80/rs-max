<?php

namespace App\Services\Browser;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Accès HTTP au Camofox de RS-Max. Une session Camofox correspond à un compte social. */
class CamofoxClient
{
    public function isConfigured(): bool
    {
        return filled(config('browser.url')) && filled(config('browser.access_key'));
    }

    public function open(string $userId, string $url): string
    {
        return (string) $this->send('post', '/tabs', ['userId' => $userId, 'sessionKey' => 'rsmax', 'url' => $url])->json('tabId');
    }

    public function close(string $userId, string $tabId): void
    {
        try {
            $this->send('delete', "/tabs/{$tabId}", ['userId' => $userId]);
        } catch (BrowserException) {
            // L'onglet expire de lui-même : un échec ici ne doit pas masquer le résultat.
        }
    }

    public function navigate(string $userId, string $tabId, string $url): void
    {
        $this->send('post', "/tabs/{$tabId}/navigate", ['userId' => $userId, 'url' => $url]);
    }

    public function evaluate(string $userId, string $tabId, string $expression): mixed
    {
        return $this->send('post', "/tabs/{$tabId}/evaluate", ['userId' => $userId, 'expression' => $expression])->json('result');
    }

    public function click(string $userId, string $tabId, string $selector): void
    {
        $this->send('post', "/tabs/{$tabId}/click", ['userId' => $userId, 'selector' => $selector]);
    }

    public function press(string $userId, string $tabId, string $key): void
    {
        $this->send('post', "/tabs/{$tabId}/press", ['userId' => $userId, 'key' => $key]);
    }

    /** @param  array<int, string>  $paths  Chemins vus depuis le conteneur Camofox. */
    public function upload(string $userId, string $tabId, array $paths, string $selector): void
    {
        $this->send('post', "/tabs/{$tabId}/upload", ['userId' => $userId, 'path' => $paths, 'selector' => $selector]);
    }

    public function screenshot(string $userId, string $tabId): string
    {
        return $this->send('get', "/tabs/{$tabId}/screenshot", ['userId' => $userId])->body();
    }

    /** @param  array<int, array<string, mixed>>  $cookies */
    public function importCookies(string $userId, array $cookies): void
    {
        $this->send('post', "/sessions/{$userId}/cookies", ['cookies' => $cookies], config('browser.api_key'));
    }

    private function send(string $method, string $path, array $data, ?string $key = null): Response
    {
        if (! $this->isConfigured()) {
            throw new BrowserException('Navigateur du VPS non configuré.');
        }

        try {
            $response = $this->client($key)->{$method}($path, $data);
        } catch (ConnectionException $e) {
            throw new BrowserException('Navigateur du VPS injoignable : '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new BrowserException("Navigateur du VPS : erreur {$response->status()} sur {$path} : ".mb_substr($response->body(), 0, 300));
        }

        return $response;
    }

    private function client(?string $key): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('browser.url'), '/'))
            ->withToken((string) ($key ?? config('browser.access_key')))
            ->timeout((int) config('browser.timeout'))
            ->acceptJson();
    }
}
