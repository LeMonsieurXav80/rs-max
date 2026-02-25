@extends('layouts.app')

@section('title', 'Threads')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">

        {{-- Connexion OAuth --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-black/5 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-black" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.509 5.467l-2.04.569c-1.104-3.96-3.898-5.984-8.304-6.015-2.91.022-5.11.936-6.54 2.717C4.307 6.504 3.616 8.914 3.59 12c.025 3.086.718 5.496 2.057 7.164 1.432 1.783 3.631 2.698 6.54 2.717 2.623-.02 4.358-.631 5.8-2.045 1.647-1.613 1.618-3.593 1.09-4.798-.31-.71-.873-1.3-1.634-1.75-.192 1.352-.622 2.446-1.284 3.272-.886 1.102-2.14 1.704-3.73 1.79-1.202.065-2.361-.218-3.259-.801-1.063-.689-1.685-1.74-1.752-2.96-.065-1.17.408-2.253 1.33-3.05.81-.7 1.91-1.12 3.192-1.216 1.074-.082 2.068.022 2.97.283-.039-1.31-.494-2.282-1.321-2.796-.573-.356-1.363-.54-2.281-.54l-.026.002c-1.378.014-2.396.454-3.024 1.305l-1.602-1.197C8.022 5.628 9.47 4.96 11.48 4.94l.04-.001c1.321 0 2.459.298 3.38.886 1.222.781 1.937 2.02 2.098 3.634.58.17 1.11.403 1.578.695 1.202.75 2.084 1.798 2.55 3.032.77 2.034.712 4.89-1.512 7.067-1.836 1.795-4.103 2.628-7.343 2.647l-.085.1zm-1.57-8.357c-1.452.111-2.458.784-2.396 1.896.026.472.258.863.673 1.13.553.357 1.287.508 2.063.472 1.083-.058 1.907-.455 2.449-1.18.392-.525.652-1.21.78-2.05-.876-.303-1.875-.38-2.857-.296l-.713.028z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Connexion OAuth</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Liez votre compte Threads pour publier automatiquement.</p>
                    </div>
                </div>
            </div>

            <div class="p-6">
                <a href="{{ route('threads.redirect') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-black text-white text-sm font-medium rounded-xl hover:bg-gray-800 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m9.86-2.06a4.5 4.5 0 0 0-1.242-7.244l-4.5-4.5a4.5 4.5 0 0 0-6.364 6.364L4.757 8.81" />
                    </svg>
                    Connecter un compte Threads
                </a>
            </div>
        </div>

        {{-- Comptes Threads connectés --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">Comptes Threads</h3>
                <span class="text-xs text-gray-400">{{ $accounts->count() }} compte(s)</span>
            </div>

            @if($accounts->isEmpty())
                <div class="px-6 py-8 text-center">
                    <p class="text-sm text-gray-400">Aucun compte Threads connecté.</p>
                </div>
            @else
                <div class="divide-y divide-gray-50">
                    @foreach($accounts as $account)
                        <div class="px-6 py-4 flex items-center justify-between gap-3" x-data="{ active: {{ $account->is_active ? 'true' : 'false' }}, toggling: false }">
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                @if($account->profile_picture_url)
                                    <img src="{{ $account->profile_picture_url }}" alt="{{ $account->name }}" class="w-9 h-9 rounded-lg object-cover flex-shrink-0">
                                @else
                                    <x-platform-icon platform="threads" size="lg" />
                                @endif
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $account->name }}</p>
                                    <p class="text-xs text-gray-400">ID: {{ $account->platform_account_id }}</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                {{-- Toggle --}}
                                <button
                                    type="button"
                                    @click="
                                        if (toggling) return;
                                        toggling = true;
                                        fetch('{{ route('accounts.toggle', $account) }}', {
                                            method: 'PATCH',
                                            headers: {
                                                'Content-Type': 'application/json',
                                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                                'Accept': 'application/json',
                                            },
                                        })
                                        .then(r => r.json())
                                        .then(d => { if (d.success) active = d.is_active; })
                                        .catch(() => {})
                                        .finally(() => { toggling = false; });
                                    "
                                    :class="active ? 'bg-indigo-600' : 'bg-gray-200'"
                                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
                                    role="switch"
                                    :aria-checked="active"
                                    :title="active ? 'Désactiver' : 'Activer'"
                                >
                                    <span :class="active ? 'translate-x-5' : 'translate-x-0'" class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
                                </button>

                                {{-- Delete --}}
                                <form action="{{ route('platforms.destroyAccount', $account) }}" method="POST" onsubmit="return confirm('Supprimer ce compte ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="inline-flex items-center p-1 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>
@endsection
