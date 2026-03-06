@extends('layouts.app')

@section('title', 'Reddit')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">

        {{-- Enregistrer une app --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[#FF4500]/10 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-[#FF4500]" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M14.238 15.348c.085.084.085.221 0 .306-.465.462-1.194.687-2.231.687l-.008-.002-.008.002c-1.036 0-1.766-.225-2.231-.688-.085-.084-.085-.221 0-.305.084-.084.222-.084.307 0 .379.377 1.008.561 1.924.561l.008.002.008-.002c.915 0 1.544-.184 1.924-.561.085-.084.223-.084.307 0zm-3.44-2.418a1.269 1.269 0 0 0-1.27 1.27 1.27 1.27 0 1 0 1.27-1.27zm4.132 0a1.27 1.27 0 1 0 0 2.54 1.27 1.27 0 0 0 0-2.54zM12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.492 13.612c.036.174.055.353.055.536 0 2.726-3.173 4.937-7.088 4.937s-7.088-2.211-7.088-4.937c0-.183.018-.362.055-.536a1.657 1.657 0 0 1-.653-1.315c0-.916.742-1.659 1.659-1.659.443 0 .845.177 1.14.465a8.134 8.134 0 0 1 4.382-1.384l.862-4.067a.279.279 0 0 1 .334-.223l2.874.613a1.14 1.14 0 1 1-.13.611l-2.571-.548-.756 3.563a8.097 8.097 0 0 1 4.327 1.383 1.65 1.65 0 0 1 1.14-.465c.916 0 1.659.743 1.659 1.659 0 .548-.268 1.033-.653 1.315v.036z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Enregistrer une app Reddit</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Entrez vos identifiants de Script App pour la valider.</p>
                    </div>
                </div>
            </div>

            <div class="p-6" x-data="redditAppForm()">
                <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 mb-5">
                    <div class="flex gap-3">
                        <svg class="w-5 h-5 text-orange-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                        </svg>
                        <div class="text-sm text-orange-800">
                            <p class="font-medium mb-1">Comment obtenir les identifiants ?</p>
                            <ol class="list-decimal list-inside space-y-1 text-orange-700">
                                <li>Allez sur <strong>reddit.com/prefs/apps</strong></li>
                                <li>Cliquez sur <strong>"create an app"</strong> ou <strong>"create another app"</strong></li>
                                <li>Sélectionnez <strong>"script"</strong> comme type</li>
                                <li>Remplissez un nom (ex: "RS-Max") et une redirect URI (ex: http://localhost)</li>
                                <li>Le <strong>Client ID</strong> est affiché sous le nom de l'app</li>
                                <li>Le <strong>Client Secret</strong> est affiché à côté de "secret"</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <form action="{{ route('platforms.reddit.registerApp') }}" method="POST" class="space-y-4">
                    @csrf

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="rd_username" class="block text-sm font-medium text-gray-700 mb-1">Nom d'utilisateur Reddit</label>
                            <input type="text" name="username" id="rd_username" value="{{ old('username') }}" required
                                x-model="username" placeholder="votre_username"
                                class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="rd_password" class="block text-sm font-medium text-gray-700 mb-1">Mot de passe</label>
                            <input type="password" name="password" id="rd_password" required
                                x-model="password"
                                class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="rd_client_id" class="block text-sm font-medium text-gray-700 mb-1">Client ID</label>
                            <input type="text" name="client_id" id="rd_client_id" value="{{ old('client_id') }}" required
                                x-model="clientId"
                                class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="rd_client_secret" class="block text-sm font-medium text-gray-700 mb-1">Client Secret</label>
                            <input type="password" name="client_secret" id="rd_client_secret" required
                                x-model="clientSecret"
                                class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>

                    <div class="flex items-center gap-3 justify-end pt-2">
                        <button type="button" @click="validate()" :disabled="validating || !username || !password || !clientId || !clientSecret"
                            class="inline-flex items-center gap-2 px-4 py-2.5 bg-white text-sm font-medium text-gray-700 border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors shadow-sm disabled:opacity-50">
                            <svg x-show="!validating" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <svg x-show="validating" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Tester la connexion
                        </button>
                        <button type="submit" :disabled="!validated"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-900 text-white text-sm font-medium rounded-xl hover:bg-gray-800 transition-colors shadow-sm disabled:opacity-50">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Enregistrer l'app
                        </button>
                    </div>

                    {{-- Validation result --}}
                    <div x-show="validated" x-cloak class="flex items-center gap-3 p-3 bg-green-50 border border-green-200 rounded-xl">
                        <svg class="w-5 h-5 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        <p class="text-sm text-green-800">Connexion réussie pour <strong x-text="'u/' + resolvedUsername"></strong></p>
                    </div>

                    <div x-show="error" x-cloak class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700" x-text="error"></div>
                </form>
            </div>
        </div>

        {{-- Apps existantes avec leurs subreddits --}}
        @forelse($apps as $clientId => $app)
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                {{-- App header --}}
                <div class="px-6 py-4 border-b border-gray-100">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            @if($app->record?->profile_picture_url)
                                <img src="{{ $app->record->profile_picture_url }}" alt="" class="w-8 h-8 rounded-full object-cover flex-shrink-0">
                            @else
                                <x-platform-icon platform="reddit" size="md" />
                            @endif
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">
                                    u/{{ $app->username ?? 'inconnu' }}
                                </h3>
                                <p class="text-xs text-gray-400 font-mono">{{ substr($clientId, 0, 12) }}...</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-400 mr-1">{{ $app->subreddits->count() }} subreddit(s)</span>

                            {{-- Test connection (AJAX) --}}
                            <div x-data="{ testing: false, ok: null, error: null }">
                                <button
                                    type="button"
                                    @click="
                                        if (testing) return;
                                        testing = true; ok = null; error = null;
                                        fetch('{{ route('platforms.reddit.validateAccount') }}', {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/json',
                                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                                'Accept': 'application/json',
                                            },
                                            body: JSON.stringify({
                                                username: @js($app->record?->credentials['username'] ?? ''),
                                                password: @js($app->record?->credentials['password'] ?? ''),
                                                client_id: @js($clientId),
                                                client_secret: @js($app->record?->credentials['client_secret'] ?? ''),
                                            }),
                                        })
                                        .then(r => r.json())
                                        .then(d => {
                                            if (d.success) { ok = true; }
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

                            {{-- Delete app --}}
                            @if(auth()->user()->isAdmin())
                            <form action="{{ route('platforms.reddit.destroyApp') }}" method="POST" onsubmit="return confirm('Supprimer cette app et tous ses subreddits ?')">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="client_id" value="{{ $clientId }}">
                                <button type="submit" class="inline-flex items-center p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer l'app">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                    </svg>
                                </button>
                            </form>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Subreddits list --}}
                @if($app->subreddits->isNotEmpty())
                    <div class="divide-y divide-gray-50">
                        @foreach($app->subreddits as $subreddit)
                            <div class="px-6 py-4 flex items-center justify-between gap-3">
                                <div class="flex items-center gap-3 min-w-0 flex-1">
                                    @if($subreddit->profile_picture_url)
                                        <img src="{{ $subreddit->profile_picture_url }}" alt="{{ $subreddit->name }}" class="w-8 h-8 rounded-full object-cover flex-shrink-0">
                                    @else
                                        <div class="w-8 h-8 rounded-full bg-[#FF4500]/10 flex items-center justify-center flex-shrink-0">
                                            <span class="text-xs font-bold text-[#FF4500]">r/</span>
                                        </div>
                                    @endif
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-900 truncate">{{ $subreddit->name }}</p>
                                        @if($subreddit->followers_count)
                                            <p class="text-xs text-gray-400">{{ number_format($subreddit->followers_count) }} membres</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex items-center gap-3">
                                    {{-- Delete subreddit --}}
                                    @if(auth()->user()->isAdmin())
                                    <form action="{{ route('platforms.destroyAccount', $subreddit) }}" method="POST" onsubmit="return confirm('Supprimer ce subreddit ?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center p-1 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                            </svg>
                                        </button>
                                    </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Quick add subreddit to this app --}}
                @if(auth()->user()->isAdmin())
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100">
                    <form action="{{ route('platforms.reddit.addSubreddit') }}" method="POST" class="flex gap-2">
                        @csrf
                        <input type="hidden" name="client_id" value="{{ $clientId }}">
                        <div class="flex items-center gap-1 flex-1">
                            <span class="text-sm text-gray-400 pl-1">r/</span>
                            <input
                                type="text"
                                name="subreddit"
                                placeholder="nom_du_subreddit"
                                class="flex-1 rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 bg-white"
                                required
                            >
                        </div>
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 bg-indigo-600 text-white text-xs font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Ajouter
                        </button>
                    </form>
                </div>
                @endif
            </div>
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
                <p class="text-sm text-gray-400">Aucune app Reddit configurée. Utilisez le formulaire ci-dessus pour en ajouter une.</p>
            </div>
        @endforelse

    </div>

    @push('scripts')
    <script>
        function redditAppForm() {
            return {
                username: '{{ old("username", "") }}',
                password: '',
                clientId: '{{ old("client_id", "") }}',
                clientSecret: '',
                validating: false,
                validated: false,
                error: null,
                resolvedUsername: '',

                async validate() {
                    this.validating = true;
                    this.validated = false;
                    this.error = null;

                    try {
                        const response = await fetch('{{ route("platforms.reddit.validateAccount") }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                username: this.username,
                                password: this.password,
                                client_id: this.clientId,
                                client_secret: this.clientSecret,
                            }),
                        });

                        const data = await response.json();

                        if (data.success) {
                            this.validated = true;
                            this.resolvedUsername = data.username;
                        } else {
                            this.error = data.error || 'Connexion échouée.';
                        }
                    } catch (e) {
                        this.error = 'Erreur de connexion. Veuillez réessayer.';
                    } finally {
                        this.validating = false;
                    }
                }
            };
        }
    </script>
    @endpush
@endsection
