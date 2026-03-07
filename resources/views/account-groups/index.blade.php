@extends('layouts.app')

@section('title', 'Groupes de comptes')

@section('content')
    <div class="max-w-4xl space-y-6" x-data="accountGroupsManager()">

        <div class="flex items-center justify-between">
            <h1 class="text-xl font-bold text-gray-900">Groupes de comptes</h1>
            <button @click="openCreate()"
                class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Nouveau groupe
            </button>
        </div>

        {{-- Groups list --}}
        <div class="space-y-4">
            <template x-for="group in groups" :key="group.id">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <div class="w-3 h-3 rounded-full" :style="'background-color:' + group.color"></div>
                            <h3 class="text-sm font-semibold text-gray-900" x-text="group.name"></h3>
                            <span class="text-xs text-gray-400" x-text="group.social_accounts.length + ' compte(s)'"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button @click="openEdit(group)" class="p-1.5 text-gray-400 hover:text-indigo-600 transition-colors rounded-lg hover:bg-gray-50">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                </svg>
                            </button>
                            <button @click="deleteGroup(group)" class="p-1.5 text-gray-400 hover:text-red-600 transition-colors rounded-lg hover:bg-gray-50">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="account in group.social_accounts" :key="account.id">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-gray-50 border border-gray-200 text-xs text-gray-700">
                                <img x-show="account.profile_picture_url" :src="account.profile_picture_url" class="w-4 h-4 rounded-full" alt="">
                                <span x-text="account.name"></span>
                                <span class="text-gray-400" x-text="account.platform?.slug"></span>
                            </span>
                        </template>
                        <template x-if="group.social_accounts.length === 0">
                            <span class="text-xs text-gray-400 italic">Aucun compte</span>
                        </template>
                    </div>
                </div>
            </template>

            <template x-if="groups.length === 0">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                    </svg>
                    <p class="text-sm text-gray-500">Aucun groupe pour le moment.</p>
                    <p class="text-xs text-gray-400 mt-1">Creez des groupes pour selectionner rapidement vos comptes.</p>
                </div>
            </template>
        </div>

        {{-- Modal create/edit --}}
        <div x-show="showModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showModal = false">
            <div x-show="showModal" x-transition class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[80vh] flex flex-col" @click.stop>
                <div class="p-6 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-900" x-text="editingGroup ? 'Modifier le groupe' : 'Nouveau groupe'"></h2>
                </div>
                <div class="p-6 space-y-4 overflow-y-auto flex-1">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                        <input type="text" x-model="form.name" class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Ex: Marque X, Projet Y...">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Couleur</label>
                        <div class="flex items-center gap-2">
                            <input type="color" x-model="form.color" class="w-8 h-8 rounded-lg border border-gray-300 cursor-pointer p-0.5">
                            <span class="text-xs text-gray-400" x-text="form.color"></span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Comptes</label>
                        <div class="space-y-1.5 max-h-60 overflow-y-auto">
                            @foreach($allAccounts as $account)
                                <label class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-indigo-50/50 transition-colors cursor-pointer">
                                    <input type="checkbox" value="{{ $account->id }}" x-model.number="form.account_ids"
                                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    <x-platform-icon :platform="$account->platform->slug" size="sm" />
                                    <span class="text-sm text-gray-700">{{ $account->name }}</span>
                                    <span class="text-xs text-gray-400">{{ $account->platform->slug }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="p-6 border-t border-gray-100 flex items-center justify-end gap-3">
                    <button @click="showModal = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
                        Annuler
                    </button>
                    <button @click="saveGroup()" :disabled="saving || !form.name.trim()"
                            class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition-colors disabled:opacity-50">
                        <span x-text="saving ? 'Enregistrement...' : (editingGroup ? 'Enregistrer' : 'Creer')"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function accountGroupsManager() {
    return {
        groups: @json($groups),
        allAccounts: @json($allAccounts),
        showModal: false,
        editingGroup: null,
        saving: false,
        form: { name: '', color: '#6366f1', account_ids: [] },

        openCreate() {
            this.editingGroup = null;
            this.form = { name: '', color: '#6366f1', account_ids: [] };
            this.showModal = true;
        },

        openEdit(group) {
            this.editingGroup = group;
            this.form = {
                name: group.name,
                color: group.color,
                account_ids: group.social_accounts.map(a => a.id),
            };
            this.showModal = true;
        },

        async saveGroup() {
            this.saving = true;
            const url = this.editingGroup
                ? `{{ url('account-groups') }}/${this.editingGroup.id}`
                : '{{ route('accountGroups.store') }}';
            const method = this.editingGroup ? 'PUT' : 'POST';

            try {
                const resp = await fetch(url, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(this.form),
                });
                const data = await resp.json();
                if (data.success) {
                    if (this.editingGroup) {
                        const idx = this.groups.findIndex(g => g.id === this.editingGroup.id);
                        if (idx !== -1) this.groups[idx] = data.group;
                    } else {
                        this.groups.push(data.group);
                    }
                    this.showModal = false;
                }
            } catch(e) {}
            this.saving = false;
        },

        async deleteGroup(group) {
            if (!confirm(`Supprimer le groupe "${group.name}" ?`)) return;
            try {
                const resp = await fetch(`{{ url('account-groups') }}/${group.id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                        'Accept': 'application/json',
                    },
                });
                if (resp.ok) {
                    this.groups = this.groups.filter(g => g.id !== group.id);
                }
            } catch(e) {}
        },
    };
}
</script>
@endpush
