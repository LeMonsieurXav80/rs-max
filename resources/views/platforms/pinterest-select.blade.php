@extends('layouts.app')

@section('title', 'Connecter un compte Pinterest')

@section('content')
    <div class="max-w-2xl mx-auto">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Connecter un compte Pinterest</h1>
            <p class="mt-2 text-sm text-gray-600">
                Confirmez la connexion de votre compte Pinterest à RS-Max.
            </p>
        </div>

        <div class="mb-6 rounded-xl bg-amber-50 border border-amber-200 p-4">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                <div>
                    <h3 class="text-sm font-medium text-amber-800">Mode Trial</h3>
                    <p class="mt-1 text-sm text-amber-700">
                        L'API Pinterest est en mode <strong>lecture seule</strong>. La publication se fait via les flux RSS auto-publish.
                        Ce compte sert à récupérer vos tableaux et vérifier les pins publiés.
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-start gap-4">
                @if($pinterestUser['profile_image'])
                    <img src="{{ $pinterestUser['profile_image'] }}" alt="{{ $pinterestUser['display_name'] }}" class="w-16 h-16 rounded-full object-cover flex-shrink-0">
                @else
                    <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                        <svg class="w-8 h-8" fill="#E60023" viewBox="0 0 24 24">
                            <path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 0 1 .083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.988C24.007 5.367 18.641 0 12.017 0z"/>
                        </svg>
                    </div>
                @endif

                <div class="flex-1 min-w-0">
                    <h2 class="text-lg font-semibold text-gray-900 truncate">
                        {{ $pinterestUser['display_name'] }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        @{{ $pinterestUser['username'] }}
                    </p>
                    <div class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-50 text-green-700 text-xs font-medium rounded-full">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        Autorisé (lecture seule)
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('pinterest.connect') }}" class="mt-6">
                @csrf

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                    <a href="{{ route('accounts.index') }}" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 transition-colors">
                        Annuler
                    </a>
                    <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 text-white text-sm font-medium rounded-xl hover:opacity-90 transition-colors shadow-sm" style="background-color: #E60023">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                        </svg>
                        Connecter ce compte
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
