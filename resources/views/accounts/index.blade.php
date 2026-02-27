@extends('layouts.app')

@section('title', 'Comptes sociaux')

@section('actions')
    <div class="flex items-center gap-3">
        <a href="{{ route('accounts.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Ajouter un compte
        </a>
    </div>
@endsection

@section('content')
    @if($accounts->isEmpty())
        {{-- Empty state --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
            <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-6">
                <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900 mb-2">Aucun compte social</h3>
            <p class="text-sm text-gray-500 mb-8 max-w-sm mx-auto">
                Ajoutez vos comptes de réseaux sociaux pour commencer à planifier et publier vos contenus.
            </p>
            <a href="{{ route('accounts.create') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Ajouter votre premier compte
            </a>
        </div>
    @else
        <div class="space-y-10">
            @foreach($accounts as $platformName => $platformAccounts)
                {{-- Platform group --}}
                <section>
                    {{-- Platform header --}}
                    <div class="flex items-center gap-3 mb-5">
                        <x-platform-icon :platform="$platformAccounts->first()->platform" size="lg" />
                        <h2 class="text-lg font-semibold text-gray-900">{{ $platformName }}</h2>
                        <span class="text-sm text-gray-400">({{ $platformAccounts->count() }})</span>
                    </div>

                    @if($platformAccounts->isEmpty())
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
                            <p class="text-sm text-gray-400">Aucun compte pour cette plateforme.</p>
                        </div>
                    @else
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                            @foreach($platformAccounts as $account)
                                <div
                                    class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 hover:shadow-md transition-shadow"
                                    x-data="{ active: {{ $account->user_is_active ? 'true' : 'false' }}, toggling: false }"
                                >
                                    <div class="flex items-start justify-between gap-4">
                                        {{-- Profile picture + Account info --}}
                                        <div class="flex items-start gap-3 min-w-0 flex-1">
                                            @if($account->profile_picture_url)
                                                <img
                                                    src="{{ $account->profile_picture_url }}"
                                                    alt="{{ $account->name }}"
                                                    class="w-10 h-10 rounded-xl object-cover flex-shrink-0"
                                                >
                                            @else
                                                <div class="w-10 h-10 rounded-xl bg-gray-100 flex items-center justify-center flex-shrink-0">
                                                    <span class="text-sm font-bold text-gray-400">{{ strtoupper(substr($account->name, 0, 1)) }}</span>
                                                </div>
                                            @endif

                                            <div class="min-w-0 flex-1">
                                                <h3 class="text-sm font-semibold text-gray-900 truncate">{{ $account->name }}</h3>
                                                @if($account->platform_account_id)
                                                    <p class="text-xs text-gray-400 mt-0.5">ID: {{ $account->platform_account_id }}</p>
                                                @endif

                                                <div class="flex items-center gap-2 mt-2 flex-wrap">
                                                    {{-- Language badges --}}
                                                    @foreach(($account->languages ?? ['fr']) as $lang)
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-600 uppercase">
                                                            {{ $lang }}
                                                        </span>
                                                    @endforeach

                                                    {{-- Active/Inactive badge --}}
                                                    <span
                                                        x-show="active"
                                                        class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-green-50 text-green-700"
                                                    >
                                                        Actif
                                                    </span>
                                                    <span
                                                        x-show="!active"
                                                        class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-500"
                                                    >
                                                        Inactif
                                                    </span>
                                                </div>

                                                {{-- Last used --}}
                                                @if($account->last_used_at)
                                                    <p class="text-xs text-gray-400 mt-1">
                                                        Dernier usage : {{ $account->last_used_at->diffForHumans() }}
                                                    </p>
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Toggle switch --}}
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
                                                .then(response => response.json())
                                                .then(data => {
                                                    if (data.success) {
                                                        active = data.is_active;
                                                    }
                                                })
                                                .catch(() => {})
                                                .finally(() => { toggling = false; });
                                            "
                                            :class="active ? 'bg-indigo-600' : 'bg-gray-200'"
                                            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                            role="switch"
                                            :aria-checked="active"
                                            :title="active ? 'Désactiver le compte' : 'Activer le compte'"
                                        >
                                            <span
                                                :class="active ? 'translate-x-5' : 'translate-x-0'"
                                                class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                            ></span>
                                        </button>
                                    </div>

                                    {{-- Actions --}}
                                    <div class="flex items-center gap-2 mt-5 pt-4 border-t border-gray-50">
                                        <a
                                            href="{{ route('accounts.edit', $account) }}"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                            </svg>
                                            Modifier
                                        </a>

                                        <form
                                            method="POST"
                                            action="{{ route('accounts.destroy', $account) }}"
                                            onsubmit="return confirm('Supprimer ce compte social ? Cette action est irréversible.')"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                type="submit"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition-colors"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                                Supprimer
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
@endsection
