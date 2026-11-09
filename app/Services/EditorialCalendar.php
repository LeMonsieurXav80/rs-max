<?php

namespace App\Services;

use App\Models\SocialAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EditorialCalendar
{
    public function search(string $from, string $to, ?string $topic, ?string $search, ?SocialAccount $account): Collection
    {
        $topics = match ($topic) {
            'all' => [],
            null => $account?->agent_strategy['editorial_topics'] ?? [],
            default => [$topic],
        };
        $needle = Str::ascii(Str::lower($search ?? ''));

        return collect(config('editorial_calendar.sources'))
            ->flatMap(function (string $source, int $year) {
                $events = json_decode(file_get_contents(resource_path("editorial-calendar/{$year}.json")), true, flags: JSON_THROW_ON_ERROR);

                return array_map(fn (array $event) => $event + ['source_url' => $source], $events);
            })
            ->filter(fn (array $event) => $event['starts_on'] <= $to
                && ($event['ends_on'] ?? $event['starts_on']) >= $from
                && ($topics === [] || array_intersect($topics, $event['topics']))
                && str_contains(Str::ascii(Str::lower($event['title'])), $needle))
            ->values();
    }
}
