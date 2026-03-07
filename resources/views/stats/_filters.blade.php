{{-- Shared stats filter form --}}
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6" x-data="{
    saving: false,
    saved: false,
    async saveDefaults() {
        this.saving = true;
        const checked = [...this.$el.querySelectorAll('input[name=\'accounts[]\']:checked')].map(el => parseInt(el.value));
        try {
            const resp = await fetch('{{ route('accounts.saveDefaults') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ accounts: checked })
            });
            if (resp.ok) {
                this.saved = true;
                setTimeout(() => this.saved = false, 2000);
            }
        } catch(e) {}
        this.saving = false;
    }
}">
    <form method="GET" action="{{ $action }}" class="space-y-4">
        {{-- Filter by accounts --}}
        <div>
            <div class="flex items-center justify-between mb-2">
                <label class="block text-sm font-medium text-gray-700">Comptes sociaux</label>
                <button type="button" @click="saveDefaults()"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors"
                    :class="saved ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    :disabled="saving">
                    <template x-if="!saving && !saved">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0Z" />
                        </svg>
                    </template>
                    <template x-if="saving">
                        <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                    </template>
                    <template x-if="saved">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </template>
                    <span x-text="saved ? 'Enregistré' : 'Enregistrer'"></span>
                </button>
            </div>
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
