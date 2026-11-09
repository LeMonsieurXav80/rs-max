<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\EditorialCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EditorialCalendarController extends Controller
{
    public function index(Request $request, EditorialCalendar $calendar): View
    {
        $filters = $this->filters($request);
        $month = $this->month($filters);
        $accounts = $request->user()->visibleSocialAccounts()->with('platform')->orderBy('name')->get();
        $account = isset($filters['account_id']) ? $accounts->firstWhere('id', (int) $filters['account_id']) : null;
        abort_if(isset($filters['account_id']) && ! $account, 404);

        $events = $calendar->search(
            $month->toDateString(),
            $month->endOfMonth()->toDateString(),
            $filters['topic'] ?? null,
            $filters['q'] ?? null,
            $account,
        );

        return view('editorial-calendar.index', [
            'accounts' => $accounts,
            'account' => $account,
            'month' => $month,
            'filters' => $filters,
            'topics' => config('editorial_calendar.topics'),
            'sources' => config('editorial_calendar.sources'),
            'eventsByDay' => $events->where('starts_on', '>=', $month->toDateString())->groupBy('starts_on'),
            'ongoing' => $events->where('starts_on', '<', $month->toDateString()),
            'count' => $events->count(),
            'months' => collect(array_keys(config('editorial_calendar.sources')))
                ->flatMap(fn (int $year) => collect(range(1, 12))->map(fn (int $month) => CarbonImmutable::create($year, $month, 1)))
                ->push($month)
                ->unique(fn (CarbonImmutable $date) => $date->format('Y-m'))
                ->sort(),
        ]);
    }

    public function events(Request $request, EditorialCalendar $calendar): JsonResponse
    {
        $filters = $this->filters($request, dates: true);
        $account = isset($filters['account_id'])
            ? $request->user()->visibleSocialAccounts()->findOrFail($filters['account_id'])
            : null;

        return $this->results($calendar, $filters, $account);
    }

    public function accountEvents(Request $request, int $id, EditorialCalendar $calendar): JsonResponse
    {
        abort_unless($request->user()->isManager(), 403);
        $account = $request->user()->connectedSocialAccounts()->findOrFail($id);

        return $this->results($calendar, $this->filters($request, dates: true), $account);
    }

    private function results(EditorialCalendar $calendar, array $filters, ?SocialAccount $account): JsonResponse
    {
        $month = $this->month($filters);
        $from = $filters['from'] ?? $month->toDateString();
        $to = $filters['to'] ?? $month->endOfMonth()->toDateString();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'account_id' => $account?->id,
            'topics' => config('editorial_calendar.topics'),
            'events' => $calendar->search($from, $to, $filters['topic'] ?? null, $filters['q'] ?? null, $account),
        ]);
    }

    private function filters(Request $request, bool $dates = false): array
    {
        $rules = [
            'month' => ['nullable', 'date_format:Y-m'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'topic' => ['nullable', Rule::in(['all', ...array_keys(config('editorial_calendar.topics'))])],
            'q' => ['nullable', 'string', 'max:150'],
        ];

        if ($dates) {
            $rules['from'] = ['required_with:to', 'date_format:Y-m-d'];
            $rules['to'] = ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'];
        }

        return $request->validate($rules);
    }

    private function month(array $filters): CarbonImmutable
    {
        return isset($filters['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', $filters['month'])
            : CarbonImmutable::now(config('app.timezone'))->startOfMonth();
    }
}
