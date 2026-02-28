@extends('layouts.app')

@section('title', 'Sites WordPress')

@section('actions')
    <a href="{{ route('wordpress-sites.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Nouveau site
    </a>
@endsection

@section('content')

    @if(session('status') === 'source-created')
        <div class="mb-6" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)">
            <div class="rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <p class="text-sm text-green-700">Site WordPress ajouté avec succès.</p>
            </div>
        </div>
    @endif

    @if(session('status') === 'source-updated')
        <div class="mb-6" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)">
            <div class="rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <p class="text-sm text-green-700">Site WordPress mis à jour.</p>
            </div>
        </div>
    @endif

    @if(session('status') === 'source-deleted')
        <div class="mb-6" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)">
            <div class="rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <p class="text-sm text-green-700">Site WordPress supprimé.</p>
            </div>
        </div>
    @endif

    @if(session('status') === 'source-fetched')
        <div class="mb-6" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)">
            <div class="rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <p class="text-sm text-green-700">Contenu récupéré : {{ session('fetch_count', 0) }} nouveaux articles.</p>
            </div>
        </div>
    @endif

    @if($sources->isEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
            <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-6">
                <svg class="w-8 h-8 text-gray-300" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M21.469 6.825c.84 1.537 1.318 3.3 1.318 5.175 0 3.979-2.156 7.456-5.363 9.325l3.295-9.527c.615-1.539.82-2.771.82-3.864 0-.397-.026-.765-.07-1.109m-7.981.105c.647-.034 1.23-.1 1.23-.1.579-.068.51-.919-.069-.886 0 0-1.742.137-2.865.137-1.056 0-2.83-.137-2.83-.137-.579-.033-.648.852-.068.886 0 0 .549.06 1.128.103l1.674 4.59-2.35 7.05-3.911-11.64c.647-.034 1.23-.1 1.23-.1.579-.068.51-.919-.069-.886 0 0-1.742.137-2.865.137-.201 0-.44-.005-.693-.014C4.758 3.668 8.088 2 11.869 2c2.81 0 5.371 1.075 7.294 2.833-.046-.003-.091-.009-.141-.009-1.056 0-1.803.919-1.803 1.907 0 .886.51 1.636 1.055 2.523.41.717.889 1.636.889 2.962 0 .919-.354 1.985-.82 3.47l-1.075 3.59-3.896-11.586.003-.002zM11.869 24c-1.886 0-3.673-.429-5.265-1.196l5.596-16.252 5.728 15.69c.038.092.083.178.131.257C15.997 23.467 14.008 24 11.869 24M.926 12c0-2.335.73-4.5 1.974-6.278l5.441 14.906C3.597 18.705.926 15.641.926 12"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900 mb-2">Aucun site WordPress</h3>
            <p class="text-sm text-gray-500 mb-8">Connectez des sites WordPress pour générer automatiquement des publications à partir de leur contenu.</p>
            <a href="{{ route('wordpress-sites.create') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Ajouter un site
            </a>
        </div>
    @else
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 divide-y divide-gray-100">
            @foreach($sources as $source)
                <div class="flex items-center gap-4 p-5 hover:bg-gray-50/60 transition-colors">
                    {{-- Icon --}}
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 {{ $source->is_active ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400' }}">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M21.469 6.825c.84 1.537 1.318 3.3 1.318 5.175 0 3.979-2.156 7.456-5.363 9.325l3.295-9.527c.615-1.539.82-2.771.82-3.864 0-.397-.026-.765-.07-1.109m-7.981.105c.647-.034 1.23-.1 1.23-.1.579-.068.51-.919-.069-.886 0 0-1.742.137-2.865.137-1.056 0-2.83-.137-2.83-.137-.579-.033-.648.852-.068.886 0 0 .549.06 1.128.103l1.674 4.59-2.35 7.05-3.911-11.64c.647-.034 1.23-.1 1.23-.1.579-.068.51-.919-.069-.886 0 0-1.742.137-2.865.137-.201 0-.44-.005-.693-.014C4.758 3.668 8.088 2 11.869 2c2.81 0 5.371 1.075 7.294 2.833-.046-.003-.091-.009-.141-.009-1.056 0-1.803.919-1.803 1.907 0 .886.51 1.636 1.055 2.523.41.717.889 1.636.889 2.962 0 .919-.354 1.985-.82 3.47l-1.075 3.59-3.896-11.586.003-.002zM11.869 24c-1.886 0-3.673-.429-5.265-1.196l5.596-16.252 5.728 15.69c.038.092.083.178.131.257C15.997 23.467 14.008 24 11.869 24M.926 12c0-2.335.73-4.5 1.974-6.278l5.441 14.906C3.597 18.705.926 15.641.926 12"/>
                        </svg>
                    </div>

                    {{-- Content --}}
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <h3 class="text-sm font-semibold text-gray-900 truncate">{{ $source->name }}</h3>
                            @foreach($source->post_types ?? [] as $type)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-blue-50 text-blue-600">{{ $type }}</span>
                            @endforeach
                            @if(! $source->is_active)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-red-50 text-red-600">Inactif</span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-400 truncate mb-1">{{ $source->url }}</p>
                        <div class="flex items-center gap-4 text-xs text-gray-500">
                            <span>{{ $source->wp_items_count }} articles</span>
                            @if($source->last_fetched_at)
                                <span>Dernière récup. {{ $source->last_fetched_at->diffForHumans() }}</span>
                            @else
                                <span class="text-amber-500">Jamais récupéré</span>
                            @endif
                            @if($source->socialAccounts->count() > 0)
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                                    </svg>
                                    {{ $source->socialAccounts->count() }} compte(s)
                                </span>
                            @endif
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center gap-1 flex-shrink-0">
                        <form method="POST" action="{{ route('wordpress-sites.fetch', $source) }}" class="inline">
                            @csrf
                            <button type="submit"
                                    class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                    title="Récupérer maintenant">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                                </svg>
                            </button>
                        </form>
                        <a href="{{ route('wordpress-sites.preview', $source) }}"
                           class="p-2 text-gray-400 hover:text-purple-600 hover:bg-purple-50 rounded-lg transition-colors"
                           title="Prévisualiser et générer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456Z" />
                            </svg>
                        </a>
                        <a href="{{ route('wordpress-sites.edit', $source) }}"
                           class="p-2 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                           title="Modifier">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                            </svg>
                        </a>
                        <form method="POST" action="{{ route('wordpress-sites.destroy', $source) }}"
                              onsubmit="return confirm('Supprimer ce site WordPress et tout son contenu ?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                    title="Supprimer">
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

@endsection
