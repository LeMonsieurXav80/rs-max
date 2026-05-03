@php
    $likeTerms = $termsByAccountAndPurpose[$account->id.'_likes'] ?? collect();
    $s = $settings[$account->id];
@endphp

<div class="space-y-5">
    <div>
        <h3 class="text-sm font-semibold text-gray-900 mb-2">Mots-cles a liker</h3>
        <p class="text-xs text-gray-500 mb-3">Le bot recherche ces mots-cles sur Bluesky et like les posts (et leurs replies si active).</p>

        @if ($likeTerms->isEmpty())
            <p class="text-sm text-gray-400 italic mb-3">Aucun mot-cle pour cette action.</p>
        @else
            <div class="flex flex-wrap gap-2 mb-3">
                @foreach ($likeTerms as $t)
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

        <form method="POST" action="{{ route('bot.bluesky.addTerm', 'likes') }}" class="flex flex-wrap gap-2 items-end">
            @csrf
            <input type="hidden" name="social_account_id" value="{{ $account->id }}">
            <div class="flex-1 min-w-40">
                <input type="text" name="term" placeholder="ex: laravel" required
                       class="w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <input type="number" name="max_per_run" placeholder="max" min="1" max="50" value="10"
                       class="w-20 text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <label class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                <input type="checkbox" name="like_replies" value="1" checked
                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                Liker replies
            </label>
            <button type="submit" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-md">
                Ajouter
            </button>
        </form>
    </div>

    <div class="border-t border-gray-100 pt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
        <label class="flex items-start gap-3">
            <input type="checkbox" {{ $s['like_comments'] ? 'checked' : '' }}
                   onchange="toggleBskyOption('like_comments', {{ $account->id }}, this.checked)"
                   class="mt-0.5 rounded border-gray-300 text-indigo-600">
            <span class="text-sm">
                <span class="font-medium text-gray-700">Liker les commentaires sur mes posts</span>
                <span class="block text-xs text-gray-500">Like automatiquement les replies recus sur mes propres posts.</span>
            </span>
        </label>
        <label class="flex items-start gap-3">
            <input type="checkbox" {{ $s['feed_likes'] ? 'checked' : '' }}
                   onchange="toggleBskyOption('feed_likes', {{ $account->id }}, this.checked)"
                   class="mt-0.5 rounded border-gray-300 text-indigo-600">
            <span class="text-sm flex-1">
                <span class="font-medium text-gray-700">Liker quelques posts du feed</span>
                <span class="block text-xs text-gray-500">Like X posts random du feed perso.</span>
                <input type="number" min="1" max="20" value="{{ $s['feed_likes_max'] }}"
                       onchange="updateBskyNumeric('feed_likes_max', {{ $account->id }}, this.value)"
                       class="mt-1 w-20 text-xs rounded border-gray-300">
            </span>
        </label>
        <label class="flex items-start gap-3">
            <input type="checkbox" {{ $s['unfollow'] ? 'checked' : '' }}
                   onchange="toggleBskyOption('unfollow', {{ $account->id }}, this.checked)"
                   class="mt-0.5 rounded border-gray-300 text-indigo-600">
            <span class="text-sm flex-1">
                <span class="font-medium text-gray-700">Defollow les non-followers</span>
                <span class="block text-xs text-gray-500">Defollow ceux qui ne nous suivent pas (apres delai de grace).</span>
                <input type="number" min="1" max="100" value="{{ $s['unfollow_max'] }}"
                       onchange="updateBskyNumeric('unfollow_max', {{ $account->id }}, this.value)"
                       class="mt-1 w-20 text-xs rounded border-gray-300">
            </span>
        </label>
    </div>
</div>
