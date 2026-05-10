@php
    $commentTerms = $termsByAccountAndPurpose[$account->id.'_comments'] ?? collect();
    $s = $settings[$account->id];
    $persona = $account->persona;
    $stats = $commentStats[$account->id] ?? ['last_run' => null, 'posted_24h' => 0, 'failed_24h' => 0, 'llm' => ['mode' => 'none', 'label' => null, 'key_ok' => false]];
    $lastRunHuman = $stats['last_run'] ? \Carbon\Carbon::parse($stats['last_run'])->diffForHumans() : null;
    $llm = $stats['llm'];
@endphp

<div class="space-y-5">
    @if (! $persona)
        <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
            Aucun persona n'est associe a ce compte. <a href="{{ route('personas.index') }}" class="underline font-medium">Configure un persona</a> et lie-le au compte.
        </div>
    @elseif (! $persona->hasBotComments())
        <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
            Le persona <strong>{{ $persona->name }}</strong> n'a aucun contexte de commentaire rempli.
            <a href="{{ route('personas.edit', $persona) }}" class="underline font-medium">Renseigne au moins un contexte</a> (texte / article / image) pour activer les commentaires bot.
        </div>
    @else
        <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-4 text-sm text-emerald-800">
            Persona actif : <strong>{{ $persona->name }}</strong>. Longueur max commentaire : {{ $persona->bot_comment_max_length ?? 280 }} caracteres.
            <a href="{{ route('personas.edit', $persona) }}" class="underline">Modifier</a>
        </div>
    @endif

    {{-- Diagnostic : est-ce que ca tourne ? --}}
    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
        <div class="flex items-center justify-between flex-wrap gap-3 mb-2">
            <h4 class="text-sm font-semibold text-gray-900">Diagnostic</h4>
            <a href="{{ route('bot.logs', ['account' => $account->id, 'type' => 'comment_keyword']) }}"
               class="text-xs text-indigo-600 hover:text-indigo-700 underline">
                Voir les logs commentaires de ce compte →
            </a>
        </div>

        {{-- Bandeau LLM : si rien n'est configure ou cle manquante, le bot ne pourra rien generer --}}
        @if ($llm['mode'] === 'none')
            <div class="mb-3 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-xs text-red-800">
                <strong>Aucun LLM configure.</strong> Aucun commentaire ne sera genere.
                <a href="{{ route('settings.index', ['tab' => 'ia_libre']) }}" class="underline font-medium">
                    Configure un LLM gratuit
                </a>
                ou ajoute une cle OpenAI dans l'onglet Contenu IA.
            </div>
        @elseif (! $llm['key_ok'])
            <div class="mb-3 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-xs text-red-800">
                <strong>Modele defini :</strong> <code class="bg-white px-1 rounded">{{ $llm['label'] }}</code>
                — mais la cle API du provider est <strong>manquante en BDD</strong>.
                <a href="{{ route('settings.index', ['tab' => 'ia_libre']) }}" class="underline font-medium">
                    Verifie tes cles dans Settings
                </a>
                (n'oublie pas de cliquer "Enregistrer les parametres" en bas).
            </div>
        @else
            <div class="mb-3 rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 text-xs text-emerald-800">
                <strong>LLM :</strong> <code class="bg-white px-1 rounded">{{ $llm['label'] }}</code> — cle OK.
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
            <div class="bg-white rounded-lg p-3 border border-gray-100">
                <div class="text-xs text-gray-500">Derniere execution du bot</div>
                <div class="font-medium text-gray-900 mt-0.5">
                    @if ($lastRunHuman)
                        {{ $lastRunHuman }}
                    @else
                        <span class="text-amber-600">jamais</span>
                    @endif
                </div>
            </div>
            <div class="bg-white rounded-lg p-3 border border-gray-100">
                <div class="text-xs text-gray-500">Commentaires postes (24h)</div>
                <div class="font-medium {{ $stats['posted_24h'] > 0 ? 'text-emerald-700' : 'text-gray-400' }} mt-0.5">
                    {{ $stats['posted_24h'] }}
                </div>
            </div>
            <div class="bg-white rounded-lg p-3 border border-gray-100">
                <div class="text-xs text-gray-500">Echecs (24h)</div>
                <div class="font-medium {{ $stats['failed_24h'] > 0 ? 'text-red-600' : 'text-gray-400' }} mt-0.5">
                    {{ $stats['failed_24h'] }}
                </div>
            </div>
        </div>
        @if ($lastRunHuman && $stats['posted_24h'] === 0 && $stats['failed_24h'] === 0)
            <p class="text-xs text-gray-500 mt-3">
                Le bot a tourne mais n'a poste aucun commentaire sur les 24 dernieres heures.
                Causes possibles : aucun post trouve pour les mots-cles, posts deja commentes,
                ou aucun contexte rempli dans le persona pour le type de post (texte/article/image).
                Verifie aussi <code class="bg-white px-1 rounded">storage/logs/laravel.log</code> pour
                d'eventuelles erreurs de generation IA.
            </p>
        @endif
    </div>

    <label class="flex items-center gap-3 p-4 bg-gray-50 rounded-xl">
        <input type="checkbox" {{ $s['comments_keyword'] ? 'checked' : '' }}
               onchange="toggleBskyOption('comments_keyword', {{ $account->id }}, this.checked)"
               class="rounded border-gray-300 text-indigo-600">
        <div class="flex-1">
            <div class="text-sm font-medium text-gray-900">Activer les commentaires automatiques par mot-cle</div>
            <div class="text-xs text-gray-500">Le bot recherche les mots-cles ci-dessous et poste un commentaire IA adapte au type de post.</div>
        </div>
        <div class="text-xs text-gray-500">Max / run :</div>
        <input type="number" min="1" max="20" value="{{ $s['comments_max'] }}"
               onchange="updateBskyNumeric('comments_max', {{ $account->id }}, this.value)"
               class="w-20 text-sm rounded border-gray-300">
    </label>

    <div>
        <h3 class="text-sm font-semibold text-gray-900 mb-2">Mots-cles a commenter</h3>

        @if ($commentTerms->isEmpty())
            <p class="text-sm text-gray-400 italic mb-3">Aucun mot-cle pour les commentaires.</p>
        @else
            <div class="flex flex-wrap gap-2 mb-3">
                @foreach ($commentTerms as $t)
                    <div class="inline-flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-full pl-3 pr-1 py-1">
                        <span class="text-sm text-gray-700">{{ $t->term }}</span>
                        <button type="button" onclick="toggleBskyTerm({{ $t->id }}, this)"
                                class="text-xs px-2 py-0.5 rounded-full {{ $t->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $t->is_active ? 'Actif' : 'Inactif' }}
                        </button>
                        <form method="POST" action="{{ route('bot.bluesky.removeTerm', $t) }}" class="inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-gray-400 hover:text-red-500 px-1">×</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('bot.bluesky.addTerm', 'comments') }}" class="flex flex-wrap gap-2 items-end">
            @csrf
            <input type="hidden" name="social_account_id" value="{{ $account->id }}">
            <div class="flex-1 min-w-40">
                <input type="text" name="term" placeholder="ex: vie de freelance" required
                       class="w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <input type="number" name="max_per_run" placeholder="max" min="1" max="20" value="3"
                       class="w-20 text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <button type="submit" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-md">
                Ajouter
            </button>
        </form>
    </div>

    @if ($persona && $persona->hasBotComments())
        <details class="text-xs text-gray-500 bg-gray-50 rounded-xl p-3">
            <summary class="cursor-pointer font-medium text-gray-700">Apercu des contextes de reponse</summary>
            <div class="mt-3 space-y-2">
                <div><strong>Texte seul:</strong> {{ Str::limit($persona->bot_comment_context_text ?: '(non defini)', 200) }}</div>
                <div><strong>Article:</strong> {{ Str::limit($persona->bot_comment_context_article ?: '(non defini)', 200) }}</div>
                <div><strong>Image:</strong> {{ Str::limit($persona->bot_comment_context_image ?: '(non defini)', 200) }}</div>
            </div>
        </details>
    @endif
</div>
