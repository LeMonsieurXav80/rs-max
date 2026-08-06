{{--
    Repères de rédaction : compare le brouillon à l'historique publié de CHAQUE
    compte sélectionné, jamais à une moyenne globale — le score d'un post est une
    propriété du couple (post, lecteur), donc seule la comparaison à sa propre
    audience a un sens.

    Composant autonome : il lit le formulaire par le DOM plutôt que de s'accrocher
    à l'état Alpine du composer, pour rester sans effet de bord.
--}}
<div x-data="postBenchmark()" x-init="init()" x-show="accounts.length > 0" x-cloak
     class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:p-8">

    <div class="flex items-center gap-2 mb-1">
        <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
        </svg>
        <h2 class="text-base font-semibold text-gray-900">Repères</h2>
        <span x-show="loading" class="text-xs text-gray-400">calcul…</span>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Comparaison avec les publications passées de chaque compte. Des médianes, pas une prédiction.
    </p>

    <div class="space-y-5">
        <template x-for="acc in accounts" :key="acc.account_id">
            <div>
                <div class="flex items-baseline gap-2 mb-2">
                    <span class="text-sm font-medium text-gray-700" x-text="acc.account_name"></span>
                    <span class="text-xs text-gray-400" x-show="!acc.insufficient"
                          x-text="'médiane ' + fmt(acc.median) + ' · ' + acc.sample + ' publications'"></span>
                </div>

                {{-- Trop peu de données : on le dit, on n'invente pas --}}
                <p x-show="acc.insufficient" class="text-sm text-gray-400"
                   x-text="'Pas encore assez de publications mesurées (' + acc.sample + '/' + acc.min_sample + ').'"></p>

                {{-- Aucune comparaison n'atteint la taille de groupe minimale --}}
                <p x-show="!acc.insufficient && acc.signals.length === 0" class="text-sm text-gray-400">
                    Historique trop homogène pour comparer quoi que ce soit.
                </p>

                <div class="space-y-1.5">
                    <template x-for="s in acc.signals" :key="s.key">
                        <div class="flex items-center gap-3 text-sm">
                            <span class="text-gray-600 w-40 flex-shrink-0" x-text="s.label"></span>
                            <span class="font-medium tabular-nums w-20 text-right"
                                  :class="s.ratio >= 1 ? 'text-green-600' : 'text-gray-500'"
                                  x-text="s.ratio !== null ? (s.ratio >= 1 ? '×' + s.ratio : '×' + s.ratio) : '—'"></span>
                            <span class="text-xs text-gray-400"
                                  x-text="fmt(s.median) + ' contre ' + fmt(s.other_median) + ' (n=' + s.n + ' / ' + s.n_other + ')'"></span>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>
</div>

@push('scripts')
<script>
function postBenchmark() {
    return {
        accounts: [],
        loading: false,
        timer: null,

        init() {
            const form = this.$el.closest('form');
            if (!form) return;

            // Un seul écouteur sur le formulaire : couvre les cases de comptes,
            // le texte, le lien, la date, et les médias ajoutés dynamiquement.
            form.addEventListener('input', () => this.schedule());
            form.addEventListener('change', () => this.schedule());
            this.schedule();
        },

        schedule() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.fetch(), 600);
        },

        async fetch() {
            const form = this.$el.closest('form');
            if (!form) return;

            const checked = [...form.querySelectorAll('input[name="accounts[]"]:checked')].map(i => i.value);
            if (checked.length === 0) { this.accounts = []; return; }

            const payload = new FormData();
            checked.forEach(id => payload.append('accounts[]', id));
            payload.append('content_fr', form.querySelector('[name="content_fr"]')?.value ?? '');
            payload.append('link_url', form.querySelector('[name="link_url"]')?.value ?? '');
            payload.append('has_media', form.querySelector('input[name="media[]"]') ? '1' : '0');
            const when = form.querySelector('[name="scheduled_at"]')?.value;
            if (when) payload.append('scheduled_at', when);

            this.loading = true;
            try {
                const resp = await fetch('{{ route('posts.benchmark') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'Accept': 'application/json',
                    },
                    body: payload,
                });
                if (resp.ok) {
                    const data = await resp.json();
                    this.accounts = data.accounts ?? [];
                }
            } catch (e) {
                // Indicateur d'aide : une panne ici ne doit pas gêner la rédaction.
            }
            this.loading = false;
        },

        fmt(n) {
            return n === null || n === undefined ? '—' : new Intl.NumberFormat('fr-FR').format(n);
        },
    };
}
</script>
@endpush
