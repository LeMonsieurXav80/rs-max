@extends('layouts.app')

@section('title', 'Bot Facebook')

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

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-1">Likes des commentaires recus</h2>
            <p class="text-sm text-gray-500 mb-5">
                Le bot Facebook like automatiquement les commentaires recus sur les posts de tes pages.
                Les autres actions (search, follow, prospection) ne sont pas disponibles via l'API Graph.
            </p>

            @forelse ($accounts as $acc)
                <div class="border border-gray-100 rounded-xl p-4 mb-3 last:mb-0">
                    <div class="flex items-center justify-between gap-4 flex-wrap">
                        <div>
                            <div class="font-medium text-gray-900">{{ $acc->name }}</div>
                            <div class="text-xs text-gray-500">{{ $acc->platform_account_id }}</div>
                        </div>
                        <div class="flex items-center gap-3">
                            <select onchange="updateFbFrequency({{ $acc->id }}, this.value)"
                                    class="text-sm rounded-md border-gray-300">
                                <option value="disabled" @selected($settings[$acc->id]['frequency'] === 'disabled')>Desactive</option>
                                <option value="every_15_min" @selected($settings[$acc->id]['frequency'] === 'every_15_min')>Toutes les 15 min</option>
                                <option value="every_30_min" @selected($settings[$acc->id]['frequency'] === 'every_30_min')>Toutes les 30 min</option>
                                <option value="hourly" @selected($settings[$acc->id]['frequency'] === 'hourly')>Toutes les heures</option>
                                <option value="every_2_hours" @selected($settings[$acc->id]['frequency'] === 'every_2_hours')>Toutes les 2h</option>
                                <option value="every_6_hours" @selected($settings[$acc->id]['frequency'] === 'every_6_hours')>Toutes les 6h</option>
                                <option value="every_12_hours" @selected($settings[$acc->id]['frequency'] === 'every_12_hours')>Toutes les 12h</option>
                                <option value="daily" @selected($settings[$acc->id]['frequency'] === 'daily')>Une fois par jour</option>
                            </select>
                            <button type="button" onclick="runFb({{ $acc->id }})"
                                    class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-md">
                                Lancer
                            </button>
                            <button type="button" onclick="stopFb({{ $acc->id }})"
                                    class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm rounded-md">
                                Stopper
                            </button>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500 italic">Aucun compte Facebook connecte.</p>
            @endforelse
        </div>
    </div>

    <script>
        const csrfFb = '{{ csrf_token() }}';
        function updateFbFrequency(accountId, frequency) {
            fetch('{{ route("bot.facebook.updateFrequency") }}', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfFb, 'Accept': 'application/json'},
                body: JSON.stringify({account_id: accountId, frequency}),
            });
        }
        function runFb(accountId) {
            fetch('{{ route("bot.facebook.run") }}', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfFb, 'Accept': 'application/json'},
                body: JSON.stringify({social_account_id: accountId}),
            });
        }
        function stopFb(accountId) {
            fetch('{{ route("bot.facebook.stop") }}', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfFb, 'Accept': 'application/json'},
                body: JSON.stringify({account_id: accountId}),
            });
        }
    </script>
@endsection
