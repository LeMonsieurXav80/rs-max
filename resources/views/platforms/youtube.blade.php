@extends('layouts.app')

@section('title', 'YouTube')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">

        {{-- Warning message --}}
        <div class="rounded-xl bg-amber-50 border border-amber-200 p-4">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                <div>
                    <h3 class="text-sm font-medium text-amber-800">Information importante</h3>
                    <p class="mt-1 text-sm text-amber-700">
                        Seul le <strong>propriétaire</strong> de la chaîne YouTube peut autoriser l'accès API.
                        Les managers et éditeurs ne peuvent pas connecter la chaîne via OAuth.
                    </p>
                </div>
            </div>
        </div>

        {{-- Connexion OAuth --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-red-50 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Connexion OAuth</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Liez votre chaîne YouTube pour publier automatiquement vos vidéos.</p>
                    </div>
                </div>
            </div>

            <div class="p-6 space-y-3">
                <div class="text-sm text-gray-600">
                    <p class="font-medium text-gray-900 mb-2">Avant de commencer:</p>
                    <ul class="list-disc list-inside space-y-1 text-gray-600">
                        <li>Vous devez être le <strong>propriétaire</strong> de la chaîne YouTube</li>
                        <li>Les vidéos seront uploadées sur votre chaîne</li>
                        <li>Quota API: ~100 uploads par jour (limite gratuite)</li>
                    </ul>
                </div>

                <a href="{{ route('youtube.redirect') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-red-600 text-white text-sm font-medium rounded-xl hover:bg-red-700 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m9.86-2.06a4.5 4.5 0 0 0-1.242-7.244l-4.5-4.5a4.5 4.5 0 0 0-6.364 6.364L4.757 8.81" />
                    </svg>
                    Connecter ma chaîne YouTube
                </a>
            </div>
        </div>

        {{-- Chaînes YouTube connectées --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">Chaînes YouTube</h3>
                <span class="text-xs text-gray-400">{{ $accounts->count() }} chaîne(s)</span>
            </div>

            @if($accounts->isEmpty())
                <div class="px-6 py-8 text-center">
                    <p class="text-sm text-gray-400">Aucune chaîne YouTube connectée.</p>
                </div>
            @else
                <div class="divide-y divide-gray-50">
                    @foreach($accounts as $account)
                        <div class="px-6 py-4 flex items-center justify-between gap-3" x-data="{
                            active: {{ $account->is_active ? 'true' : 'false' }},
                            toggling: false,
                            importing: false,
                            showImportModal: false,
                            importInfo: null,
                            importResult: null
                        }">
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                @if($account->profile_picture_url)
                                    <img src="{{ $account->profile_picture_url }}" alt="{{ $account->name }}" class="w-9 h-9 rounded-full object-cover flex-shrink-0">
                                @else
                                    <x-platform-icon platform="youtube" size="lg" />
                                @endif
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $account->name }}</p>
                                    <p class="text-xs text-gray-400">ID: {{ $account->platform_account_id }}</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                {{-- Import History --}}
                                <button
                                    type="button"
                                    @click="
                                        fetch('{{ route('accounts.import.info', $account) }}?limit=50', {
                                            headers: { 'Accept': 'application/json' }
                                        })
                                        .then(r => r.json())
                                        .then(d => { importInfo = d; showImportModal = true; })
                                        .catch(e => alert('Erreur lors de la récupération des informations'));
                                    "
                                    class="inline-flex items-center p-1 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                                    title="Importer l'historique"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                    </svg>
                                </button>

                                {{-- Toggle --}}
                                <button
                                    type="button"
                                    @click="
                                        if (toggling) return;
                                        toggling = true;
                                        fetch('{{ route('accounts.toggle', $account) }}', {
                                            method: 'PATCH',
                                            headers: {
                                                'Content-Type': 'application/json',
                                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                                'Accept': 'application/json',
                                            },
                                        })
                                        .then(r => r.json())
                                        .then(d => { if (d.success) active = d.is_active; })
                                        .catch(() => {})
                                        .finally(() => { toggling = false; });
                                    "
                                    :class="active ? 'bg-indigo-600' : 'bg-gray-200'"
                                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
                                    role="switch"
                                    :aria-checked="active"
                                    :title="active ? 'Désactiver' : 'Activer'"
                                >
                                    <span :class="active ? 'translate-x-5' : 'translate-x-0'" class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
                                </button>

                                {{-- Delete --}}
                                <form action="{{ route('platforms.destroyAccount', $account) }}" method="POST" onsubmit="return confirm('Supprimer cette chaîne YouTube ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="inline-flex items-center p-1 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                        </svg>
                                    </button>
                                </form>
                            </div>

                            {{-- Import Modal with YouTube-specific warning --}}
                            <div x-show="showImportModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-black/30 backdrop-blur-sm" @click.self="showImportModal = false">
                                <div class="flex min-h-full items-center justify-center p-4">
                                    <div class="relative bg-white rounded-2xl shadow-xl max-w-md w-full p-6" @click.stop>
                                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Importer l'historique YouTube</h3>

                                        {{-- YouTube-specific warning --}}
                                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4">
                                            <p class="text-xs text-amber-800">⚠️ YouTube a un quota API strict. L'import est limité à 1 fois par semaine pour protéger votre quota.</p>
                                        </div>

                                        <template x-if="importInfo && !importInfo.can_import">
                                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                                                <p class="text-sm text-yellow-800" x-text="importInfo.cooldown_reason"></p>
                                            </div>
                                        </template>

                                        <template x-if="importInfo && importInfo.can_import">
                                            <div>
                                                <div class="space-y-3 mb-6">
                                                    <div class="flex justify-between text-sm">
                                                        <span class="text-gray-600">Vidéos à importer:</span>
                                                        <span class="font-medium text-gray-900">50</span>
                                                    </div>
                                                    <div class="flex justify-between text-sm" x-show="importInfo.quota_cost > 0">
                                                        <span class="text-gray-600">Coût API:</span>
                                                        <span class="font-medium text-gray-900" x-text="importInfo.quota_description"></span>
                                                    </div>
                                                    <div class="flex justify-between text-sm" x-show="importInfo.last_import">
                                                        <span class="text-gray-600">Dernier import:</span>
                                                        <span class="font-medium text-gray-900" x-text="importInfo.last_import"></span>
                                                    </div>
                                                </div>

                                                <template x-if="!importResult">
                                                    <div class="flex gap-3">
                                                        <button
                                                            type="button"
                                                            @click="showImportModal = false"
                                                            class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors"
                                                        >
                                                            Annuler
                                                        </button>
                                                        <button
                                                            type="button"
                                                            @click="
                                                                importing = true;
                                                                fetch('{{ route('accounts.import', $account) }}', {
                                                                    method: 'POST',
                                                                    headers: {
                                                                        'Content-Type': 'application/json',
                                                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                                                        'Accept': 'application/json',
                                                                    },
                                                                    body: JSON.stringify({ limit: 50 })
                                                                })
                                                                .then(r => r.json())
                                                                .then(d => { importResult = d; })
                                                                .catch(e => { importResult = { success: false, error: 'Erreur réseau' }; })
                                                                .finally(() => { importing = false; });
                                                            "
                                                            :disabled="importing"
                                                            class="flex-1 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-50"
                                                        >
                                                            <span x-show="!importing">Importer</span>
                                                            <span x-show="importing" class="inline-flex items-center gap-2">
                                                                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                                                Import en cours...
                                                            </span>
                                                        </button>
                                                    </div>
                                                </template>

                                                <template x-if="importResult">
                                                    <div>
                                                        <div :class="importResult.success ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'" class="border rounded-lg p-4 mb-4">
                                                            <p class="text-sm" :class="importResult.success ? 'text-green-800' : 'text-red-800'" x-text="importResult.success ? importResult.message : importResult.error"></p>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            @click="showImportModal = false; importResult = null;"
                                                            class="w-full px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors"
                                                        >
                                                            Fermer
                                                        </button>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>

                                        <template x-if="!importInfo">
                                            <p class="text-sm text-gray-500">Chargement...</p>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>
@endsection
