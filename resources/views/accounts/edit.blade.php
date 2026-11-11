@extends('layouts.app')

@section('title', 'Modifier le compte')

@section('content')
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('accounts.update', $account) }}">
            @csrf
            @method('PUT')

            {{-- Section : Informations du compte --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                <h2 class="text-base font-semibold text-gray-900 mb-6">Informations du compte</h2>

                {{-- Plateforme (disabled, display only) --}}
                <div class="mb-5">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Plateforme</label>
                    <div class="flex items-center gap-3 px-4 py-2.5 bg-gray-50 rounded-xl border border-gray-200">
                        <x-platform-icon :platform="$account->platform" size="sm" />
                        <span class="text-sm text-gray-700 font-medium">{{ $account->platform->name }}</span>
                    </div>
                    <input type="hidden" name="platform_id" value="{{ $account->platform_id }}" />
                    <p class="mt-1 text-xs text-gray-400">La plateforme ne peut pas etre modifiée après la création.</p>
                </div>

                {{-- Nom du compte --}}
                <div class="mb-5">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Nom du compte <span class="text-red-500">*</span>
                    </label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="{{ old('name', $account->name) }}"
                        required
                        class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                        placeholder="Ex: Mon compte Instagram principal"
                    />
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Langues --}}
                <div class="mb-5">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Langues de publication</label>
                    <p class="text-xs text-gray-400 mb-3">Le contenu sera traduit et publie dans les langues selectionnees.</p>
                    @php
                        $availableLanguages = [
                            'fr' => 'Francais',
                            'en' => 'Anglais',
                            'pt' => 'Portugais',
                            'es' => 'Espagnol',
                            'de' => 'Allemand',
                            'it' => 'Italien',
                        ];
                        $accountLanguages = old('languages', $account->languages ?? ['fr']);
                    @endphp
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @foreach($availableLanguages as $code => $label)
                            <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 hover:bg-indigo-50/50 cursor-pointer transition-colors">
                                <input
                                    type="checkbox"
                                    name="languages[]"
                                    value="{{ $code }}"
                                    {{ in_array($code, $accountLanguages) ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                >
                                <span class="text-sm text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('languages')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @error('languages.*')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                @if($account->platform->slug === 'tiktok')
                    <p class="mt-5 text-xs {{ $account->browser_session_status === 'connected' ? 'text-green-600' : 'text-amber-600' }}">
                        @if($account->browser_session_status === 'connected')
                            Session enregistrée ({{ '@'.$account->browser_username }}), vérifiée {{ $account->browser_session_checked_at?->diffForHumans() }}.
                        @elseif($account->browser_session_status === 'stored')
                            Session reçue et enregistrée. Navigateur suspendu ou non configuré : aucun test TikTok effectué.
                        @else
                            Aucune session active. Ouvre TikTok Studio dans ton Chrome avec ce compte, puis lance « TikTok — transmettre la session à RS-Max » dans Companion.
                        @endif
                    </p>
                @endif

                @if($account->platform->slug === 'twitter')
                    {{-- Mode de connexion --}}
                    <div class="mt-5">
                        <label for="driver" class="block text-sm font-medium text-gray-700 mb-1.5">Mode de connexion</label>
                        @php
                            $connectionMode = old('driver', $account->usesBrowser() ? $account::companionMode($account->browser_profile) : 'api');
                        @endphp
                        <select name="driver" id="driver"
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <option value="api" @selected($connectionMode === 'api')>API X (payante)</option>
                            <option value="browser_vps" @selected($connectionMode === 'browser_vps')>Companion sur le VPS</option>
                            <option value="browser_nas" @selected($connectionMode === 'browser_nas')>Companion sur le NAS</option>
                        </select>
                        <label for="browser_profile" class="block text-sm font-medium text-gray-700 mt-3 mb-1.5">Profil navigateur</label>
                        <input type="text" name="browser_profile" id="browser_profile" value="{{ old('browser_profile', $account->browser_profile) }}" placeholder="vps-… ou nas-…"
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        @error('browser_profile')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-gray-400">
                            Le profil navigateur indique quel Companion traite les publications, les réponses et les statistiques. Les profils VPS commencent par vps-.
                            Les publications planifiées utilisent aussi Companion. Les articles attendent un parcours navigateur ; aucun repli vers l’API.
                            Les comptes qui ont le même profil passent chacun leur tour dans le même navigateur.
                        </p>
                        <p class="mt-1 text-xs {{ $account->browser_session_status === 'connected' ? 'text-green-600' : 'text-amber-600' }}">
                            @if($account->browser_session_status === 'connected')
                                Session connectée{{ $account->browser_username ? ' (@'.$account->browser_username.')' : '' }}{{ $account->browser_session_checked_at ? ', vérifiée '.$account->browser_session_checked_at->diffForHumans() : '' }}.
                            @elseif($account->browser_session_status === 'expired')
                                Session expirée : ouvrez X dans Chrome avec ce compte, l'extension la renverra.
                            @else
                                Aucune session : ouvrez X dans Chrome avec ce compte, puis lancez « X — transmettre la session à RS-Max ».
                            @endif
                        </p>
                        @if($account->browser_followers_observation)
                            <p class="mt-2 text-xs text-gray-500">
                                Abonnés lus dans X : {{ $account->browser_followers_observation['display'] ?? '—' }}
                                ({{ ($account->browser_followers_observation['precision'] ?? '') === 'exact' ? 'valeur exacte' : 'valeur arrondie par X' }}).
                            </p>
                        @endif
                        @error('driver')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                @if($account->platform->slug === 'threads')
                <div class="mt-5">
                    <label for="browser_profile" class="block text-sm font-medium text-gray-700">Profil NAS pour les interactions Threads</label>
                    <input name="browser_profile" id="browser_profile" value="{{ old('browser_profile', $account->browser_profile) }}" placeholder="profil-nas" class="w-full rounded-xl border-gray-300 mt-1">
                    <p class="text-xs text-gray-500 mt-2">Les réponses aux commentaires reçus et les publications restent sur l’API. Recherches, flux, likes et commentaires de publications passent par Companion sur le NAS.</p>
                    <p class="text-xs mt-1">Session NAS : {{ $account->browser_session_status === 'connected' ? 'connectée' : 'à transmettre depuis Companion dans Chrome' }}.</p>
                </div>
                @endif

                {{-- Persona IA --}}
                <div class="mt-5">
                    <label for="persona_id" class="block text-sm font-medium text-gray-700 mb-1.5">Persona des réponses</label>
                    <select name="persona_id" id="persona_id"
                            class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        <option value="">Aucun</option>
                        @foreach($personas as $persona)
                            <option value="{{ $persona->id }}" {{ old('persona_id', $account->persona_id) == $persona->id ? 'selected' : '' }}>
                                {{ $persona->name }}{{ $persona->tone ? ' — ' . $persona->tone : '' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-400">Utilisé pour répondre aux messages et commentaires, et pour poster des commentaires. Jamais pour les publications.</p>
                    @error('persona_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                <h2 class="text-base font-semibold text-gray-900 mb-2">Community Manager : cadence et ciblage</h2>
                <p class="text-xs text-gray-500 mb-5">Réglages propres à ce compte et à ce réseau, accessibles à l’agent par API. Ils ne déclenchent aucune action.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
                    <div>
                        <label for="agent_hourly_limit" class="block text-sm font-medium mb-1">Maximum d’actions sur 60 minutes</label>
                        <input id="agent_hourly_limit" name="agent_hourly_limit" type="number" min="0" max="1000" value="{{ old('agent_hourly_limit', $account->agent_hourly_limit) }}" class="w-full rounded-xl border-gray-300 text-sm">
                        <p class="text-xs text-gray-500 mt-1">Vide : pas de plafond supplémentaire ; 0 : gestes bloqués. Réponse et like comptent séparément.</p>
                    </div>
                    <div>
                        <label for="agent_min_interval_seconds" class="block text-sm font-medium mb-1">Délai minimal entre actions (secondes)</label>
                        <input id="agent_min_interval_seconds" name="agent_min_interval_seconds" type="number" min="0" max="86400" required value="{{ old('agent_min_interval_seconds', $account->agent_min_interval_seconds) }}" class="w-full rounded-xl border-gray-300 text-sm">
                    </div>
                </div>
                <p class="text-sm text-gray-700 mb-5">Sur les 60 dernières minutes : {{ $agentCadence['attempted'] }} tentative(s), {{ $agentCadence['confirmed'] }} confirmée(s). Les échecs et incertitudes consomment aussi la cadence. Hausse progressive seulement si l’adaptation est activée ci-dessous.</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
                    <div>
                        <label class="block text-sm font-medium mb-1" for="agent_daily_limit">Cadence actuelle par jour · Lisbonne</label>
                        <input id="agent_daily_limit" name="agent_daily_limit" type="number" min="0" max="100000" value="{{ old('agent_daily_limit', $account->agent_daily_limit) }}" class="w-full rounded-xl border-gray-300 text-sm">
                        <p class="text-xs text-gray-500 mt-1">{{ $agentCadence['daily']['attempted'] }} tentative(s) aujourd’hui. Vide : sans plafond quotidien supplémentaire.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Plafond autorisé par heure</label>
                        <input name="agent_adaptation[max_hourly]" type="number" min="1" max="1000" value="{{ old('agent_adaptation.max_hourly', $account->agent_adaptation['max_hourly'] ?? '') }}" class="w-full rounded-xl border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Plafond autorisé par jour</label>
                        <input name="agent_adaptation[max_daily]" type="number" min="2" max="100000" value="{{ old('agent_adaptation.max_daily', $account->agent_adaptation['max_daily'] ?? '') }}" class="w-full rounded-xl border-gray-300 text-sm">
                    </div>
                </div>
                <input type="hidden" name="agent_adaptation[enabled]" value="0">
                <label class="flex items-center gap-2 text-sm mb-2"><input type="checkbox" name="agent_adaptation[enabled]" value="1" @checked(old('agent_adaptation.enabled', $account->agent_adaptation['enabled'] ?? false)) class="rounded border-gray-300"> Adapter progressivement la cadence de ce compte</label>
                <p class="text-xs text-gray-500 mb-4">Paliers de 25 % dans les plafonds autorisés, après 10 gestes confirmés et 30 minutes stables. Le délai minimal suit la cadence. Les quotas des réponses et likes activés suivent le plafond quotidien actuel ; les autres actions gardent leur réglage. Aucun volume à remplir.</p>
                @if($account->agent_adaptation_state['cooldown_until'] ?? null)
                    <p class="text-sm text-amber-700 mb-3">Pause jusqu’au {{ $account->agent_adaptation_state['cooldown_until'] }} · {{ $account->agent_adaptation_state['reason'] ?? '' }}</p>
                @endif
                <details class="mb-5 text-sm"><summary class="cursor-pointer">Historique des ajustements de ce compte</summary>
                    @forelse($agentCadence['adaptation']['history'] as $adjustment)
                        <p class="mt-2">{{ $adjustment['created_at'] }} · {{ $adjustment['old_hourly'] ?? '—' }} → {{ $adjustment['new_hourly'] ?? '—' }}/h · {{ $adjustment['old_daily'] ?? '—' }} → {{ $adjustment['new_daily'] ?? '—' }}/jour · {{ $adjustment['reason'] }}</p>
                    @empty<p class="mt-2 text-gray-500">Aucun ajustement.</p>@endforelse
                </details>
                @foreach(['audience' => 'Audience cible', 'objective' => 'Objectif du compte'] as $field => $label)
                    <label for="agent_{{ $field }}" class="block text-sm font-medium mb-1">{{ $label }}</label>
                    <textarea id="agent_{{ $field }}" name="agent_strategy[{{ $field }}]" rows="2" maxlength="{{ $field === 'audience' ? 4000 : 2000 }}" class="w-full rounded-xl border-gray-300 text-sm mb-4">{{ old('agent_strategy.'.$field, $account->agent_strategy[$field] ?? '') }}</textarea>
                @endforeach
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach(\App\Services\Agent\AccountStrategy::LISTS as $field => $label)
                        @php
                            $entries = old('agent_strategy.'.$field, $account->agent_strategy[$field] ?? []);
                        @endphp
                        <div>
                            <label for="agent_{{ $field }}" class="block text-sm font-medium mb-1">{{ $label }}</label>
                            <textarea id="agent_{{ $field }}" name="agent_strategy[{{ $field }}]" rows="4" class="w-full rounded-xl border-gray-300 text-sm" placeholder="Une entrée par ligne">{{ is_array($entries) ? implode("\n", $entries) : $entries }}</textarea>
                        </div>
                    @endforeach
                </div>
                <fieldset class="mt-5">
                    <legend class="text-sm font-semibold">Thèmes du calendrier éditorial</legend>
                    <p class="text-xs text-gray-500 mb-2">Le calendrier affiche tous les événements si aucun thème n’est sélectionné.</p>
                    <input type="hidden" name="agent_strategy[editorial_topics]" value="">
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach(config('editorial_calendar.topics') as $key => $label)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="agent_strategy[editorial_topics][]" value="{{ $key }}"
                                    @checked(in_array($key, (array) old('agent_strategy.editorial_topics', $account->agent_strategy['editorial_topics'] ?? []), true))
                                    class="rounded border-gray-300 text-indigo-600">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                @foreach($errors->getMessages() as $field => $messages)
                    @if(str_starts_with($field, 'agent_'))<p class="text-sm text-red-600 mt-2">{{ implode(' ', $messages) }}</p>@endif
                @endforeach
                <label for="agent_unfollow_after_days" class="block text-sm font-medium mt-4 mb-1">Délai avant un désabonnement (jours)</label>
                <input id="agent_unfollow_after_days" name="agent_strategy[unfollow_after_days]" type="number" min="1" max="365" value="{{ old('agent_strategy.unfollow_after_days', $account->agent_strategy['unfollow_after_days'] ?? 7) }}" class="w-24 rounded-xl border-gray-300 text-sm">
                <p class="text-xs text-gray-500 mt-1">X : uniquement après un abonnement confirmé de l’agent et deux constats récents espacés d’au moins 15 min sans retour d’abonnement. Aucune suppression de suivi n’est lancée en enregistrant cette fiche.</p>
                <p class="text-xs text-gray-500 mt-3">La liste des comptes sert au ciblage. Elle ne lance pas de follow/unfollow.</p>
                <h3 class="text-sm font-semibold mt-6 mb-2">Technique par action</h3>
                <div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead><tr><th class="py-2">Action</th><th>Technique</th><th>Disponibilité</th></tr></thead><tbody>
                @foreach($agentActions as $action)
                    <tr class="border-t border-gray-100"><td class="py-2">{{ $action['label'] }}</td><td>{{ $action['channel'] === 'browser' ? 'Companion · NAS' : 'API officielle' }}</td><td>{{ $action['status'] === 'ready' ? ($action['enabled'] ? 'Autorisée' : 'Désactivée') : ($action['status'] === 'engine' ? 'Moteur dédié' : 'À développer') }}</td></tr>
                @endforeach
                </tbody></table></div>
                <h3 class="text-sm font-semibold mt-6 mb-2">Historique par heure · Lisbonne</h3>
                <div class="overflow-x-auto"><table class="w-full text-sm text-left"><thead><tr><th class="py-2">Heure</th><th>Tentatives</th><th>Confirmées</th><th>Échecs</th><th>À contrôler</th></tr></thead><tbody>
                @forelse($agentHistory as $hour)
                    <tr class="border-t border-gray-100"><td class="py-2">{{ $hour['hour'] }}</td><td>{{ $hour['attempted'] }}</td><td>{{ $hour['confirmed'] }}</td><td>{{ $hour['failed'] }}</td><td>{{ $hour['uncertain'] }}</td></tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">Aucune tentative enregistrée sur les dernières 24 heures.</td></tr>
                @endforelse
                </tbody></table></div>
            </div>

            {{-- Section : Identifiants de connexion --}}
            @php
                $credentialFields = $account->platform->config['credential_fields'] ?? [];
            @endphp

            @if(count($credentialFields) > 0)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
                    <h2 class="text-base font-semibold text-gray-900 mb-2">Identifiants de connexion</h2>
                    <p class="text-xs text-gray-400 mb-6">Ces informations sont chiffrées et stockées en toute sécurité.</p>

                    <div class="space-y-5">
                        @foreach($credentialFields as $field)
                            @php
                                $fieldKey = is_array($field) ? ($field['key'] ?? $field['name'] ?? '') : $field;
                                $fieldLabel = is_array($field) ? ($field['label'] ?? $fieldKey) : $field;
                                $fieldType = is_array($field) ? ($field['type'] ?? 'text') : 'text';
                                $isPassword = $fieldType === 'password';
                            @endphp
                            <div>
                                <label for="credential_{{ $fieldKey }}" class="block text-sm font-medium text-gray-700 mb-1.5">
                                    {{ $fieldLabel }}
                                </label>
                                <input
                                    type="{{ $fieldType }}"
                                    id="credential_{{ $fieldKey }}"
                                    name="credentials[{{ $fieldKey }}]"
                                    value="{{ old('credentials.' . $fieldKey, '') }}"
                                    class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    placeholder="{{ $isPassword ? '••••••••' : $fieldLabel }}"
                                />
                                @if($isPassword)
                                    <p class="mt-1 text-xs text-gray-400">Laisser vide pour garder la valeur actuelle.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @error('credentials')
                        <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @error('credentials.*')
                        <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            {{-- Submit --}}
            <div class="flex items-center gap-4">
                <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                    Mettre à jour
                </button>
                <a href="{{ route('accounts.index') }}" class="text-sm text-gray-500 hover:text-gray-700 transition-colors">Annuler</a>
            </div>
        </form>
    </div>
@endsection
