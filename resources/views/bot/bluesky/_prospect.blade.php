@php
    $myTargets = $targetAccounts->where('social_account_id', $account->id);
@endphp

<div class="space-y-5">
    <div>
        <h3 class="text-sm font-semibold text-gray-900 mb-1">Prospection ciblee</h3>
        <p class="text-xs text-gray-500 mb-3">Ajoute le handle d'un compte cible. Le bot analyse ses posts recents, like leurs likers et follow un sous-ensemble (ratio 1/5).</p>

        <form method="POST" action="{{ route('bot.bluesky.addTarget') }}" class="flex flex-wrap gap-2 items-end">
            @csrf
            <input type="hidden" name="social_account_id" value="{{ $account->id }}">
            <div class="flex-1 min-w-60">
                <input type="text" name="handle" placeholder="@user.bsky.social" required
                       class="w-full text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <button type="submit" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-md">
                Ajouter cible
            </button>
        </form>
    </div>

    @if ($myTargets->isEmpty())
        <p class="text-sm text-gray-400 italic">Aucune cible.</p>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-600 uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">Handle</th>
                        <th class="px-3 py-2 text-left">Statut</th>
                        <th class="px-3 py-2 text-right">Likers traites</th>
                        <th class="px-3 py-2 text-right">Likes</th>
                        <th class="px-3 py-2 text-right">Follows</th>
                        <th class="px-3 py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($myTargets as $t)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-700">@{{ $t->handle }}</td>
                            <td class="px-3 py-2">
                                <span class="inline-flex text-xs px-2 py-0.5 rounded-full
                                    @if ($t->status === 'running') bg-blue-100 text-blue-700
                                    @elseif ($t->status === 'completed') bg-emerald-100 text-emerald-700
                                    @elseif ($t->status === 'paused') bg-amber-100 text-amber-700
                                    @else bg-gray-100 text-gray-600
                                    @endif">
                                    {{ $t->status }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right text-gray-600">{{ $t->likers_processed }}</td>
                            <td class="px-3 py-2 text-right text-gray-600">{{ $t->likes_given }}</td>
                            <td class="px-3 py-2 text-right text-gray-600">{{ $t->follows_given }}</td>
                            <td class="px-3 py-2 text-right">
                                <div class="inline-flex gap-1">
                                    <form method="POST" action="{{ route('bot.bluesky.runTarget', $t) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs px-2 py-1 bg-indigo-100 text-indigo-700 rounded hover:bg-indigo-200">▶</button>
                                    </form>
                                    <form method="POST" action="{{ route('bot.bluesky.stopTarget', $t) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs px-2 py-1 bg-amber-100 text-amber-700 rounded hover:bg-amber-200">⏸</button>
                                    </form>
                                    <form method="POST" action="{{ route('bot.bluesky.resetTarget', $t) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs px-2 py-1 bg-gray-100 text-gray-600 rounded hover:bg-gray-200">↻</button>
                                    </form>
                                    <form method="POST" action="{{ route('bot.bluesky.removeTarget', $t) }}" class="inline"
                                          onsubmit="return confirm('Supprimer cette cible ?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs px-2 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200">×</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
