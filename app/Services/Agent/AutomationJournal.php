<?php

namespace App\Services\Agent;

use App\Models\AccountActionEvent;
use App\Models\BotActionLog;
use App\Models\BrowserTask;
use Illuminate\Support\Facades\DB;

/** Journal court et expurgé ; les identités anti-doublons restent dans leurs tables métier. */
class AutomationJournal
{
    public const STATUSES = ['pending', 'running', 'completed', 'confirmed', 'unchanged', 'failed', 'uncertain', 'cancelled'];

    public function task(BrowserTask $task): void
    {
        if ($task->created_at->lt(now()->subDays(7))) {
            return;
        }
        $p = $task->payload ?? [];
        $result = $task->result ?? [];
        $rawStatus = $task->status ?? 'pending';
        $status = $rawStatus === 'done' ? (($result['confirmed'] ?? false) ? (($result['unchanged'] ?? false) ? 'unchanged' : 'confirmed') : 'completed') : $rawStatus;
        $log = BotActionLog::firstOrNew(['browser_task_id' => $task->id]);
        $metadata = array_replace($log->metadata ?? [], ['status' => $status, 'task_kind' => $task->kind,
            'action' => $p['action'] ?? $task->kind, 'profile' => $task->profile,
            'claimed_at' => $task->claimed_at?->toIso8601String(), 'finished_at' => $task->finished_at?->toIso8601String(),
            'duration_ms' => $task->claimed_at && $task->finished_at ? (int) abs($task->finished_at->diffInMilliseconds($task->claimed_at)) : null,
            'proof' => $this->proof($result), 'dry_run' => (bool) ($p['dry'] ?? false)]);
        $log->fill(['social_account_id' => $task->social_account_id, 'action_type' => in_array($task->kind, ['x.agent.act', 'threads.agent.act']) ? ($task->kind === 'x.agent.act' ? 'x.' : 'threads.').($p['action'] ?? 'act') : $task->kind,
            'source' => 'browser', 'target_uri' => $this->url($p['target']['url'] ?? $p['url'] ?? ''), 'target_author' => $this->text($p['target']['author'] ?? $p['handle'] ?? null, 255),
            'target_text' => $this->text($p['text'] ?? $p['caption'] ?? $p['target']['text'] ?? null, 2000),
            'success' => $rawStatus === 'done', 'error' => $this->text($task->error, 1000),
            'metadata' => $metadata, 'performed_at' => $task->finished_at ?? $task->claimed_at ?? $task->created_at]);
        parse_str(parse_url($p['url'] ?? '', PHP_URL_QUERY) ?? '', $query);
        if (str_starts_with($task->kind, 'tiktok.photo.') && isset($result['url'])) {
            $log->target_uri = $this->url($result['url']);
        }
        if (preg_match('/^[0-9]+$/', (string) ($query['in_reply_to'] ?? ''))) {
            $log->target_uri = 'https://x.com/i/status/'.$query['in_reply_to'];
        }
        if (preg_match('/^[0-9]+$/', (string) ($result['id'] ?? '')) && preg_match('/^[a-zA-Z0-9_]{1,15}$/', $p['username'] ?? '') && str_starts_with($task->kind, 'x.')) {
            $metadata['proof']['post_url'] = 'https://x.com/'.$p['username'].'/status/'.$result['id'];
            $log->metadata = $metadata;
        }
        $log->created_at = $task->created_at;
        $log->save();
    }

    public function event(AccountActionEvent $event): void
    {
        if ($event->browser_task_id || $event->created_at->lt(now()->subDays(7))) {
            return;
        }
        $log = BotActionLog::firstOrNew(['account_action_event_id' => $event->id]);
        $log->fill([
            'social_account_id' => $event->social_account_id, 'action_type' => $event->action, 'source' => $event->executor,
            'target_uri' => $this->url($event->target ?? '') ?: ($log->target_uri ?? ''), 'success' => in_array($event->status, ['confirmed', 'completed', 'unchanged'], true),
            'error' => $this->text($event->error, 1000), 'metadata' => ['status' => ($event->status ?? 'attempted') === 'attempted' ? 'running' : $event->status],
            'performed_at' => $event->updated_at]);
        $log->created_at = $event->created_at;
        $log->save();
    }

    public function append(BrowserTask $task, array $steps, bool $final = false): void
    {
        if ($task->created_at->lt(now()->subDays(7))) {
            return;
        }
        DB::transaction(function () use ($task, $steps, $final) {
            $log = BotActionLog::where('browser_task_id', $task->id)->lockForUpdate()->first();
            if (! $log) {
                $this->task($task);
                $log = BotActionLog::where('browser_task_id', $task->id)->lockForUpdate()->firstOrFail();
            }
            $metadata = $log->metadata ?? [];
            $incoming = $this->steps($steps);
            $existing = $metadata['steps'] ?? [];
            // Les messages de progression peuvent arriver dans un ordre différent.
            // La liste finale de Companion conserve l’ordre réel d’exécution.
            $all = collect($final ? [...$incoming, ...$existing] : [...$existing, ...$incoming])->unique('id')->take(-100)->values()->all();
            $log->update(['metadata' => array_replace($metadata, ['steps' => $all])]);
        });
    }

