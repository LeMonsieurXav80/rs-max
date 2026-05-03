@extends('layouts.app')

@section('title', 'Logs Bot')

@section('actions')
    <div class="flex items-center gap-2">
        <a href="{{ route('bot.index') }}"
           class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
            </svg>
            Retour
        </a>
        <form method="POST" action="{{ route('bot.clearLogs') }}"
              onsubmit="return confirm('Vider tout l\'historique des actions ?');">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-red-600 bg-white border border-red-200 rounded-xl hover:bg-red-50 transition-colors">
                Vider l'historique
            </button>
        </form>
    </div>
@endsection

@section('content')
    <div class="space-y-4">
        {{-- Filtres --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <form method="GET" action="{{ route('bot.logs') }}" class="flex flex-wrap items-center gap-3">
                <select name="account" class="text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">Tous les comptes</option>
                    @foreach ($allBotAccounts as $a)
                        <option value="{{ $a->id }}" @selected($filterAccount == $a->id)>
                            {{ $a->name }} ({{ $a->platform->name ?? '?' }})
                        </option>
                    @endforeach
                </select>
                <select name="type" class="text-sm rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">Toutes les actions</option>
                    @foreach ($actionTypes as $t)
                        <option value="{{ $t }}" @selected($filterType === $t)>{{ str_replace('_', ' ', $t) }}</option>
                    @endforeach
                </select>
                <button type="submit"
                        class="px-3 py-1.5 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                    Filtrer
                </button>
                @if ($filterAccount || $filterType)
                    <a href="{{ route('bot.logs') }}" class="text-sm text-gray-500 hover:text-gray-700">Reinitialiser</a>
                @endif
            </form>
        </div>

        {{-- Table --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-600 uppercase">
                        <tr>
                            <th class="px-3 py-2 text-left">Date</th>
                            <th class="px-3 py-2 text-left">Compte</th>
                            <th class="px-3 py-2 text-left">Action</th>
                            <th class="px-3 py-2 text-left">Cible</th>
                            <th class="px-3 py-2 text-left">Mot-cle</th>
                            <th class="px-3 py-2 text-center">OK</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($logs as $log)
                            <tr>
                                <td class="px-3 py-2 text-xs text-gray-500 whitespace-nowrap">{{ $log->created_at->diffForHumans() }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $log->socialAccount->name ?? '?' }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex text-xs bg-indigo-50 text-indigo-700 rounded-md px-2 py-0.5">{{ $log->action_type }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="text-xs text-gray-600">{{ $log->target_author }}</div>
                                    <div class="text-xs text-gray-400 truncate max-w-md">{{ $log->target_text }}</div>
                                </td>
                                <td class="px-3 py-2 text-xs text-gray-500">{{ $log->search_term }}</td>
                                <td class="px-3 py-2 text-center">
                                    @if ($log->success)
                                        <span class="text-emerald-600">✓</span>
                                    @else
                                        <span class="text-red-500" title="{{ $log->error }}">✗</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-6 text-center text-sm text-gray-400">Aucune action sur les 7 derniers jours.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
