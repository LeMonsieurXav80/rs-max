@extends('layouts.app')

@section('title', 'Agent — '.$definition['name'])

@section('content')
    <div class="max-w-3xl space-y-6">

        @if (session('success'))
            <div class="rounded-xl bg-green-50 border border-green-200 p-4 text-sm text-green-700 font-medium">{{ session('success') }}</div>
        @endif

        @if (isset($definition['route']))
            <div class="rounded-xl bg-indigo-50 border border-indigo-100 p-4 text-sm text-indigo-800">
                Sur ce réseau, le moteur existant se règle sur
                <a href="{{ route($definition['route']) }}" class="font-medium underline">sa page dédiée</a>.
            </div>
        @endif

        @forelse ($accounts as $row)
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <x-platform-icon :platform="$platform" size="sm" />
                        <h2 class="text-sm font-semibold text-gray-900 truncate">{{ $row['account']->name }}</h2>
                    </div>
                    @if ($row['account']->usesBrowser())
                        <span class="text-xs {{ $row['account']->browser_session_status === 'connected' ? 'text-green-600' : 'text-amber-600' }}">
                            {{ $row['account']->browser_session_status === 'connected' ? 'Session active' : 'Aucune session' }}
                        </span>
                    @else
                        <span class="text-xs text-gray-400">Mode API</span>
                    @endif
                </div>

                <div class="divide-y divide-gray-50">
                    @if (($row['actions']['reply']['enabled'] ?? false) && $row['account']->persona_id)
                        <form method="POST" action="{{ route('agent.prepare', $row['account']) }}" class="px-6 py-3 flex items-center justify-between gap-3">
                            @csrf
                            <span class="text-sm text-gray-600">Rédiger les réponses en attente, à valider dans la messagerie</span>
                            <button type="submit" class="px-3 py-1.5 text-xs font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition-colors">Préparer</button>
                        </form>
                    @endif
                    @foreach ($row['actions'] as $key => $action)
                        <div class="px-6 py-3">
                            @if ($action['status'] === 'ready')
                                <form method="POST" action="{{ route('agent.update', ['account' => $row['account'], 'action' => $key]) }}" class="flex flex-wrap items-center gap-3">
                                    @csrf
                                    @method('PUT')
                                    <label class="flex items-center gap-3 flex-1 min-w-48 cursor-pointer">
                                        <input type="checkbox" name="enabled" value="1" {{ $action['enabled'] ? 'checked' : '' }}
                                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-sm text-gray-800">{{ $action['label'] }}</span>
                                    </label>
                                    <span class="text-xs text-gray-400">{{ $action['used'] }} aujourd'hui, maximum</span>
                                    <input type="number" name="daily_limit" min="0" max="1000" value="{{ $action['daily_limit'] }}" aria-label="Maximum par jour"
                                           class="w-20 rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <button type="submit" class="px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition-colors">Enregistrer</button>
                                </form>
                            @else
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-sm {{ $action['status'] === 'engine' ? 'text-gray-800' : 'text-gray-400' }}">{{ $action['label'] }}</span>
                                    <span class="text-xs text-gray-400">{{ $action['status'] === 'engine' ? 'Moteur existant' : 'À venir' }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 px-6 py-8 text-center">
                <p class="text-sm text-gray-400">Aucun compte {{ $definition['name'] }}.</p>
            </div>
        @endforelse
        @if ($errors->has('agent'))<p class="rounded-xl bg-red-50 text-red-700 p-4 text-sm">{{ $errors->first('agent') }}</p>@endif
        @if ($xEngagements->isNotEmpty())
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 divide-y divide-gray-100">
                <h2 class="px-6 py-4 text-sm font-semibold">Interactions X : validation et résultats</h2>
                @foreach($xEngagements as $engagement)
                    <div class="px-6 py-4 space-y-2 text-sm">
                        <p class="font-medium">{{ $engagement->socialAccount->name }} · {{ $definition['actions'][$engagement->action]['label'] ?? $engagement->action }}</p>
                        <a href="{{ $engagement->payload['url'] }}" target="_blank" rel="noopener" class="text-indigo-600 underline">Voir la cible sur X</a>
                        @if($engagement->payload['target']['text'] ?? null)<p class="text-gray-500">{{ $engagement->payload['target']['text'] }}</p>@endif
                        @if($engagement->payload['text'] ?? null)<p class="whitespace-pre-wrap text-gray-900">{{ $engagement->payload['text'] }}</p>@endif
                        <p class="text-xs text-gray-500">{{ ['prepared'=>'Préparée','queued'=>'En attente','running'=>'En cours','confirmed'=>'Confirmée après rechargement','uncertain'=>'À vérifier, sans renvoi','failed'=>'Échec'][$engagement->status] ?? $engagement->status }}{{ ($engagement->result['unchanged'] ?? false) ? ' · État déjà présent, aucun nouveau geste' : '' }}</p>
                        @if($engagement->error)<p class="text-amber-700">{{ $engagement->error }}</p>@endif
                        @if($engagement->status === 'prepared')
                            <form method="POST" action="{{ route('agent.twitter.send',$engagement) }}">@csrf<button type="submit" class="px-3 py-2 rounded-lg bg-indigo-600 text-white text-xs">Exécuter sur le NAS</button></form>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
        @if ($engagements->isNotEmpty())
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 divide-y divide-gray-100">
                <h2 class="px-6 py-4 text-sm font-semibold text-gray-900">Interactions TikTok à valider et résultats</h2>
                @foreach ($engagements as $engagement)
                    <div class="px-6 py-4 space-y-2 text-sm">
                        <div class="flex justify-between gap-3">
                            <span class="font-medium">{{ $engagement->socialAccount->name }} · {{ $definition['actions'][$engagement->action]['label'] }}</span>
                            <span class="text-xs text-gray-500">{{ ['prepared' => 'À valider', 'queued' => 'En attente', 'running' => 'Vérification en cours', 'confirmed' => 'Confirmé après rechargement', 'failed' => 'Échec', 'uncertain' => 'À vérifier sur TikTok'][$engagement->status] ?? $engagement->status }}</span>
                        </div>
                        <a href="{{ $engagement->payload['url'] }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 underline">Voir la vidéo</a>
                        @if ($engagement->payload['target'] ?? null)
                            <p class="text-gray-500">{{ $engagement->payload['target']['author'] }} : {{ $engagement->payload['target']['text'] }}</p>
                        @endif
                        @if ($engagement->payload['text'] ?? null)
                            <p class="text-gray-800">{{ $engagement->payload['text'] }}</p>
                        @endif
                        @if ($engagement->payload['discussion'] ?? null)
                            <p class="text-xs text-gray-500">{{ $engagement->payload['discussion']['observed'] }} commentaires lus{{ isset($engagement->payload['discussion']['expected']) ? ' / '.$engagement->payload['discussion']['expected'].' annoncés par TikTok' : '' }}{{ $engagement->payload['discussion']['coverage'] === 'accessible' ? ' — tous les commentaires accessibles' : '' }}.</p>
                        @endif
                        @if ($engagement->error)
                            <p class="text-amber-700">{{ $engagement->error }}</p>
                        @endif
                        @if ($engagement->status === 'prepared')
                            <form method="POST" action="{{ route('agent.tiktok.send', $engagement) }}">
                                @csrf
                                <button type="submit" class="px-3 py-1.5 text-xs font-medium text-white bg-indigo-600 rounded-lg">Valider cette action</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