    public function steps(mixed $steps): array
    {
        if (! is_array($steps)) {
            return [];
        }

        return collect($steps)->take(100)->filter(fn ($step) => is_array($step) && is_string($step['stage'] ?? null))
            ->map(fn ($step) => array_filter(['id' => $this->text($step['id'] ?? hash('sha256', json_encode($step)), 80), 'stage' => $this->text($step['stage'], 100),
                'elapsed_ms' => isset($step['elapsed_ms']) && is_numeric($step['elapsed_ms']) ? min(3600000, max(0, (int) $step['elapsed_ms'])) : null,
                'details' => $this->details($step['details'] ?? [])], fn ($value) => $value !== null))->values()->all();
    }

    public function details(mixed $details): array
    {
        if (! is_array($details)) {
            return [];
        }
        $allowed = ['kind', 'action', 'status', 'run_id', 'recipe', 'version', 'control', 'control_count', 'target_id', 'attached', 'confirmed', 'unchanged', 'gesture', 'attempt', 'text_matched', 'post_matched', 'parent_visible', 'observed_count', 'streak', 'items', 'count', 'total', 'failed', 'ok', 'error', 'message', 'url'];
        $out = [];
        foreach (array_intersect_key($details, array_flip($allowed)) as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[$key] = $key === 'url' ? $this->url((string) $value) : (is_string($value) ? $this->text($value, 500) : $value);
            }
        }

        return $out;
    }

    public function proof(array $result): array
    {
        $proof = array_intersect_key($result, array_flip(['confirmed', 'unchanged', 'gesture', 'proof', 'send_clicked', 'initial_visible', 'target_found', 'pause_ms', 'id', 'post_id', 'reply_id', 'parent_id', 'quote_id', 'media_count', 'visibility', 'upload_order_verified']));
        $proof = array_filter($proof, fn ($value) => is_scalar($value));
        $proof = array_map(fn ($value) => is_string($value) ? $this->text($value, 120) : $value, $proof);
        foreach (['post_url', 'reply_url'] as $key) {
            if (isset($result[$key])) {
                $proof[$key] = $this->url($result[$key]);
            }
        }
        if (isset($result['url']) && preg_match('~^https://www\.tiktok\.com/@[\w.]+/photo/[0-9]+$~', $result['url'])) {
            $proof['post_url'] = $this->url($result['url']);
        }
        if (isset($result['verification']) && is_array($result['verification'])) {
            $proof['verification'] = $this->details($result['verification']);
        }
        if (($result['platform_limit']['source'] ?? '') === 'platform_ui') {
            $proof['platform_limit'] = ['source' => 'platform_ui', 'scope' => $this->text($result['platform_limit']['scope'] ?? '', 30), 'message' => $this->text($result['platform_limit']['message'] ?? '', 500)];
        }

        return $proof;
    }

    public function url(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        parse_str($parts['query'] ?? '', $query);
        $query = array_intersect_key($query, array_flip(['in_reply_to', 'quote_tweet_id']));

        return mb_substr($parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '').($query ? '?'.http_build_query($query) : ''), 0, 2000);
    }

    public function text(mixed $value, int $max): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }
        $value = (string) $value;
        $value = preg_replace('/"(authorization|cookie|auth_token|ct0|access_token|api[_-]?key|password|token)"\s*:\s*"[^"]*"/i', '$1="[masqué]"', $value);
        $value = preg_replace('/Bearer\s+[^\s,;]+/i', 'Bearer [masqué]', $value);
        $value = preg_replace('/\b(authorization|cookie|auth_token|ct0|access_token|api[_-]?key|password|token)\s*[:=]\s*[^\s,;]+/i', '$1=[masqué]', $value);
        $value = preg_replace_callback('~https?://[^\s<>]+~i', fn ($match) => $this->url($match[0]), $value);

        return mb_substr($value, 0, $max);
    }

    public function present(BotActionLog $log): array
    {
        $m = is_array($log->metadata) ? $log->metadata : [];

        return ['id' => $log->id, 'account_id' => $log->social_account_id, 'account' => $log->socialAccount?->name,
            'platform' => $log->socialAccount?->platform?->slug, 'action' => $log->action_type, 'source' => $log->source,
            'status' => in_array($m['status'] ?? null, self::STATUSES, true) ? $m['status'] : ($log->success ? 'completed' : 'failed'),
            'at' => $log->created_at->toIso8601String(), 'updated_at' => $log->updated_at->toIso8601String(),
            'run_id' => $this->text($m['run_id'] ?? null, 80), 'recipe' => $this->text($m['recipe'] ?? null, 100), 'version' => $this->text($m['version'] ?? null, 30),
            'task_id' => $log->browser_task_id, 'dry_run' => (bool) ($m['dry_run'] ?? false), 'target_url' => $this->url($log->target_uri ?? ''),
            'target_author' => $this->text($log->target_author, 255), 'text' => $this->text($log->target_text, 2000),
            'error' => $this->text($log->error, 1000), 'duration_ms' => is_numeric($m['duration_ms'] ?? null) ? max(0, min(3600000, (int) $m['duration_ms'])) : null,
            'proof' => $this->proof(is_array($m['proof'] ?? null) ? $m['proof'] : []), 'steps' => $this->steps($m['steps'] ?? [])];
    }
}
