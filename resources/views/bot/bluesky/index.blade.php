@extends('layouts.app')

@section('title', 'Bot Bluesky')

@section('actions')
    <a href="{{ route('bot.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
        </svg>
        Retour
    </a>
@endsection

@section('content')
    <div class="space-y-4">

        @if (session('success'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-xl bg-green-50 border border-green-200 p-4 text-sm text-green-700 font-medium">
                {{ session('success') }}
            </div>
        @endif

        @if ($accounts->isEmpty())
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 text-center">
                <p class="text-sm text-gray-500">Aucun compte Bluesky connecte.</p>
            </div>
        @else

            @foreach ($accounts as $account)
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden"
                     x-data="{ tab: 'likes' }">

                    {{-- Header compte --}}
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-3 bg-gray-50">
                        <div class="flex items-center gap-3">
                            @if ($account->profile_picture_url)
                                <img src="{{ $account->profile_picture_url }}" class="w-10 h-10 rounded-full" alt="">
                            @endif
                            <div>
                                <div class="font-medium text-gray-900">{{ $account->name }}</div>
                                <div class="text-xs text-gray-500">@{{ $account->credentials['handle'] ?? $account->platform_account_id }}</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <select onchange="updateBskyFrequency({{ $account->id }}, this.value)"
                                    class="text-sm rounded-md border-gray-300">
                                @php $f = $settings[$account->id]['frequency']; @endphp
                                <option value="disabled" @selected($f === 'disabled')>Desactive</option>
                                <option value="every_15_min" @selected($f === 'every_15_min')>Toutes les 15 min</option>
                                <option value="every_30_min" @selected($f === 'every_30_min')>Toutes les 30 min</option>
                                <option value="hourly" @selected($f === 'hourly')>Toutes les heures</option>
                                <option value="every_2_hours" @selected($f === 'every_2_hours')>Toutes les 2h</option>
                                <option value="every_6_hours" @selected($f === 'every_6_hours')>Toutes les 6h</option>
                                <option value="every_12_hours" @selected($f === 'every_12_hours')>Toutes les 12h</option>
                                <option value="daily" @selected($f === 'daily')>Une fois par jour</option>
                            </select>
                            <button type="button" onclick="runBsky({{ $account->id }})"
                                    class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-md">
                                Lancer
                            </button>
                            <button type="button" onclick="stopBsky({{ $account->id }})"
                                    class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm rounded-md">
                                Stopper
                            </button>
                        </div>
                    </div>

                    {{-- Tabs --}}
                    <nav class="flex border-b border-gray-100 px-2 overflow-x-auto">
                        <button type="button" @click="tab = 'likes'"
                                :class="tab === 'likes' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap">
                            Likes
                        </button>
                        <button type="button" @click="tab = 'comments'"
                                :class="tab === 'comments' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap">
                            Commentaires
                        </button>
                        <button type="button" @click="tab = 'follow'"
                                :class="tab === 'follow' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap">
                            Follow
                        </button>
                        <button type="button" @click="tab = 'prospect'"
                                :class="tab === 'prospect' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap">
                            Prospection
                        </button>
                    </nav>

                    {{-- Panels --}}
                    <div class="p-5">
                        <div x-show="tab === 'likes'" x-cloak>
                            @include('bot.bluesky._likes', ['account' => $account, 'settings' => $settings, 'termsByAccountAndPurpose' => $termsByAccountAndPurpose])
                        </div>
                        <div x-show="tab === 'comments'" x-cloak>
                            @include('bot.bluesky._comments', ['account' => $account, 'settings' => $settings, 'termsByAccountAndPurpose' => $termsByAccountAndPurpose])
                        </div>
                        <div x-show="tab === 'follow'" x-cloak>
                            @include('bot.bluesky._follow', ['account' => $account, 'settings' => $settings, 'termsByAccountAndPurpose' => $termsByAccountAndPurpose])
                        </div>
                        <div x-show="tab === 'prospect'" x-cloak>
                            @include('bot.bluesky._prospect', ['account' => $account, 'targetAccounts' => $targetAccounts])
                        </div>
                    </div>
                </div>
            @endforeach

        @endif
    </div>

    <script>
        const csrfBsky = '{{ csrf_token() }}';
        function updateBskyFrequency(accountId, frequency) {
            fetch('{{ route("bot.bluesky.updateFrequency") }}', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfBsky, 'Accept': 'application/json'},
                body: JSON.stringify({account_id: accountId, frequency}),
            });
        }
        function runBsky(accountId) {
            fetch('{{ route("bot.bluesky.run") }}', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfBsky, 'Accept': 'application/json'},
                body: JSON.stringify({social_account_id: accountId}),
            });
        }
        function stopBsky(accountId) {
            fetch('{{ route("bot.bluesky.stop") }}', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfBsky, 'Accept': 'application/json'},
                body: JSON.stringify({account_id: accountId}),
            });
        }
        function toggleBskyOption(feature, accountId, enabled) {
            fetch('{{ route("bot.bluesky.updateOption") }}', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfBsky, 'Accept': 'application/json'},
                body: JSON.stringify({feature, account_id: accountId, enabled}),
            });
        }
        function updateBskyNumeric(key, accountId, value) {
            fetch('{{ route("bot.bluesky.updateNumeric") }}', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfBsky, 'Accept': 'application/json'},
                body: JSON.stringify({key, account_id: accountId, value: parseInt(value, 10)}),
            });
        }
        async function toggleBskyTerm(termId, btn) {
            const r = await fetch(`/dashboard/bot/bluesky/terms/${termId}/toggle`, {
                method: 'PATCH',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfBsky, 'Accept': 'application/json'},
            });
            const data = await r.json();
            btn.textContent = data.is_active ? 'Actif' : 'Inactif';
            btn.classList.toggle('bg-emerald-100', data.is_active);
            btn.classList.toggle('text-emerald-700', data.is_active);
            btn.classList.toggle('bg-gray-100', !data.is_active);
            btn.classList.toggle('text-gray-500', !data.is_active);
        }
    </script>
@endsection
