@extends('layouts.app')

@section('title', 'Facebook / Instagram')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">

        {{-- Connexion OAuth --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[#1877F2]/10 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-[#1877F2]" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Connexion OAuth</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Liez vos Pages Facebook et comptes Instagram Business.</p>
                    </div>
                </div>
            </div>

            <div class="p-6">
                <a href="{{ route('facebook.redirect') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#1877F2] text-white text-sm font-medium rounded-xl hover:bg-[#1565C0] transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m9.86-2.06a4.5 4.5 0 0 0-1.242-7.244l-4.5-4.5a4.5 4.5 0 0 0-6.364 6.364L4.757 8.81" />
                    </svg>
                    Connecter Facebook / Instagram
                </a>
            </div>
        </div>

        {{-- Pages Facebook connectées --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">Pages Facebook</h3>
                <span class="text-xs text-gray-400">{{ $fbAccounts->count() }} compte(s)</span>
            </div>

            @if($fbAccounts->isEmpty())
                <div class="px-6 py-8 text-center">
                    <p class="text-sm text-gray-400">Aucune Page Facebook connectée.</p>
                </div>
            @else
                <div class="divide-y divide-gray-50">
                    @foreach($fbAccounts as $account)
                        <div class="px-6 py-4 flex items-center justify-between gap-3" x-data="{
                            active: {{ $account->is_active ? 'true' : 'false' }},
                            toggling: false
                        }">
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                @if($account->profile_picture_url)
                                    <img src="{{ $account->profile_picture_url }}" alt="{{ $account->name }}" class="w-9 h-9 rounded-lg object-cover flex-shrink-0">
                                @else
                                    <x-platform-icon platform="facebook" size="lg" />
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
                                <form action="{{ route('platforms.destroyAccount', $account) }}" method="POST" onsubmit="return confirm('Supprimer cette Page ?')">
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

        {{-- Comptes Instagram connectés --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">Comptes Instagram</h3>
                <span class="text-xs text-gray-400">{{ $igAccounts->count() }} compte(s)</span>
            </div>

            @if($igAccounts->isEmpty())
                <div class="px-6 py-8 text-center">
                    <p class="text-sm text-gray-400">Aucun compte Instagram connecté.</p>
                </div>
            @else
                <div class="divide-y divide-gray-50">
                    @foreach($igAccounts as $account)
                        <div class="px-6 py-4 flex items-center justify-between gap-3" x-data="{
                            active: {{ $account->is_active ? 'true' : 'false' }},
                            toggling: false
                        }">
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                @if($account->profile_picture_url)
                                    <img src="{{ $account->profile_picture_url }}" alt="{{ $account->name }}" class="w-9 h-9 rounded-lg object-cover flex-shrink-0">
                                @else
                                    <x-platform-icon platform="instagram" size="lg" />
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
