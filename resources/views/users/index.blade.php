@extends('layouts.app')

@section('title', 'Utilisateurs')

@section('actions')
    <div class="flex items-center gap-3">
        <a href="{{ route('users.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Ajouter un utilisateur
        </a>
    </div>
@endsection

@section('content')
    @if($users->isEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
            <h3 class="text-base font-semibold text-gray-900 mb-2">Aucun utilisateur</h3>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach($users as $user)
                <div
                    class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 hover:shadow-md transition-shadow"
                    x-data="{ isAdmin: {{ $user->is_admin ? 'true' : 'false' }}, toggling: false }"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3 min-w-0 flex-1">
                            {{-- Avatar --}}
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                                 :class="isAdmin ? 'bg-indigo-100' : 'bg-gray-100'">
                                <span class="text-sm font-bold"
                                      :class="isAdmin ? 'text-indigo-600' : 'text-gray-400'">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-semibold text-gray-900 truncate">{{ $user->name }}</h3>
                                <p class="text-xs text-gray-400 mt-0.5 truncate">{{ $user->email }}</p>

                                <div class="flex items-center gap-2 mt-2 flex-wrap">
                                    {{-- Role badge --}}
                                    <span
                                        x-show="isAdmin"
                                        class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-indigo-50 text-indigo-700"
                                    >
                                        Admin
                                    </span>
                                    <span
                                        x-show="!isAdmin"
                                        class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-500"
                                    >
                                        Utilisateur
                                    </span>

                                    {{-- Accounts count --}}
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-50 text-gray-500">
                                        {{ $user->social_accounts_count }} compte{{ $user->social_accounts_count > 1 ? 's' : '' }}
                                    </span>

                                    {{-- Posts count --}}
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-50 text-gray-500">
                                        {{ $user->posts_count }} post{{ $user->posts_count > 1 ? 's' : '' }}
                                    </span>
                                </div>

                                @if($user->default_language)
                                    <p class="text-xs text-gray-400 mt-1">
                                        Langue : <span class="uppercase font-medium">{{ $user->default_language }}</span>
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center gap-2 mt-5 pt-4 border-t border-gray-50">
                        <a
                            href="{{ route('users.edit', $user) }}"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                            </svg>
                            Modifier
                        </a>

                        @if($user->id !== auth()->id())
                            {{-- Toggle admin --}}
                            <button
                                type="button"
                                @click="
                                    if (toggling) return;
                                    toggling = true;
                                    fetch('{{ route('users.toggleAdmin', $user) }}', {
                                        method: 'PATCH',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                            'Accept': 'application/json',
                                        },
                                    })
                                    .then(r => r.json())
                                    .then(data => { if (data.success) isAdmin = data.is_admin; })
                                    .catch(() => {})
                                    .finally(() => { toggling = false; });
                                "
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors"
                                :class="isAdmin ? 'text-orange-600 bg-orange-50 hover:bg-orange-100' : 'text-indigo-600 bg-indigo-50 hover:bg-indigo-100'"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                                </svg>
                                <span x-text="isAdmin ? 'Retirer admin' : 'Promouvoir admin'"></span>
                            </button>

                            {{-- Delete --}}
                            <form
                                method="POST"
                                action="{{ route('users.destroy', $user) }}"
                                onsubmit="return confirm('Supprimer cet utilisateur ? Cette action est irreversible.')"
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
                        @else
                            <span class="text-xs text-gray-400 italic">Vous</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
