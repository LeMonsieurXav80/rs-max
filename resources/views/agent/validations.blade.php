@extends('layouts.app')

@section('title', 'À valider')

@section('content')
    <div class="max-w-3xl space-y-6">

        @if (session('success'))
            <div class="rounded-xl bg-green-50 border border-green-200 p-4 text-sm text-green-700 font-medium">{{ session('success') }}</div>
        @endif

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <h2 class="text-sm font-semibold text-gray-900">Préparer des réponses</h2>
            @if ($accounts->isEmpty())
                <p class="mt-2 text-sm text-gray-500">Active « Répondre, avec validation » sur un compte, dans la page de son réseau.</p>
            @else
                <p class="mt-1 text-sm text-gray-500">Un seul prompt par compte, avec son persona, pour toutes ses conversations.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($accounts as $account)
                        <form method="POST" action="{{ route('agent.validations.prepare', $account) }}">
                            @csrf
                            <button type="submit" @disabled(! $account->persona) title="{{ $account->persona ? '' : 'Aucun persona sur ce compte' }}"
                                    class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors {{ $account->persona ? 'text-indigo-700 bg-indigo-50 hover:bg-indigo-100' : 'text-gray-400 bg-gray-50 cursor-not-allowed' }}">
                                <x-platform-icon :platform="$account->platform" size="xs" />
                                {{ $account->name }}
                            </button>
                        </form>
                    @endforeach
                </div>
            @endif
        </div>

        @forelse ($pending as $proposal)
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-3 border-b border-gray-100 flex items-center gap-3">
                    <x-platform-icon :platform="$proposal->socialAccount->platform" size="xs" />
                    <span class="text-sm font-medium text-gray-900 truncate">{{ $proposal->socialAccount->name }}</span>
                </div>
                <div class="px-6 py-4 space-y-2">
                    @foreach ($proposal->conversation as $message)
                        <div class="text-sm {{ $message['mine'] ? 'text-gray-500' : 'text-gray-800' }}">
                            <span class="font-medium">{{ $message['mine'] ? 'Compte' : $message['author'] }} :</span>
                            {{ $message['text'] }}
                        </div>
                    @endforeach
                </div>
                <form method="POST" action="{{ route('agent.validations.approve', $proposal) }}" class="px-6 pb-5">
                    @csrf
                    @if ($proposal->error)
                        <p class="mb-2 text-xs text-amber-600">{{ $proposal->error }}</p>
                    @endif
                    <label class="sr-only" for="reply-{{ $proposal->id }}">Réponse proposée</label>
                    <textarea id="reply-{{ $proposal->id }}" name="reply_text" rows="2"
                              class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">{{ $proposal->reply_text }}</textarea>
                    <div class="mt-3 flex gap-2">
                        <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition-colors">Valider et envoyer</button>
                        <button type="submit" formaction="{{ route('agent.validations.reject', $proposal) }}" formnovalidate class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">Refuser</button>
                    </div>
                </form>
            </div>
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 px-6 py-8 text-center">
                <p class="text-sm text-gray-400">Rien à valider.</p>
            </div>
        @endforelse

        @if ($recent->isNotEmpty())
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <h3 class="px-6 py-4 border-b border-gray-100 text-sm font-semibold text-gray-900">Dernières décisions</h3>
                <div class="divide-y divide-gray-50">
                    @foreach ($recent as $proposal)
                        <div class="px-6 py-3 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm text-gray-800 truncate">{{ $proposal->reply_text }}</p>
                                <p class="text-xs text-gray-400 truncate">{{ $proposal->socialAccount->name }}{{ $proposal->error ? ' : '.$proposal->error : '' }}</p>
                            </div>
                            <span class="text-xs text-gray-500 flex-shrink-0">{{ ['approved' => 'En attente d’envoi', 'sending' => 'Envoi en cours', 'sent' => 'Envoyée', 'rejected' => 'Refusée', 'failed' => 'Échec', 'uncertain' => 'À vérifier'][$proposal->status] ?? $proposal->status }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endsection
