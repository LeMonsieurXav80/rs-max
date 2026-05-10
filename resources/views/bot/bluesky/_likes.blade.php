@php
    $likeTerms = $termsByAccountAndPurpose[$account->id.'_likes'] ?? collect();
    $s = $settings[$account->id];
@endphp

<div class="space-y-6">

    {{-- Bloc 1 : likes des commentaires recus sur mes posts --}}
    <div class="border border-gray-100 rounded-xl p-4 bg-white">
        <label class="flex items-start gap-3">
            <input type="checkbox" {{ $s['like_comments'] ? 'checked' : '' }}
                   onchange="toggleBskyOption('like_comments', {{ $account->id }}, this.checked)"
                   class="mt-1 rounded border-gray-300 text-indigo-600">
            <div class="flex-1">
                <div class="text-sm font-semibold text-gray-900">Liker les commentaires recus sur mes posts</div>
                <p class="text-xs text-gray-500 mt-0.5">
                    Le bot like automatiquement les replies que d'autres comptes laissent sous mes propres publications.
                </p>
            </div>
        </label>
    </div>

    {{-- Bloc 2 : likes de posts du feed perso --}}
    <div class="border border-gray-100 rounded-xl p-4 bg-white">
        <label class="flex items-start gap-3">
            <input type="checkbox" {{ $s['feed_likes'] ? 'checked' : '' }}
                   onchange="toggleBskyOption('feed_likes', {{ $account->id }}, this.checked)"
                   class="mt-1 rounded border-gray-300 text-indigo-600">
            <div class="flex-1">
                <div class="text-sm font-semibold text-gray-900">Liker quelques posts de mon feed</div>
                <p class="text-xs text-gray-500 mt-0.5">
                    Le bot pioche aleatoirement quelques posts dans la timeline du compte et les like.
                </p>
                <div class="mt-2 inline-flex items-center gap-2 text-xs text-gray-600">
                    <span>Nombre max par execution :</span>
                    <input type="number" min="1" max="20" value="{{ $s['feed_likes_max'] }}"
                           onchange="updateBskyNumeric('feed_likes_max', {{ $account->id }}, this.value)"
                           class="w-20 text-xs rounded border-gray-300">
                </div>
            </div>
        </label>
    </div>

    {{-- Bloc 3 : likes par mots-cles --}}
    <div class="border border-gray-100 rounded-xl p-4 bg-white">
        <h3 class="text-sm font-semibold text-gray-900">Liker des posts par mot-cle</h3>
        <p class="text-xs text-gray-500 mt-0.5 mb-3">
            Le bot recherche ces mots-cles sur Bluesky et like les posts trouves.
        </p>

        @if ($likeTerms->isEmpty())
            <p class="text-sm text-gray-400 italic mb-3">Aucun mot-cle pour cette action.</p>
        @else
            <div class="flex flex-wrap gap-2 mb-3">
                @foreach ($likeTerms as $t)
                    <div class="inline-flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-full pl-3 pr-1 py-1">
                        <span class="text-sm text-gray-700">{{ $t->term }}</span>
                        @if ($t->like_replies)
                            <span class="text-[10px] uppercase tracking-wide bg-indigo-50 text-indigo-700 rounded px-1.5 py-0.5"
                                  title="Le bot like aussi les replies sous chaque post trouve">+ replies</span>
                        @endif
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

        <form method="POST" action="{{ route('bot.bluesky.addTerm', 'likes') }}">
            @csrf
            <input type="hidden" name="social_account_id" value="{{ $account->id }}">
            <div class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-40">
                    <label class="block text-[11px] uppercase tracking-wide text-gray-500 mb-1">Mot-cle</label>
                    <input type="text" name="term" placeholder="ex: laravel" required
                           class="w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-gray-500 mb-1">Max / run</label>
                    <input type="number" name="max_per_run" min="1" max="50" value="10"
                           class="w-20 text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <button type="submit" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-md">
                    Ajouter
                </button>
            </div>
            <label class="mt-3 flex items-start gap-2 cursor-pointer">
                <input type="checkbox" name="like_replies" value="1" checked
                       class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                <span class="flex-1">
                    <span class="block text-xs font-medium text-gray-700">Inclure aussi les replies sous le post</span>
                    <span class="block text-[11px] text-gray-500 mt-0.5">
                        Si coche, le bot like en plus du post les commentaires (replies) laisses par d'autres
                        comptes sous chaque post trouve avec ce mot-cle.
                    </span>
                </span>
            </label>
        </form>
    </div>

</div>
