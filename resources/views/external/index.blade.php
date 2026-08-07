@extends('layouts.app')

@section('title', 'Publications natives')

@section('actions')
    <button
        type="button"
        x-data="{ loading: false }"
        @click="
            loading = true;
            fetch('{{ route('external.refresh') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            })
            .then(r => r.json())
            .then(() => window.location.reload())
            .catch(() => { loading = false; alert('L\'import a echoue. Voir les logs.'); })
        "
        :disabled="loading"
        class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm disabled:opacity-50"
    >
        <svg class="w-4 h-4" :class="loading && 'animate-spin'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356m-4.993 4.992-3.181-3.183a8.25 8.25 0 0 0-13.803 3.7M4.031 9.865v4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7" />
        </svg>
        <span x-text="loading ? 'Recuperation en cours…' : 'Recuperer les dernieres publications'"></span>
    </button>
@endsection

@section('content')

    @if(session('success'))
        <div class="mb-6" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)">
            <div class="rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <p class="text-sm text-green-700">{{ session('success') }}</p>
            </div>
        </div>
    @endif

    {{-- Filtres : groupes + comptes affiches dans les colonnes --}}
    <form method="GET" action="{{ route('external.index') }}" class="mb-6 bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
        <x-account-selector
            :accounts="$accounts"
            :selected-ids="$selectedAccounts"
            :groups="$groups"
            label="Comptes affiches dans les colonnes"
        />

        <div class="flex flex-wrap items-end gap-4 mt-4 pt-4 border-t border-gray-100">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-gray-500 mb-1.5">Recherche</label>
                <input type="text" name="search" value="{{ $search }}" placeholder="Texte de la publication…"
                       class="w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1.5">Periode</label>
                <select name="period" class="rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach(['7' => '7 jours', '30' => '30 jours', '90' => '90 jours', '365' => '1 an', 'all' => 'Tout'] as $value => $label)
                        <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <label class="inline-flex items-center gap-2 text-sm text-gray-600 pb-2">
                <input type="checkbox" name="ignored" value="1" @checked($showIgnored)
                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                Voir les ecartees
            </label>

            <button type="submit"
                    class="px-4 py-2 bg-gray-900 text-white text-sm font-medium rounded-xl hover:bg-gray-700 transition-colors">
                Appliquer
            </button>
        </div>
    </form>

    <p class="mb-4 text-sm text-gray-500">
        Une colonne par reseau. Cochez <span class="font-medium text-gray-700">une publication par colonne</span> :
        elles seront reunies en une seule publication RS-Max.
        @if($pendingCount > 0)
            <span class="font-medium text-gray-700">{{ $pendingCount }} en attente de rattachement.</span>
        @endif
    </p>

    @if($columns->isEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
            <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-6">
                <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900 mb-2">Aucun compte a afficher</h3>
            <p class="text-sm text-gray-500">Choisissez au moins un groupe ou un compte ci-dessus.</p>
        </div>
    @else
        <div x-data="externalBoard()">
            {{-- Tableau : une colonne par reseau --}}
            <div class="flex gap-4 overflow-x-auto pb-32 items-start">
                @foreach($columns as $column)
                    @php
                        $slug = $column['platform']->slug;
                        $accountNames = $column['accounts']->pluck('name')->join(', ');
                    @endphp
                    <div class="flex-shrink-0 w-80 bg-gray-50 rounded-2xl border border-gray-200 flex flex-col max-h-[calc(100vh-22rem)]">
                        {{-- En-tete de colonne --}}
                        <div class="flex items-center gap-2 px-3 py-3 border-b border-gray-200 bg-white rounded-t-2xl">
                            <x-platform-icon :platform="$slug" size="sm" />
                            <span class="text-sm font-semibold text-gray-900">{{ $column['platform']->name }}</span>
                            <span class="text-xs text-gray-400">{{ $column['posts']->count() }}</span>

                            @if(! $column['importable'])
                                <span class="ml-auto text-[10px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200"
                                      title="Pas d'import automatique pour ce reseau">
                                    manuel
                                </span>
                            @endif
                        </div>

                        {{-- Comptes alimentant la colonne --}}
                        <div class="px-3 py-1.5 border-b border-gray-100 bg-white/60">
                            <p class="text-[11px] text-gray-400 truncate" title="{{ $accountNames }}">
                                {{ $accountNames }}
                            </p>
                        </div>

                        {{-- Flux --}}
                        <div class="flex-1 overflow-y-auto p-2 space-y-1.5">
                            @forelse($column['posts'] as $externalPost)
                                @php
                                    $thumbnail = $externalPost->thumbnailUrl();
                                    $mediaCount = count($externalPost->mediaItems());
                                    $metrics = $externalPost->getFormattedMetrics();
                                @endphp

                                <div
                                    @click="pick('{{ $slug }}', {{ $externalPost->id }})"
                                    class="group flex gap-2 p-2 rounded-xl border bg-white cursor-pointer transition-colors"
                                    :class="isPicked('{{ $slug }}', {{ $externalPost->id }})
                                        ? 'border-indigo-500 ring-1 ring-indigo-200'
                                        : 'border-gray-100 hover:border-gray-300'"
                                >
                                    {{-- Coche --}}
                                    <div class="flex-shrink-0 w-4 h-4 mt-0.5 rounded border flex items-center justify-center transition-colors"
                                         :class="isPicked('{{ $slug }}', {{ $externalPost->id }})
                                            ? 'bg-indigo-600 border-indigo-600'
                                            : 'border-gray-300 bg-white'">
                                        <svg class="w-3 h-3 text-white" x-show="isPicked('{{ $slug }}', {{ $externalPost->id }})" x-cloak
                                             fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                    </div>

                                    {{-- Vignette discrete --}}
                                    @if($thumbnail)
                                        <div class="relative flex-shrink-0">
                                            <img src="{{ $thumbnail }}" alt="" loading="lazy"
                                                 class="w-10 h-10 rounded-lg object-cover bg-gray-100">
                                            @if($mediaCount > 1)
                                                <span class="absolute -bottom-1 -right-1 px-1 rounded bg-gray-900 text-white text-[9px] leading-4">
                                                    {{ $mediaCount }}
                                                </span>
                                            @endif
                                        </div>
                                    @endif

                                    {{-- Texte --}}
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs text-gray-700 line-clamp-2 leading-snug">
                                            {{ $externalPost->content ?: '(sans texte)' }}
                                        </p>
                                        <div class="mt-1 flex items-center gap-2 text-[10px] text-gray-400">
                                            <span>{{ $externalPost->published_at?->format('d/m/y') }}</span>
                                            <span>·</span>
                                            <span>{{ $metrics['likes'] }} j'aime</span>
                                            @if($externalPost->post_url)
                                                <a href="{{ $externalPost->post_url }}" target="_blank" rel="noopener"
                                                   @click.stop
                                                   class="ml-auto text-indigo-600 hover:text-indigo-700 opacity-0 group-hover:opacity-100 transition-opacity">
                                                    Voir
                                                </a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <p class="text-xs text-gray-400 text-center py-8">
                                    {{ $showIgnored ? 'Rien d\'ecarte ici.' : 'Aucune publication native.' }}
                                </p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Barre de selection --}}
            <div x-show="count > 0" x-cloak
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="fixed bottom-0 left-0 right-0 lg:left-64 z-40 bg-white border-t border-gray-200 shadow-lg px-6 py-4">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-2 min-w-0">
                        <p class="text-sm text-gray-700 flex-shrink-0">
                            <span class="font-semibold" x-text="count"></span>
                            reseau(x) :
                        </p>
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="slug in pickedPlatforms" :key="slug">
                                <span class="px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-medium"
                                      x-text="slug"></span>
                            </template>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 flex-shrink-0">
                        <button type="button" @click="picks = {}"
                                class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700">
                            Annuler
                        </button>

                        <form method="POST" action="{{ $showIgnored ? route('external.restore') : route('external.ignore') }}">
                            @csrf
                            <template x-for="id in pickedIds" :key="id">
                                <input type="hidden" name="ids[]" :value="id">
                            </template>
                            <button type="submit"
                                    class="px-4 py-2 text-sm font-medium rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
                                {{ $showIgnored ? 'Remettre dans le flux' : 'Ecarter du flux' }}
                            </button>
                        </form>

                        <button type="button" disabled
                                title="Etape suivante : fusion des publications cochees en une publication RS-Max"
                                class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl opacity-40 cursor-not-allowed">
                            Adopter la selection
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

@endsection

@push('scripts')
<script>
function externalBoard() {
    return {
        // Une seule publication retenue par reseau : c'est la meme publication
        // vue sous plusieurs comptes, pas plusieurs publications a fusionner.
        picks: {},

        pick(slug, id) {
            if (this.picks[slug] === id) {
                delete this.picks[slug];
            } else {
                this.picks[slug] = id;
            }
        },

        isPicked(slug, id) {
            return this.picks[slug] === id;
        },

        get pickedPlatforms() {
            return Object.keys(this.picks);
        },

        get pickedIds() {
            return Object.values(this.picks);
        },

        get count() {
            return Object.keys(this.picks).length;
        },
    };
}
</script>
@endpush
