@extends('layouts.app')

@section('title', 'Twitter / X')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">

        {{-- Ajouter un compte --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gray-100 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-gray-900" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Ajouter un compte</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Renseignez vos clés API Twitter / X.</p>
                    </div>
                </div>
            </div>

            <div class="p-6">
                <form action="{{ route('platforms.twitter.addAccount') }}" method="POST" class="space-y-4">
                    @csrf

                    <div>
                        <label for="tw_name" class="block text-sm font-medium text-gray-700 mb-1">Nom du compte</label>
                        <input type="text" name="name" id="tw_name" value="{{ old('name') }}" placeholder="Mon compte X" required
                            class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="tw_api_key" class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
                            <input type="text" name="api_key" id="tw_api_key" value="{{ old('api_key') }}" required
                                class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="tw_api_secret" class="block text-sm font-medium text-gray-700 mb-1">API Secret</label>
                            <input type="password" name="api_secret" id="tw_api_secret" required
                                class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="tw_access_token" class="block text-sm font-medium text-gray-700 mb-1">Access Token</label>
                            <input type="text" name="access_token" id="tw_access_token" value="{{ old('access_token') }}" required
                                class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="tw_access_token_secret" class="block text-sm font-medium text-gray-700 mb-1">Access Token Secret</label>
                            <input type="password" name="access_token_secret" id="tw_access_token_secret" required
                                class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-900 text-white text-sm font-medium rounded-xl hover:bg-gray-800 transition-colors shadow-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Ajouter le compte
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Applications existantes avec leurs comptes --}}
        @forelse($apps as $apiKey => $appAccounts)
            @php
                $first = $appAccounts->first();
                $keyPreview = substr($apiKey, 0, 10) . '...';
            @endphp

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                {{-- App header --}}
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <x-platform-icon platform="twitter" size="md" />
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Application Twitter</h3>
                            <p class="text-xs text-gray-400 font-mono">{{ $keyPreview }}</p>
                        </div>
                    </div>
                    <span class="text-xs text-gray-400">{{ $appAccounts->count() }} compte(s)</span>
                </div>

                {{-- Accounts list --}}
                @if($appAccounts->isNotEmpty())
                    <div class="divide-y divide-gray-50">
                        @foreach($appAccounts as $account)
                            <div class="px-6 py-4 flex items-center justify-between gap-3" x-data="{ active: {{ $account->is_active ? 'true' : 'false' }}, toggling: false, testing: false, testOk: null, testError: null }">
                                <div class="flex items-center gap-3 min-w-0 flex-1">
                                    @if($account->profile_picture_url)
                                        <img src="{{ $account->profile_picture_url }}" alt="{{ $account->name }}" class="w-9 h-9 rounded-lg object-cover flex-shrink-0">
                                    @else
                                        <div class="w-9 h-9 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                            </svg>
                                        </div>
                                    @endif
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-900 truncate">{{ $account->name }}</p>
                                        @if($account->platform_account_id)
                                            <p class="text-xs text-gray-400">{{ '@' }}{{ $account->platform_account_id }}</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    {{-- Test connection --}}
                                    <button
                                        type="button"
                                        @click="
                                            if (testing) return;
                                            testing = true; testOk = null; testError = null;
                                            fetch('{{ route('platforms.twitter.validateAccount') }}', {
                                                method: 'POST',
                                                headers: {
                                                    'Content-Type': 'application/json',
                                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                                    'Accept': 'application/json',
                                                },
                                                body: JSON.stringify({ account_id: {{ $account->id }} }),
                                            })
                                            .then(r => r.json())
                                            .then(d => {
                                                if (d.success) { testOk = d.username ? ('@' + d.username) : 'OK'; setTimeout(() => location.reload(), 1500); }
                                                else testError = d.error || 'Erreur';
                                            })
                                            .catch(() => { testError = 'Erreur de connexion.'; })
                                            .finally(() => { testing = false; });
                                        "
                                        :disabled="testing"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors"
                                        :class="testOk ? 'border-green-300 bg-green-50 text-green-700' : testError ? 'border-red-300 bg-red-50 text-red-700' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'"
                                    >
                                        <svg x-show="testing" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                        <svg x-show="testOk && !testing" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                        <span x-text="testing ? 'Test...' : testOk ? testOk : testError ? 'Erreur' : 'Tester'"></span>
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

                                    {{-- Delete account --}}
                                    <form action="{{ route('platforms.destroyAccount', $account) }}" method="POST" onsubmit="return confirm('Supprimer ce compte ?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center p-1 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Quick add account to this app --}}
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100">
                    <form action="{{ route('platforms.twitter.addAccount') }}" method="POST" class="space-y-3">
                        @csrf
                        <input type="hidden" name="api_key" value="{{ $apiKey }}">
                        <input type="hidden" name="api_secret" value="{{ $first->credentials['api_secret'] ?? '' }}">
                        <div class="flex gap-2">
                            <input
                                type="text"
                                name="name"
                                placeholder="Nom du compte"
                                class="flex-1 rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 bg-white"
                                required
                            >
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <input
                                type="text"
                                name="access_token"
                                placeholder="Access Token"
                                class="rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 bg-white"
                                required
                            >
                            <input
                                type="password"
                                name="access_token_secret"
                                placeholder="Access Token Secret"
                                class="rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 bg-white"
                                required
                            >
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 bg-gray-900 text-white text-xs font-medium rounded-xl hover:bg-gray-800 transition-colors shadow-sm">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                Ajouter un compte
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
                <p class="text-sm text-gray-400">Aucune application Twitter configurée. Utilisez le formulaire ci-dessus pour en ajouter une.</p>
            </div>
        @endforelse

    </div>
@endsection
