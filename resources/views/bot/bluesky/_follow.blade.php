@php
    $followTerms = $termsByAccountAndPurpose[$account->id.'_follow'] ?? collect();
    $s = $settings[$account->id];
@endphp

<div class="space-y-5">
    <label class="flex items-center gap-3 p-4 bg-gray-50 rounded-xl">
        <input type="checkbox" {{ $s['follow_keyword'] ? 'checked' : '' }}
               onchange="toggleBskyOption('follow_keyword', {{ $account->id }}, this.checked)"
               class="rounded border-gray-300 text-indigo-600">
        <div class="flex-1">
            <div class="text-sm font-medium text-gray-900">Activer le follow automatique par mot-cle</div>
            <div class="text-xs text-gray-500">Le bot follow les auteurs de posts matchant les mots-cles (filtre les comptes deja suivis).</div>
        </div>
        <div class="text-xs text-gray-500">Max / run :</div>
        <input type="number" min="1" max="50" value="{{ $s['follow_max'] }}"
               onchange="updateBskyNumeric('follow_max', {{ $account->id }}, this.value)"
               class="w-20 text-sm rounded border-gray-300">
    </label>

    <div>
        <h3 class="text-sm font-semibold text-gray-900 mb-2">Mots-cles pour le follow</h3>
        <p class="text-xs text-gray-500 mb-3">Posts matchant ces mots-cles → l'auteur du post sera follow.</p>

        @if ($followTerms->isEmpty())
            <p class="text-sm text-gray-400 italic mb-3">Aucun mot-cle pour le follow.</p>
        @else
            <div class="flex flex-wrap gap-2 mb-3">
                @foreach ($followTerms as $t)
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

        <form method="POST" action="{{ route('bot.bluesky.addTerm', 'follow') }}" class="flex flex-wrap gap-2 items-end">
            @csrf
            <input type="hidden" name="social_account_id" value="{{ $account->id }}">
            <div class="flex-1 min-w-40">
                <input type="text" name="term" placeholder="ex: dev indie" required
                       class="w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <input type="number" name="max_per_run" placeholder="max" min="1" max="50" value="5"
                       class="w-20 text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <button type="submit" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-md">
                Ajouter
            </button>
        </form>
    </div>
</div>
