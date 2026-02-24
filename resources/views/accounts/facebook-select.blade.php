@extends('layouts.app')

@section('title', 'Connecter Facebook & Instagram')

@section('content')
    <div class="max-w-2xl mx-auto">
        <div class="mb-6">
            <a href="{{ route('accounts.index') }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
                Retour aux comptes
            </a>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <h2 class="text-base font-semibold text-gray-900">Sélectionnez les comptes à connecter</h2>
                <p class="text-sm text-gray-500 mt-1">Nous avons trouvé {{ count($pages) }} Page(s) Facebook sur votre compte.</p>
            </div>

            <form action="{{ route('facebook.connect') }}" method="POST">
                @csrf

                <div class="divide-y divide-gray-100">
                    @foreach($pages as $page)
                        <div class="p-6" x-data="{ pageSelected: true, igSelected: true }">
                            {{-- Facebook Page --}}
                            <label class="flex items-start gap-4 cursor-pointer">
                                <input
                                    type="checkbox"
                                    name="selected_pages[]"
                                    value="{{ $page['id'] }}"
                                    checked
                                    x-model="pageSelected"
                                    class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                >
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-3">
                                        @if(! empty($page['picture_url']))
                                            <img src="{{ $page['picture_url'] }}" alt="{{ $page['name'] }}" class="w-10 h-10 rounded-xl object-cover flex-shrink-0">
                                        @else
                                            <div class="w-10 h-10 rounded-xl bg-[#1877F2] flex items-center justify-center flex-shrink-0">
                                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                                </svg>
                                            </div>
                                        @endif
                                        <div>
                                            <p class="text-sm font-semibold text-gray-900">{{ $page['name'] }}</p>
                                            <p class="text-xs text-gray-400">{{ $page['category'] ?? 'Page Facebook' }} &middot; ID: {{ $page['id'] }}</p>
                                        </div>
                                    </div>
                                </div>
                            </label>

                            {{-- Instagram Business Account (if connected to this page) --}}
                            @if(! empty($page['instagram']))
                                <div class="ml-14 mt-4 pl-4 border-l-2 border-gray-100" x-show="pageSelected" x-transition>
                                    <label class="flex items-start gap-4 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            name="selected_instagram[]"
                                            value="{{ $page['instagram']['id'] }}"
                                            checked
                                            x-model="igSelected"
                                            :disabled="!pageSelected"
                                            class="mt-1 rounded border-gray-300 text-pink-500 focus:ring-pink-500"
                                        >
                                        <div class="flex items-center gap-3">
                                            @if(! empty($page['instagram']['profile_picture_url']))
                                                <img src="{{ $page['instagram']['profile_picture_url'] }}" alt="{{ $page['instagram']['username'] ?? 'Instagram' }}" class="w-10 h-10 rounded-xl object-cover flex-shrink-0">
                                            @else
                                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 via-pink-500 to-orange-400 flex items-center justify-center flex-shrink-0">
                                                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
                                                        <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/>
                                                    </svg>
                                                </div>
                                            @endif
                                            <div>
                                                <p class="text-sm font-semibold text-gray-900">
                                                    {{ $page['instagram']['username'] ?? $page['instagram']['name'] ?? 'Instagram' }}
                                                </p>
                                                <p class="text-xs text-gray-400">Compte Instagram Business &middot; ID: {{ $page['instagram']['id'] }}</p>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
                    <a href="{{ route('accounts.index') }}" class="text-sm text-gray-500 hover:text-gray-700 transition-colors">
                        Annuler
                    </a>
                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m9.86-2.06a4.5 4.5 0 0 0-1.242-7.244l-4.5-4.5a4.5 4.5 0 0 0-6.364 6.364L4.757 8.81" />
                        </svg>
                        Connecter les comptes sélectionnés
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
