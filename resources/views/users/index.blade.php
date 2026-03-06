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
                @php
                    $roleColors = [
                        'admin' => ['bg' => 'bg-indigo-100', 'text' => 'text-indigo-600', 'badge' => 'bg-indigo-50 text-indigo-700'],
                        'manager' => ['bg' => 'bg-purple-100', 'text' => 'text-purple-600', 'badge' => 'bg-purple-50 text-purple-700'],
                        'user' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-400', 'badge' => 'bg-gray-100 text-gray-500'],
                    ];
                    $colors = $roleColors[$user->role] ?? $roleColors['user'];
                    $roleLabels = ['admin' => 'Admin', 'manager' => 'Manager', 'user' => 'Utilisateur'];
                @endphp
                <div
                    class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 hover:shadow-md transition-shadow"
                    x-data="{ role: '{{ $user->role }}', toggling: false }"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3 min-w-0 flex-1">
                            {{-- Avatar --}}
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 {{ $colors['bg'] }}">
                                <span class="text-sm font-bold {{ $colors['text'] }}">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-semibold text-gray-900 truncate">{{ $user->name }}</h3>
                                <p class="text-xs text-gray-400 mt-0.5 truncate">{{ $user->email }}</p>

                                <div class="flex items-center gap-2 mt-2 flex-wrap">
                                    {{-- Role badge --}}
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium {{ $colors['badge'] }}"
                                          x-text="{ admin: 'Admin', manager: 'Manager', user: 'Utilisateur' }[role]">
                                        {{ $roleLabels[$user->role] ?? 'Utilisateur' }}
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
                            {{-- Role selector --}}
                            <select
                                x-model="role"
                                @change="
                                    if (toggling) return;
                                    toggling = true;
                                    fetch('{{ route('users.toggleRole', $user) }}', {
                                        method: 'PATCH',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').getAttribute('content'),
                                            'Accept': 'application/json',
                                        },
                                        body: JSON.stringify({ role: role }),
                                    })
                                    .then(r => r.json())
                                    .then(data => { if (data.success) role = data.role; })
                                    .catch(() => {})
                                    .finally(() => { toggling = false; });
                                "
                                class="text-xs rounded-lg border-gray-300 py-1.5 pr-8 focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="user">Utilisateur</option>
                                <option value="manager">Manager</option>
                                <option value="admin">Admin</option>
                            </select>

                            {{-- Delete --}}
                            <form
                                method="POST"
                                action="{{ route('users.destroy', $user) }}"
                                onsubmit="return confirm('Supprimer cet utilisateur ? Cette action est irreversible.')"
                                class="ml-auto"
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
