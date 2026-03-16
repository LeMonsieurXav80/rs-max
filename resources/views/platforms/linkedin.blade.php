@extends('layouts.app')

@section('title', 'LinkedIn')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">

        {{-- Connexion OAuth --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[#0A66C2]/10 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-[#0A66C2]" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Connexion OAuth</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Liez votre profil LinkedIn ou vos pages entreprise pour publier automatiquement.</p>
                    </div>
                </div>
            </div>

            <div class="p-6">
                <a href="{{ route('linkedin.redirect') }}" class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#0A66C2] text-white text-sm font-medium rounded-xl hover:bg-[#004182] transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m9.86-2.06a4.5 4.5 0 0 0-1.242-7.244l-4.5-4.5a4.5 4.5 0 0 0-6.364 6.364L4.757 8.81" />
                    </svg>
                    Connecter un compte LinkedIn
                </a>
            </div>
        </div>

        {{-- Comptes LinkedIn connectes --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900">Comptes LinkedIn</h3>
                <span class="text-xs text-gray-400">{{ $accounts->count() }} compte(s)</span>
            </div>

            @if($accounts->isEmpty())
                <div class="px-6 py-8 text-center">
                    <p class="text-sm text-gray-400">Aucun compte LinkedIn connecte.</p>
                </div>
            @else
                <div class="divide-y divide-gray-50">
                    @foreach($accounts as $account)
                        <div class="px-6 py-4 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                @if($account->profile_picture_url)
                                    <img src="{{ $account->profile_picture_url }}" alt="{{ $account->name }}" class="w-9 h-9 rounded-lg object-cover flex-shrink-0">
                                @else
                                    <div class="w-9 h-9 rounded-lg bg-[#0A66C2]/10 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-4 h-4 text-[#0A66C2]" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                                        </svg>
                                    </div>
                                @endif
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $account->name }}</p>
                                    <p class="text-xs text-gray-400">
                                        {{ ($account->credentials['account_type'] ?? 'person') === 'organization' ? 'Page entreprise' : 'Profil personnel' }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
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
