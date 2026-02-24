@extends('layouts.app')

@section('title', 'Telegram')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">

        {{-- Enregistrer un bot --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[#26A5E4]/10 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-[#26A5E4]" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Enregistrer un bot</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Entrez un token de bot pour le valider et l'enregistrer.</p>
                    </div>
                </div>
            </div>

            <div class="p-6">
                <form action="{{ route('platforms.telegram.registerBot') }}" method="POST" class="flex gap-2">
                    @csrf
                    <input
                        type="text"
                        name="bot_token"
                        value="{{ old('bot_token') }}"
                        placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11"
                        class="flex-1 rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        required
                    >
                    <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-[#26A5E4] text-white text-sm font-medium rounded-xl hover:bg-[#1e96d1] transition-colors shadow-sm whitespace-nowrap">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Enregistrer
                    </button>
                </form>
            </div>
        </div>

        {{-- Bots existants avec leurs canaux --}}
        @forelse($bots as $botToken => $bot)
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                {{-- Bot header --}}
                <div class="px-6 py-4 border-b border-gray-100">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <x-platform-icon platform="telegram" size="md" />
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">
                                    {{ $bot->name ?? 'Bot' }}
                                    @if($bot->username)
                                        <span class="text-gray-400 font-normal">{{ '@' . $bot->username }}</span>
                                    @endif
                                </h3>
                                <p class="text-xs text-gray-400 font-mono">{{ substr($botToken, 0, 10) }}...</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-400 mr-1">{{ $bot->channels->count() }} canal/canaux</span>

                            {{-- Test connection (AJAX) --}}
                            <div x-data="{ testing: false, ok: null, error: null }">
                                <button
                                    type="button"
                                    @click="
                                        if (testing) return;
                                        testing = true; ok = null; error = null;
                                        fetch('{{ route('platforms.telegram.validateBot') }}', {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/json',
                                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                                'Accept': 'application/json',
                                            },
                                            body: JSON.stringify({ bot_token: @js($botToken) }),
                                        })
                                        .then(r => r.json())
                                        .then(d => {
                                            if (d.success) { ok = true; setTimeout(() => location.reload(), 1000); }
                                            else error = d.error || 'Erreur';
                                        })
                                        .catch(() => { error = 'Erreur de connexion.'; })
                                        .finally(() => { testing = false; });
                                    "
                                    :disabled="testing"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors"
                                    :class="ok ? 'border-green-300 bg-green-50 text-green-700' : error ? 'border-red-300 bg-red-50 text-red-700' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'"
                                >
                                    <svg x-show="testing" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <svg x-show="ok && !testing" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                    <span x-text="testing ? 'Test...' : ok ? 'OK' : error ? 'Erreur' : 'Tester'"></span>
                                </button>
                            </div>

                            {{-- Delete bot --}}
                            <form action="{{ route('platforms.telegram.destroyBot') }}" method="POST" onsubmit="return confirm('Supprimer ce bot et tous ses canaux ?')">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="bot_token" value="{{ $botToken }}">
                                <button type="submit" class="inline-flex items-center p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer le bot">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                {{-- Channels list --}}
                @if($bot->channels->isNotEmpty())
                    <div class="divide-y divide-gray-50">
                        @foreach($bot->channels as $channel)
                            <div class="px-6 py-4 flex items-center justify-between gap-3" x-data="{ active: {{ $channel->is_active ? 'true' : 'false' }}, toggling: false }">
                                <div class="flex items-center gap-3 min-w-0 flex-1">
                                    @if($channel->profile_picture_url)
                                        <img src="{{ $channel->profile_picture_url }}" alt="{{ $channel->name }}" class="w-8 h-8 rounded-lg object-cover flex-shrink-0">
                                    @else
                                        <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 8.25h15m-16.5 7.5h15m-1.8-13.5-3.9 19.5m-2.1-19.5-3.9 19.5" />
                                            </svg>
                                        </div>
                                    @endif
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-900 truncate">{{ $channel->name }}</p>
                                        <p class="text-xs text-gray-400">ID: {{ $channel->platform_account_id }}</p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-3">
                                    {{-- Toggle --}}
                                    <button
                                        type="button"
                                        @click="
                                            if (toggling) return;
                                            toggling = true;
                                            fetch('{{ route('accounts.toggle', $channel) }}', {
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

                                    {{-- Delete channel --}}
                                    <form action="{{ route('platforms.destroyAccount', $channel) }}" method="POST" onsubmit="return confirm('Supprimer ce canal ?')">
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

                {{-- Quick add channel to this bot --}}
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100">
                    <form action="{{ route('platforms.telegram.addChannel') }}" method="POST" class="flex gap-2">
                        @csrf
                        <input type="hidden" name="bot_token" value="{{ $botToken }}">
                        <input
                            type="text"
                            name="channel_id"
                            placeholder="Ajouter un canal (@canal ou -100...)"
                            class="flex-1 rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 bg-white"
                            required
                        >
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 bg-indigo-600 text-white text-xs font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Ajouter
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
                <p class="text-sm text-gray-400">Aucun bot Telegram configuré. Utilisez le formulaire ci-dessus pour en ajouter un.</p>
            </div>
        @endforelse

    </div>
@endsection
