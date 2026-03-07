@props([
    'accounts',
    'selectedIds' => [],
    'groups' => collect(),
    'name' => 'accounts[]',
    'autoSubmit' => false,
    'dispatchEvent' => null,
    'showSaveButton' => true,
    'label' => 'Comptes',
])

@php
    $groupedByPlatform = $accounts instanceof \Illuminate\Support\Collection
        ? $accounts->groupBy(fn ($a) => $a->platform->slug)
        : collect($accounts)->groupBy(fn ($a) => $a->platform->slug);
    $flatAccounts = $accounts instanceof \Illuminate\Support\Collection ? $accounts : collect($accounts);

    $groupsData = $groups->map(fn ($g) => [
        'id' => $g->id,
        'name' => $g->name,
        'color' => $g->color,
        'account_ids' => $g->socialAccounts->pluck('id')->values()->toArray(),
    ])->values()->toArray();
@endphp

<div x-data="accountSelector({
    groups: {{ json_encode($groupsData) }},
    selectedIds: {{ json_encode(array_map('intval', $selectedIds)) }},
    autoSubmit: {{ $autoSubmit ? 'true' : 'false' }},
    dispatchEvent: {{ $dispatchEvent ? '\'' . $dispatchEvent . '\'' : 'null' }},
})">
    {{-- Header with label + save button --}}
    <div class="flex items-center justify-between mb-2">
        <label class="block text-sm font-medium text-gray-700">{{ $label }}</label>
        <div class="flex items-center gap-2">
            @if($showSaveButton)
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
                <span x-text="saved ? 'Enregistre' : 'Enregistrer'"></span>
            </button>
            @endif
        </div>
    </div>

    {{-- Group pills --}}
    @if($groups->isNotEmpty())
    <div class="flex flex-wrap gap-2 mb-3">
        <template x-for="group in groups" :key="group.id">
            <button type="button" @click="toggleGroup(group)"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors"
                :class="isGroupActive(group) ? 'border-transparent text-white' : 'border-gray-200 text-gray-600 hover:border-gray-300 bg-white'"
                :style="isGroupActive(group) ? 'background-color:' + group.color : ''">
                <div class="w-2 h-2 rounded-full" :style="'background-color:' + (isGroupActive(group) ? '#fff' : group.color)"></div>
                <span x-text="group.name"></span>
                <span class="opacity-60" x-text="'(' + group.account_ids.length + ')'"></span>
            </button>
        </template>
    </div>
    @endif

    {{-- Toggle to show individual accounts --}}
    <div class="mb-2">
        <button type="button" @click="showAccounts = !showAccounts"
            class="inline-flex items-center gap-1.5 text-xs text-gray-500 hover:text-gray-700 transition-colors">
            <svg class="w-3.5 h-3.5 transition-transform" :class="showAccounts && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
            </svg>
            <span x-text="showAccounts ? 'Masquer les comptes' : 'Afficher les comptes (' + checkedCount + ' selectionne(s))'"></span>
        </button>
    </div>

    {{-- Individual accounts grid --}}
    <div x-show="showAccounts" x-transition>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
            @foreach($flatAccounts as $account)
                <label class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer transition-colors"
                       :class="isChecked({{ $account->id }}) ? 'bg-indigo-50 border-indigo-300' : 'border-gray-200 hover:border-indigo-300 bg-white'">
                    <input type="checkbox" name="{{ $name }}" value="{{ $account->id }}"
                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                           :checked="isChecked({{ $account->id }})"
                           @change="toggleAccount({{ $account->id }}, $event.target.checked)"
                           @if($dispatchEvent) data-platform="{{ $account->platform->slug }}" @endif
                           @if(isset($account->persona_id)) data-has-persona="{{ $account->persona_id ? '1' : '0' }}" @endif>
                    <div class="flex items-center gap-2 min-w-0 flex-1">
                        <x-platform-icon :platform="$account->platform->slug" size="sm" />
                        <span class="text-sm truncate">{{ $account->name }}</span>
                    </div>
                </label>
            @endforeach
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
function accountSelector(config) {
    return {
        groups: config.groups,
        checked: new Set(config.selectedIds),
        showAccounts: config.selectedIds.length === 0 && config.groups.length === 0,
        saving: false,
        saved: false,
        autoSubmit: config.autoSubmit,
        dispatchEvent: config.dispatchEvent,

        get checkedCount() {
            return this.checked.size;
        },

        isChecked(id) {
            return this.checked.has(id);
        },

        isGroupActive(group) {
            return group.account_ids.length > 0 && group.account_ids.every(id => this.checked.has(id));
        },

        toggleGroup(group) {
            const allChecked = this.isGroupActive(group);
            group.account_ids.forEach(id => {
                if (allChecked) {
                    this.checked.delete(id);
                } else {
                    this.checked.add(id);
                }
            });
            this.checked = new Set(this.checked);
            this.syncCheckboxes();
            this.afterChange();
        },

        toggleAccount(id, isChecked) {
            if (isChecked) {
                this.checked.add(id);
            } else {
                this.checked.delete(id);
            }
            this.checked = new Set(this.checked);
            this.afterChange();
        },

        syncCheckboxes() {
            this.$el.querySelectorAll('input[type=checkbox][name]').forEach(cb => {
                cb.checked = this.checked.has(parseInt(cb.value));
            });
        },

        afterChange() {
            if (this.dispatchEvent) {
                this.$dispatch(this.dispatchEvent);
            }
            if (this.autoSubmit) {
                this.$nextTick(() => {
                    const form = this.$el.closest('form');
                    if (form) form.submit();
                });
            }
        },

        async saveDefaults() {
            this.saving = true;
            try {
                const resp = await fetch('{{ route("accounts.saveDefaults") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ accounts: [...this.checked] })
                });
                if (resp.ok) {
                    this.saved = true;
                    setTimeout(() => this.saved = false, 2000);
                }
            } catch(e) {}
            this.saving = false;
        },
    };
}
</script>
@endpush
@endonce
