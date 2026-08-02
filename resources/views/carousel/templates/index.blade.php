@extends('layouts.app')

@section('title', 'Templates de carrousel')

@section('actions')
    <a href="{{ route('carousel.templates.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Nouveau template
    </a>
@endsection

@section('content')

    @foreach (['template-created' => 'Template créé.', 'template-updated' => 'Template mis à jour.', 'template-deleted' => 'Template supprimé.'] as $key => $message)
        @if (session('status') === $key)
            <div class="mb-6" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)">
                <div class="rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                    <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <p class="text-sm text-green-700">{{ $message }}</p>
                </div>
            </div>
        @endif
    @endforeach

    @unless ($migrated)
        <div class="mb-6 rounded-xl bg-amber-50 border border-amber-200 p-4">
            <p class="text-sm text-amber-800 font-medium">Migration en attente</p>
            <p class="text-xs text-amber-700 mt-1">
                La table <code>carousel_bricks</code> n’existe pas encore : lance
                <code>php artisan migrate</code>. En attendant, seuls les templates fournis
                sont affichés et la création est indisponible.
            </p>
        </div>
    @endunless

    <p class="text-sm text-gray-500 mb-6">
        Un template est une mise en page de slide. Les templates <span class="font-medium text-gray-700">fournis</span>
        sont livrés avec l’application : duplique-les pour les adapter. Les autres sont modifiables directement.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
        @foreach ($templates as $t)
            @php
                // L'aperçu est rendu à l'échelle : largeur de carte fixe, hauteur déduite du ratio.
                $cardW = 260;
                $scale = $cardW / $dims['w'];
            @endphp
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col">

                {{-- Aperçu visuel réel du template (iframe, aucun Chromium) --}}
                <div class="relative bg-gray-100 overflow-hidden" style="height: {{ (int) round($dims['h'] * $scale) }}px;">
                    <iframe srcdoc="{{ $t['preview'] }}" scrolling="no" loading="lazy"
                            class="border-0 origin-top-left absolute top-0 left-0 pointer-events-none"
                            style="width: {{ $dims['w'] }}px; height: {{ $dims['h'] }}px; transform: scale({{ $scale }});"></iframe>
                </div>

                <div class="p-4 flex-1 flex flex-col">
                    <div class="flex items-start justify-between gap-2 mb-1">
                        <h3 class="text-sm font-semibold text-gray-900">{{ $t['name'] }}</h3>
                        @if ($t['is_builtin'])
                            <span class="flex-shrink-0 text-[10px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">fourni</span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-400 line-clamp-2 flex-1">{{ $t['description'] }}</p>

                    <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-100">
                        <span class="text-[11px] text-gray-400">{{ $t['slots'] }} champ{{ $t['slots'] > 1 ? 's' : '' }}</span>
                        <div class="flex items-center gap-3">
                            <a href="{{ route('carousel.templates.create', ['from' => $t['slug']]) }}"
                               class="text-xs text-gray-500 hover:text-indigo-600">Dupliquer</a>

                            @if (! $t['is_builtin'])
                                <a href="{{ route('carousel.templates.edit', $t['id']) }}"
                                   class="text-xs font-medium text-indigo-600 hover:text-indigo-700">Modifier</a>
                                <form method="POST" action="{{ route('carousel.templates.destroy', $t['id']) }}"
                                      onsubmit="return confirm('Supprimer définitivement ce template ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs text-red-400 hover:text-red-600">Supprimer</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
