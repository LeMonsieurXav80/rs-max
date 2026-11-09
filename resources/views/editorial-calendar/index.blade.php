@extends('layouts.app')

@section('title', 'Calendrier éditorial')

@section('content')
    @php
        $label = ucfirst($month->locale('fr')->isoFormat('MMMM YYYY'));
        $weekdays = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        $previous = $month->subMonth()->format('Y-m');
        $next = $month->addMonth()->format('Y-m');
    @endphp

    <section class="space-y-4" aria-labelledby="editorial-month">
        <form method="get" action="{{ route('editorial-calendar.index') }}" class="grid gap-3 rounded-2xl border border-gray-200 bg-white p-4 sm:grid-cols-2 xl:flex xl:items-end">
            <label class="block text-sm font-medium text-gray-700">
                Mois
                <select name="month" class="mt-1 block w-full rounded-xl border-gray-300 text-sm">
                    @foreach($months as $option)
                        <option value="{{ $option->format('Y-m') }}" @selected($option->format('Y-m') === $month->format('Y-m'))>{{ ucfirst($option->locale('fr')->isoFormat('MMMM YYYY')) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block min-w-0 text-sm font-medium text-gray-700">
                Compte
                <select name="account_id" class="mt-1 block w-full rounded-xl border-gray-300 text-sm">
                    <option value="">Tous les comptes</option>
                    @foreach($accounts as $option)
                        <option value="{{ $option->id }}" @selected($account?->id === $option->id)>{{ $option->name }} · {{ $option->platform->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block min-w-0 text-sm font-medium text-gray-700">
                Thème
                <select name="topic" class="mt-1 block w-full rounded-xl border-gray-300 text-sm">
                    <option value="">Selon le compte</option>
                    <option value="all" @selected(($filters['topic'] ?? null) === 'all')>Tous les thèmes</option>
                    @foreach($topics as $key => $topicLabel)
                        <option value="{{ $key }}" @selected(($filters['topic'] ?? null) === $key)>{{ $topicLabel }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block flex-1 text-sm font-medium text-gray-700">
                Événement
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="150" placeholder="Rechercher une date clé" class="mt-1 block w-full rounded-xl border-gray-300 text-sm">
            </label>
            <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Afficher</button>
        </form>

        @if($errors->any())
            <p role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</p>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="editorial-month" class="text-xl font-semibold text-gray-900">{{ $label }}</h2>
                <p class="text-sm text-gray-500">{{ $count }} {{ $count === 1 ? 'événement' : 'événements' }}</p>
            </div>
            <nav aria-label="Navigation par mois" class="flex gap-2">
                <a href="{{ route('editorial-calendar.index', array_replace($filters, ['month' => $previous])) }}" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm hover:bg-gray-50">← Précédent</a>
                <a href="{{ route('editorial-calendar.index', array_replace($filters, ['month' => $next])) }}" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm hover:bg-gray-50">Suivant →</a>
            </nav>
        </div>

        @if($ongoing->isNotEmpty())
            <div class="rounded-xl border border-indigo-100 bg-indigo-50 p-3 text-sm text-indigo-950">
                <p class="font-medium">Événements déjà en cours en début de mois</p>
                <ul class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                    @foreach($ongoing as $event)
                        <li>{{ $event['title'] }} · jusqu’au {{ \Carbon\CarbonImmutable::parse($event['ends_on'])->format('d/m/Y') }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-gray-200 sm:grid sm:grid-cols-7 sm:gap-px">
            @foreach($weekdays as $weekday)
                <div class="hidden bg-gray-50 p-2 text-center text-xs font-semibold text-gray-600 sm:block">{{ $weekday }}</div>
            @endforeach
            @for($offset = 1; $offset < $month->dayOfWeekIso; $offset++)
                <div class="hidden bg-gray-50 sm:block" aria-hidden="true"></div>
            @endfor
            @for($day = 1; $day <= $month->daysInMonth; $day++)
                @php($date = $month->day($day))
                <div class="border-b border-gray-200 bg-white p-2 sm:min-h-[6rem] sm:min-w-0 sm:border-0">
                    <time datetime="{{ $date->toDateString() }}" class="inline-flex rounded-lg px-1.5 py-0.5 text-xs font-semibold {{ $date->isToday() ? 'bg-indigo-600 text-white' : 'text-gray-700' }}">
                        <span class="mr-1 sm:hidden">{{ $weekdays[$date->dayOfWeekIso - 1] }}</span>{{ $day }}
                    </time>
                    <ul class="mt-1 space-y-1">
                        @foreach($eventsByDay->get($date->toDateString(), []) as $event)
                            <li class="break-words rounded-md bg-indigo-50 px-2 py-1.5 text-xs leading-snug text-indigo-950">
                                {{ $event['title'] }}
                                @if($event['ends_on'])
                                    <span class="block text-indigo-700">Jusqu’au {{ \Carbon\CarbonImmutable::parse($event['ends_on'])->format('d/m/Y') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endfor
            @for($offset = $month->endOfMonth()->dayOfWeekIso; $offset < 7; $offset++)
                <div class="hidden bg-gray-50 sm:block" aria-hidden="true"></div>
            @endfor
        </div>

        <p class="text-xs text-gray-500">
            Sources
            @foreach($sources as $year => $url)
                <a href="{{ $url }}" class="ml-2 text-indigo-700 underline">Swello {{ $year }}</a>
            @endforeach
        </p>
    </section>
@endsection
