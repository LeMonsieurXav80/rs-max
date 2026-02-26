@extends('layouts.app')

@section('title', 'Connecter une chaîne YouTube')

@section('content')
    <div class="max-w-2xl mx-auto">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Connecter une chaîne YouTube</h1>
            <p class="mt-2 text-sm text-gray-600">
                Confirmez la connexion de votre chaîne YouTube à RS-Max.
            </p>
        </div>

        {{-- Warning message --}}
        <div class="mb-6 rounded-xl bg-amber-50 border border-amber-200 p-4">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                <div>
                    <h3 class="text-sm font-medium text-amber-800">Information importante</h3>
                    <p class="mt-1 text-sm text-amber-700">
                        Seul le <strong>propriétaire</strong> de la chaîne YouTube peut autoriser l'accès API.
                        Les managers et éditeurs ne peuvent pas connecter la chaîne via OAuth.
                    </p>
                </div>
            </div>
        </div>

        {{-- Channel card --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-start gap-4">
                @if($channel['channel_thumbnail'])
                    <img src="{{ $channel['channel_thumbnail'] }}" alt="{{ $channel['channel_name'] }}" class="w-16 h-16 rounded-full object-cover flex-shrink-0">
                @else
                    <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                        <svg class="w-8 h-8 text-red-600" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                        </svg>
                    </div>
                @endif

                <div class="flex-1 min-w-0">
                    <h2 class="text-lg font-semibold text-gray-900 truncate">
                        {{ $channel['channel_name'] }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        ID: {{ $channel['channel_id'] }}
                    </p>
                    <div class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-50 text-green-700 text-xs font-medium rounded-full">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        Autorisé
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('youtube.store') }}" class="mt-6">
                @csrf

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                    <a href="{{ route('accounts.index') }}" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 transition-colors">
                        Annuler
                    </a>
                    <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 bg-red-600 text-white text-sm font-medium rounded-xl hover:bg-red-700 transition-colors shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                        </svg>
                        Connecter cette chaîne
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
