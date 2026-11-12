@extends('layouts.app')

@section('title', 'Sources du Vault')

@section('actions')
    <a href="{{ route('vault-sources.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
        Nouvelle source
    </a>
@endsection

@section('content')
    @if(session('status'))
        <div class="mb-6 rounded-xl bg-green-50 border border-green-200 p-4 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <p class="text-sm text-gray-500 mb-6 max-w-3xl">
        Chaque source désigne des dossiers du Vault de ce serveur. Les comptes cochés y puisent des références pour leurs réponses,
        via Hermès comme via Companion (Mac, NAS, VPS). Les zones sensibles (compta, mails, contacts, partenariats, 07-Raw, identifiants) sont toujours refusées.
    </p>

    @if($sources->isEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center text-sm text-gray-500">Aucune source du Vault.</div>
    @else
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 divide-y divide-gray-100">
            @foreach($sources as $source)
                <div class="p-5 flex flex-col md:flex-row md:items-start gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <a href="{{ route('vault-sources.edit', $source) }}" class="font-medium text-gray-900 hover:text-indigo-600">{{ $source->name }}</a>
                            @unless($source->is_active)<span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">inactive</span>@endunless
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Vault <code>{{ $source->corpus }}</code> · {{ \App\Models\VaultSource::FORMATS[$source->format] ?? $source->format }}</p>
                        <p class="text-xs text-gray-600 mt-2 break-words">{{ implode(' · ', $source->folders ?? []) }}</p>
                    </div>
                    <div class="text-xs text-gray-600 md:w-64">
                        {{ $source->socialAccounts->map(fn ($a) => $a->name.' ('.$a->platform->name.')')->implode(', ') ?: 'Aucun compte' }}
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
