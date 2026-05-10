@extends('layouts.app')

@section('title', 'Bot Facebook')

@section('actions')
    <div class="flex items-center gap-2">
        <a href="{{ route('bot.logs') }}"
           class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
            </svg>
            Voir les logs
        </a>
        <a href="{{ route('bot.index') }}"
           class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
            </svg>
            Retour
        </a>
    </div>
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
                        <div class="flex items-center gap-3 flex-wrap">
                            <span data-fb-status="{{ $acc->id }}"
                                  class="inline-flex items-center gap-1.5 text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-500">
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400" data-dot></span>
                                <span data-label>—</span>
                                <span data-last class="text-gray-400"></span>
                            </span>
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
        const fbAccountIds = @json($accounts->pluck('id')->all());

        function updateFbFrequency(accountId, frequency) {
            fetch('{{ route("bot.facebook.updateFrequency") }}', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfFb, 'Accept': 'application/json'},
                body: JSON.stringify({account_id: accountId, frequency}),
            });
        }
        function runFb(accountId) {
            setFbBadge(accountId, {active: true, running: true, last_run: null}, 'Demarrage…');
            fetch('{{ route("bot.facebook.run") }}', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfFb, 'Accept': 'application/json'},
                body: JSON.stringify({social_account_id: accountId}),
            }).finally(() => setTimeout(refreshFbStatuses, 1500));
        }
        function stopFb(accountId) {
            setFbBadge(accountId, {active: false, running: false, last_run: null}, 'Arret demande…');
            fetch('{{ route("bot.facebook.stop") }}', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfFb, 'Accept': 'application/json'},
                body: JSON.stringify({account_id: accountId}),
            }).finally(() => setTimeout(refreshFbStatuses, 1500));
        }

        function fbTimeAgo(iso) {
            if (!iso) return '';
            const diff = (Date.now() - new Date(iso).getTime()) / 1000;
            if (diff < 60) return 'il y a ' + Math.floor(diff) + 's';
            if (diff < 3600) return 'il y a ' + Math.floor(diff / 60) + ' min';
            if (diff < 86400) return 'il y a ' + Math.floor(diff / 3600) + ' h';
            return 'il y a ' + Math.floor(diff / 86400) + ' j';
        }
        function setFbBadge(accountId, status, overrideLabel) {
            const el = document.querySelector(`[data-fb-status="${accountId}"]`);
            if (!el) return;
            const dot = el.querySelector('[data-dot]');
            const label = el.querySelector('[data-label]');
            const last = el.querySelector('[data-last]');
            el.classList.remove('bg-gray-100', 'text-gray-500', 'bg-blue-100', 'text-blue-700', 'bg-emerald-100', 'text-emerald-700', 'bg-amber-100', 'text-amber-700');
            dot.classList.remove('bg-gray-400', 'bg-blue-500', 'bg-emerald-500', 'bg-amber-500', 'animate-pulse');
            if (overrideLabel) {
                el.classList.add('bg-amber-100', 'text-amber-700');
                dot.classList.add('bg-amber-500', 'animate-pulse');
                label.textContent = overrideLabel;
                last.textContent = '';
                return;
            }
            if (status.running) {
                el.classList.add('bg-blue-100', 'text-blue-700');
                dot.classList.add('bg-blue-500', 'animate-pulse');
                label.textContent = 'En cours';
            } else if (status.active) {
                el.classList.add('bg-emerald-100', 'text-emerald-700');
                dot.classList.add('bg-emerald-500');
                label.textContent = 'Active';
            } else {
                el.classList.add('bg-gray-100', 'text-gray-500');
                dot.classList.add('bg-gray-400');
                label.textContent = 'Arrete';
            }
            const ago = fbTimeAgo(status.last_run);
            last.textContent = ago ? '· ' + ago : '';
        }
        async function refreshFbStatuses() {
            if (!fbAccountIds.length) return;
            try {
                const r = await fetch('{{ route("bot.facebook.statusBatch") }}', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfFb, 'Accept': 'application/json'},
                    body: JSON.stringify({accounts: fbAccountIds}),
                });
                const data = await r.json();
                Object.entries(data).forEach(([id, st]) => setFbBadge(id, st));
            } catch (e) { /* network blip — ignore */ }
        }
        document.addEventListener('DOMContentLoaded', () => {
            refreshFbStatuses();
            setInterval(refreshFbStatuses, 10000);
        });
    </script>
@endsection
