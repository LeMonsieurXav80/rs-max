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
        <span x-text="loading ? 'Import en cours…' : 'Rafraichir le flux'"></span>
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

    <div class="mb-6">
        <p class="text-sm text-gray-500">
            Les publications faites directement sur les reseaux, hors RS-Max.
            Celles publiees par RS-Max sont automatiquement masquees.
            @if($pendingCount > 0)
                <span class="font-medium text-gray-700">{{ $pendingCount }} en attente de rattachement.</span>
            @endif
        </p>
    </div>

    {{-- Filtres --}}
    <form method="GET" action="{{ route('external.index') }}" class="mb-6 bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
        <div class="flex flex-wrap items-end gap-4">
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

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1.5">Comptes</label>
                <select name="accounts[]" multiple size="1"
                        class="rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500 min-w-[200px]">
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" @selected(in_array($account->id, $selectedAccounts))>
                            {{ $account->name }} ({{ $account->platform->name }})
                        </option>
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
                Filtrer
            </button>
        </div>
    </form>

    @if($importableAccounts->isEmpty())
        <div class="mb-6 rounded-xl bg-amber-50 border border-amber-200 p-4">
            <p class="text-sm text-amber-800">
                Aucun de vos comptes ne dispose d'un import automatique.
                Seuls Facebook, Instagram, Twitter/X, Threads, Bluesky et YouTube sont couverts.
            </p>
        </div>
    @endif

    @if($externalPosts->isEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
            <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-6">
                <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900 mb-2">
                {{ $showIgnored ? 'Aucune publication ecartee' : 'Aucune publication native' }}
            </h3>
            <p class="text-sm text-gray-500">
                {{ $showIgnored
                    ? 'Rien n\'a ete ecarte du flux sur cette periode.'
                    : 'Lancez « Rafraichir le flux » pour aller chercher les dernieres publications sur vos reseaux.' }}
            </p>
        </div>
    @else
        <div x-data="{ selected: [] }">
            {{-- Grille de cartes --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 pb-24">
                @foreach($externalPosts as $externalPost)
                    @php
                        $thumbnail = $externalPost->thumbnailUrl();
                        $mediaItems = $externalPost->mediaItems();
                        $metrics = $externalPost->getFormattedMetrics();
                    @endphp

                    <label
                        class="group relative bg-white rounded-2xl shadow-sm border transition-colors cursor-pointer overflow-hidden"
                        :class="selected.includes({{ $externalPost->id }}) ? 'border-indigo-500 ring-2 ring-indigo-100' : 'border-gray-100 hover:border-gray-300'"
                    >
                        <input type="checkbox" value="{{ $externalPost->id }}" x-model.number="selected" class="sr-only">

                        {{-- Vignette --}}
                        <div class="relative aspect-square bg-gray-50">
                            @if($thumbnail)
                                <img src="{{ $thumbnail }}" alt="" loading="lazy" class="w-full h-full object-cover">
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <svg class="w-10 h-10 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5M6 7.5h3v3H6v-3Z" />
                                    </svg>
                                </div>
                            @endif

                            {{-- Coche --}}
                            <div class="absolute top-2 left-2 w-6 h-6 rounded-full border-2 border-white shadow flex items-center justify-center transition-colors"
                                 :class="selected.includes({{ $externalPost->id }}) ? 'bg-indigo-600' : 'bg-white/70'">
                                <svg class="w-3.5 h-3.5 text-white" x-show="selected.includes({{ $externalPost->id }})" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                            </div>

                            {{-- Badges --}}
                            <div class="absolute top-2 right-2 flex items-center gap-1.5">
                                @if(count($mediaItems) > 1)
                                    <span class="px-1.5 py-0.5 rounded-md bg-black/60 text-white text-[10px] font-medium">
                                        {{ count($mediaItems) }} medias
                                    </span>
                                @endif
                                @if(($mediaItems[0]['type'] ?? null) === 'video')
                                    <span class="px-1.5 py-0.5 rounded-md bg-black/60 text-white text-[10px] font-medium">Video</span>
                                @endif
                                <x-platform-icon :platform="$externalPost->platform" size="sm" />
                            </div>
                        </div>

                        {{-- Corps --}}
                        <div class="p-3">
                            <div class="flex items-center justify-between gap-2 mb-1.5">
                                <span class="text-xs font-medium text-gray-700 truncate">{{ $externalPost->socialAccount?->name }}</span>
                                <span class="text-[11px] text-gray-400 flex-shrink-0">
                                    {{ $externalPost->published_at?->format('d/m/Y') }}
                                </span>
                            </div>

                            <p class="text-xs text-gray-500 line-clamp-3 min-h-[3rem]">
                                {{ $externalPost->content ?: '(sans texte)' }}
                            </p>

                            <div class="mt-2.5 pt-2.5 border-t border-gray-50 flex items-center justify-between">
                                <div class="flex items-center gap-3 text-[11px] text-gray-400">
                                    <span>{{ $metrics['views'] }} vues</span>
                                    <span>{{ $metrics['likes'] }} j'aime</span>
                                </div>
                                @if($externalPost->post_url)
                                    <a href="{{ $externalPost->post_url }}" target="_blank" rel="noopener"
                                       @click.stop
                                       class="text-[11px] text-indigo-600 hover:text-indigo-700 font-medium">
                                        Voir
                                    </a>
                                @endif
                            </div>
                        </div>
                    </label>
                @endforeach
            </div>

            {{-- Barre de selection --}}
            <div x-show="selected.length > 0" x-cloak
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="fixed bottom-0 left-0 right-0 lg:left-64 z-40 bg-white border-t border-gray-200 shadow-lg px-6 py-4">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold" x-text="selected.length"></span>
                        publication(s) selectionnee(s)
                    </p>

                    <div class="flex items-center gap-2">
                        <button type="button" @click="selected = []"
                                class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700">
                            Annuler
                        </button>

                        <form method="POST" action="{{ $showIgnored ? route('external.restore') : route('external.ignore') }}">
                            @csrf
                            <template x-for="id in selected" :key="id">
                                <input type="hidden" name="ids[]" :value="id">
                            </template>
                            <button type="submit"
                                    class="px-4 py-2 text-sm font-medium rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors">
                                {{ $showIgnored ? 'Remettre dans le flux' : 'Ecarter du flux' }}
                            </button>
                        </form>

                        <button type="button" disabled
                                title="Etape suivante : fusion des cartes cochees en une publication RS-Max"
                                class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl opacity-40 cursor-not-allowed">
                            Adopter la selection
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6">
            {{ $externalPosts->links() }}
        </div>
    @endif

@endsection
