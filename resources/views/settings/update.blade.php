@extends('layouts.app')

@section('title', 'Mise a jour')

@section('content')
    <div class="max-w-3xl space-y-6" x-data="updateManager()">

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Mise a jour de l'application</h2>

            {{-- Current version --}}
            <div class="flex items-center gap-3 mb-6">
                <span class="text-sm text-gray-500">Version actuelle :</span>
                <span class="text-sm font-mono font-medium text-gray-900">{{ $update['local_hash'] ?? 'inconnue' }}</span>
            </div>

            {{-- Update status --}}
            <template x-if="updateAvailable">
                <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 mb-6">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <div>
                            <p class="text-sm font-medium text-amber-800">Nouvelle version disponible</p>
                            <p class="text-sm text-amber-700 mt-1" x-show="remoteHash">
                                Version distante : <span class="font-mono" x-text="remoteHash"></span>
                            </p>
                        </div>
                    </div>
                </div>
            </template>

            <template x-if="!updateAvailable && checkedAt">
                <div class="rounded-xl bg-green-50 border border-green-200 p-4 mb-6">
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        <p class="text-sm text-green-700 font-medium">L'application est a jour.</p>
                    </div>
                </div>
            </template>

            {{-- Changelog --}}
            <template x-if="changelog">
                <div class="mb-6">
                    <h3 class="text-sm font-medium text-gray-700 mb-2">Changements :</h3>
                    <pre class="text-xs text-gray-600 bg-gray-50 rounded-lg p-4 overflow-x-auto whitespace-pre-wrap" x-text="changelog"></pre>
                </div>
            </template>

            {{-- Last check --}}
            <p class="text-xs text-gray-400 mb-6" x-show="checkedAt">
                Derniere verification : <span x-text="checkedAt"></span>
            </p>

            {{-- Actions --}}
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    @click="checkForUpdate()"
                    :disabled="checking"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 transition-colors"
                >
                    <svg class="w-4 h-4" :class="checking && 'animate-spin'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                    </svg>
                    <span x-text="checking ? 'Verification...' : 'Verifier maintenant'"></span>
                </button>

                @if($update['deploy_configured'])
                <template x-if="updateAvailable">
                    <button
                        type="button"
                        @click="deployUpdate()"
                        :disabled="deploying"
                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        <span x-text="deploying ? 'Deploiement en cours...' : 'Mettre a jour'"></span>
                    </button>
                </template>
                @else
                <p class="text-xs text-gray-400">
                    Deploiement automatique non configure. Definissez DEPLOY_API_URL, DEPLOY_API_TOKEN et DEPLOY_APP_UUID.
                </p>
                @endif
            </div>

            {{-- Deploy result message --}}
            <template x-if="deployMessage">
                <div class="mt-4 rounded-lg p-3 text-sm"
                     :class="deploySuccess ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'">
                    <span x-text="deployMessage"></span>
                </div>
            </template>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function updateManager() {
    return {
        updateAvailable: @json($update['available']),
        remoteHash: @json($update['remote_hash']),
        changelog: @json($update['changelog'] ?? null),
        checkedAt: @json($update['checked_at'] ? \Carbon\Carbon::parse($update['checked_at'])->format('d/m/Y H:i') : null),
        checking: false,
        deploying: false,
        deployMessage: null,
        deploySuccess: false,

        async checkForUpdate() {
            this.checking = true;
            try {
                const res = await fetch('{{ route("update.check") }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
                });
                const data = await res.json();
                this.updateAvailable = data.available;
                this.remoteHash = data.remote_hash;
                this.changelog = data.changelog;
                if (data.checked_at) {
                    const d = new Date(data.checked_at);
                    this.checkedAt = d.toLocaleDateString('fr-FR') + ' ' + d.toLocaleTimeString('fr-FR', {hour: '2-digit', minute: '2-digit'});
                }
            } catch (e) {
                console.error(e);
            }
            this.checking = false;
        },

        async deployUpdate() {
            if (!confirm('Lancer la mise a jour ? L\'application sera redemarree.')) return;
            this.deploying = true;
            this.deployMessage = null;
            try {
                const res = await fetch('{{ route("update.deploy") }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
                });
                const data = await res.json();
                this.deploySuccess = data.success;
                this.deployMessage = data.success
                    ? 'Deploiement lance avec succes. L\'application va redemarrer.'
                    : 'Erreur : ' + (data.error || 'Erreur inconnue');
                if (data.success) this.updateAvailable = false;
            } catch (e) {
                this.deploySuccess = false;
                this.deployMessage = 'Erreur de connexion.';
            }
            this.deploying = false;
        }
    };
}
</script>
@endpush
