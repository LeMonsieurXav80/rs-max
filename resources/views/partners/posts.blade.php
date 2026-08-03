@extends('layouts.app')

@section('title', 'Publications — ' . $partner->name)

@section('actions')
    <a href="{{ route('partners.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 text-gray-600 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
        </svg>
        Partenaires
    </a>
@endsection

@section('content')

    {{-- Synthèse --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Publications</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ $stats['total'] }}</p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Fils</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">{{ $stats['threads'] }}</p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Publiées</p>
            <p class="mt-1 text-2xl font-semibold text-green-600 tabular-nums">{{ $stats['published'] }}</p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Programmées</p>
            <p class="mt-1 text-2xl font-semibold text-blue-600 tabular-nums">{{ $stats['scheduled'] }}</p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Diffusions par réseau</p>
            <div class="mt-1 flex flex-wrap gap-1.5">
                @forelse($stats['by_platform'] as $slug => $count)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-700">
                        {{ $slug }} <span class="tabular-nums text-gray-500">{{ $count }}</span>
                    </span>
                @empty
                    <span class="text-sm text-gray-300">—</span>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Filtres --}}
    <form method="GET" action="{{ route('partners.posts', $partner) }}"
          class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 mb-6 flex flex-wrap items-end gap-4">
        <div>
            <label for="from" class="block text-xs font-medium text-gray-500 mb-1">Du</label>
            <input type="date" name="from" id="from" value="{{ $filters['from'] ?? '' }}"
                   class="rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
        </div>
        <div>
            <label for="to" class="block text-xs font-medium text-gray-500 mb-1">Au</label>
            <input type="date" name="to" id="to" value="{{ $filters['to'] ?? '' }}"
                   class="rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
        </div>
        <div>
            <label for="status" class="block text-xs font-medium text-gray-500 mb-1">Statut</label>
            <select name="status" id="status" class="rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                <option value="">Tous</option>
                @foreach(['draft' => 'Brouillon', 'scheduled' => 'Programmé', 'published' => 'Publié', 'failed' => 'Échec'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="source" class="block text-xs font-medium text-gray-500 mb-1">Origine du tag</label>
            <select name="source" id="source" class="rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                <option value="">Toutes</option>
                <option value="auto" @selected(($filters['source'] ?? '') === 'auto')>Héritée d'une photo</option>
                <option value="manual" @selected(($filters['source'] ?? '') === 'manual')>Posée à la main</option>
            </select>
        </div>
        <button type="submit"
                class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
            Filtrer
        </button>
        <a href="{{ route('partners.posts', $partner) }}" class="text-sm text-gray-500 hover:text-gray-700">Réinitialiser</a>
    </form>

    <h2 class="text-sm font-semibold text-gray-900 mb-3">Publications</h2>

    @if($posts->isEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
            <h3 class="text-base font-semibold text-gray-900 mb-2">Aucune publication</h3>
            <p class="text-sm text-gray-500">
                Aucune publication ne porte le tag « {{ $partner->name }} » avec ces filtres.
            </p>
        </div>
    @else
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">Publication</th>
                            <th class="px-6 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">Comptes</th>
                            <th class="px-6 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">Statut</th>
                            <th class="px-6 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">Tag</th>
                            <th class="px-6 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($posts as $post)
                            <tr class="hover:bg-gray-50/60 transition-colors">
                                <td class="px-6 py-4 max-w-md">
                                    <a href="{{ route('posts.show', $post->id) }}" class="text-sm text-gray-900 hover:text-indigo-600">
                                        {{ $post->content_preview }}
                                    </a>
                                    <div class="text-xs text-gray-400 mt-0.5">
                                        #{{ $post->id }} · {{ $post->user?->name }}
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($post->postPlatforms as $pp)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-600"
                                                  title="{{ $pp->socialAccount?->name }}">
                                                {{ $pp->platform?->slug }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    {{-- Classes ecrites en toutes lettres : Tailwind ne genere pas les noms construits dynamiquement. --}}
                                    @php
                                        $statusClass = match ($post->status) {
                                            'published' => 'bg-green-50 text-green-600',
                                            'scheduled' => 'bg-blue-50 text-blue-600',
                                            'publishing' => 'bg-yellow-50 text-yellow-700',
                                            'failed' => 'bg-red-50 text-red-600',
                                            default => 'bg-gray-100 text-gray-600',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium {{ $statusClass }}">
                                        {{ $post->status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    @if($post->pivot->source === 'auto')
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-amber-50 text-amber-700"
                                              title="Hérité d'une photo taguée">photo</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-indigo-50 text-indigo-600"
                                              title="Posé à la main">manuel</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                    {{ ($post->published_at ?? $post->scheduled_at ?? $post->created_at)->format('d/m/Y H:i') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6">
            {{ $posts->links() }}
        </div>
    @endif

    {{-- Fils de discussion tagués (même référentiel, pivot partner_thread) --}}
    <h2 class="text-sm font-semibold text-gray-900 mt-10 mb-3">Fils de discussion</h2>

    @if($threads->isEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-10 text-center">
            <p class="text-sm text-gray-500">
                Aucun fil ne porte le tag « {{ $partner->name }} » avec ces filtres.
            </p>
        </div>
    @else
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">Fil</th>
                            <th class="px-6 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">Comptes</th>
                            <th class="px-6 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">Statut</th>
                            <th class="px-6 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">Tag</th>
                            <th class="px-6 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($threads as $thread)
                            <tr class="hover:bg-gray-50/60 transition-colors">
                                <td class="px-6 py-4 max-w-md">
                                    <a href="{{ route('threads.show', $thread) }}" class="text-sm text-gray-900 hover:text-indigo-600">
                                        {{ $thread->title ?: $thread->content_preview }}
                                    </a>
                                    <div class="text-xs text-gray-400 mt-0.5">
                                        #{{ $thread->id }} · {{ $thread->segments->count() }} segments · {{ $thread->user?->name }}
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($thread->socialAccounts as $account)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-600"
                                                  title="{{ $account->name }}">
                                                {{ $account->platform?->slug }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    @php
                                        $threadStatusClass = match ($thread->status) {
                                            'published' => 'bg-green-50 text-green-600',
                                            'partial' => 'bg-amber-50 text-amber-700',
                                            'scheduled' => 'bg-blue-50 text-blue-600',
                                            'publishing' => 'bg-yellow-50 text-yellow-700',
                                            'failed' => 'bg-red-50 text-red-600',
                                            default => 'bg-gray-100 text-gray-600',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium {{ $threadStatusClass }}">
                                        {{ $thread->status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    @if($thread->pivot->source === 'auto')
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-amber-50 text-amber-700"
                                              title="Hérité d'une photo taguée">photo</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-indigo-50 text-indigo-600"
                                              title="Posé à la main">manuel</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                    {{ ($thread->published_at ?? $thread->scheduled_at ?? $thread->created_at)->format('d/m/Y H:i') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6">
            {{ $threads->links() }}
        </div>
    @endif

@endsection
