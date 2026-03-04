{{-- Shared stats filter form --}}
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
    <form method="GET" action="{{ $action }}" class="space-y-4">
        {{-- Filter by accounts --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Comptes sociaux</label>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                @foreach($socialAccounts as $account)
                    <label class="flex items-center gap-2 p-3 rounded-xl border border-gray-200 hover:border-indigo-300 cursor-pointer transition-colors {{ in_array($account->id, $selectedAccounts) ? 'bg-indigo-50 border-indigo-300' : 'bg-white' }}">
                        <input type="checkbox" name="accounts[]" value="{{ $account->id }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" {{ in_array($account->id, $selectedAccounts) ? 'checked' : '' }}>
                        <div class="flex items-center gap-2 min-w-0 flex-1">
                            <x-platform-icon :platform="$account->platform->slug" size="sm" />
                            <span class="text-sm truncate">{{ $account->name }}</span>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- Period filter --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Période</label>
                <select name="period" class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="7" {{ $period == '7' ? 'selected' : '' }}>7 derniers jours</option>
                    <option value="30" {{ $period == '30' ? 'selected' : '' }}>30 derniers jours</option>
                    <option value="90" {{ $period == '90' ? 'selected' : '' }}>90 derniers jours</option>
                    <option value="365" {{ $period == '365' ? 'selected' : '' }}>12 derniers mois</option>
                    <option value="all" {{ $period == 'all' ? 'selected' : '' }}>Tout</option>
                    @if(isset($startDate) && isset($endDate) && $startDate && $endDate)
                        <option value="custom" selected>Personnalisé</option>
                    @else
                        <option value="custom">Personnalisé</option>
                    @endif
                </select>
            </div>
            @if(isset($startDate))
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Date début</label>
                    <input type="date" name="start_date" value="{{ $startDate }}" class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Date fin</label>
                    <input type="date" name="end_date" value="{{ $endDate }}" class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            @endif
        </div>

        {{-- Submit --}}
        <div class="flex items-center gap-3">
            <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                Filtrer
            </button>
            <a href="{{ $action }}" class="inline-flex items-center gap-2 px-4 py-2 bg-white text-gray-700 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors border border-gray-200">
                Réinitialiser
            </a>
        </div>
    </form>
</div>
