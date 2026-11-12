@extends('layouts.app')

@section('title', $source->exists ? 'Modifier la source du Vault' : 'Nouvelle source du Vault')

@section('content')
    <form method="POST" action="{{ $source->exists ? route('vault-sources.update', $source) : route('vault-sources.store') }}" class="max-w-3xl">
        @csrf
        @if($source->exists) @method('PUT') @endif

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-5">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                <input id="name" name="name" required maxlength="120" value="{{ old('name', $source->name) }}" class="w-full rounded-xl border-gray-300 text-sm">
                @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="corpus" class="block text-sm font-medium text-gray-700 mb-1">Vault du serveur</label>
                    <input id="corpus" name="corpus" required maxlength="31" value="{{ old('corpus', $source->corpus) }}" class="w-full rounded-xl border-gray-300 text-sm" placeholder="cerveau-xav">
                    <p class="mt-1 text-xs text-gray-400">Nom du service de recherche installé sur ce serveur.</p>
                    @error('corpus')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="format" class="block text-sm font-medium text-gray-700 mb-1">Format</label>
                    <select id="format" name="format" class="w-full rounded-xl border-gray-300 text-sm">
                        @foreach(\App\Models\VaultSource::FORMATS as $value => $label)
                            <option value="{{ $value }}" @selected(old('format', $source->format) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label for="folders" class="block text-sm font-medium text-gray-700 mb-1">Dossiers autorisés</label>
                <textarea id="folders" name="folders" rows="6" required class="w-full rounded-xl border-gray-300 text-sm font-mono" placeholder="Un chemin par ligne, ex. 03-Resources/Livres">{{ old('folders', implode("\n", $source->folders ?? [])) }}</textarea>
                @error('folders')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="excluded_folders" class="block text-sm font-medium text-gray-700 mb-1">Exclusions dans ces dossiers</label>
                <textarea id="excluded_folders" name="excluded_folders" rows="3" class="w-full rounded-xl border-gray-300 text-sm font-mono">{{ old('excluded_folders', implode("\n", $source->excluded_folders ?? [])) }}</textarea>
            </div>
            <div>
                <label for="brands" class="block text-sm font-medium text-gray-700 mb-1">Marques à ne jamais placer d’elles-mêmes</label>
                <textarea id="brands" name="brands" rows="2" class="w-full rounded-xl border-gray-300 text-sm" placeholder="Une par ligne">{{ old('brands', implode("\n", $source->brands ?? [])) }}</textarea>
                <p class="mt-1 text-xs text-gray-400">Une réponse qui cite l’une d’elles est refusée si la personne ne l’a pas nommée.</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-700 mb-2">Comptes</p>
                @php
                    $checked = collect(old('accounts', $source->exists ? $source->socialAccounts->pluck('id')->all() : []))->map(fn ($id) => (int) $id);
                @endphp
                <div class="grid grid-cols-1 md:grid-cols-2 gap-1 max-h-72 overflow-y-auto">
                    @foreach($accounts as $account)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="accounts[]" value="{{ $account->id }}" @checked($checked->contains($account->id)) class="rounded border-gray-300">
                            {{ $account->name }} <span class="text-xs text-gray-400">{{ $account->platform->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $source->is_active)) class="rounded border-gray-300"> Active
            </label>
        </div>

        <div class="flex items-center gap-3 mt-6">
            <button type="submit" class="px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700">Enregistrer</button>
            <a href="{{ route('vault-sources.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annuler</a>
        </div>
    </form>
    @if($source->exists)
        <form method="POST" action="{{ route('vault-sources.destroy', $source) }}" class="max-w-3xl mt-4" onsubmit="return confirm('Supprimer cette source ?')">
            @csrf @method('DELETE')
            <button class="text-sm text-red-600 hover:text-red-700">Supprimer la source</button>
        </form>
    @endif
@endsection
