@extends('layouts.app')

@section('title', 'LinkedIn - Selection des comptes')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[#0A66C2]/10 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-[#0A66C2]" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Selectionner les comptes a connecter</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Choisissez votre profil personnel et/ou vos pages entreprise.</p>
                    </div>
                </div>
            </div>

            <form action="{{ route('linkedin.connect') }}" method="POST">
                @csrf

                <div class="divide-y divide-gray-50">
                    {{-- Personal profile --}}
                    <label class="flex items-center gap-3 px-6 py-4 cursor-pointer hover:bg-gray-50 transition-colors">
                        <input type="checkbox" name="accounts[]" value="{{ $profile['id'] }}" checked
                               class="w-4 h-4 rounded border-gray-300 text-[#0A66C2] focus:ring-[#0A66C2]">
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            @if($profile['picture'])
                                <img src="{{ $profile['picture'] }}" alt="{{ $profile['name'] }}" class="w-9 h-9 rounded-lg object-cover flex-shrink-0">
                            @else
                                <div class="w-9 h-9 rounded-lg bg-[#0A66C2]/10 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4 h-4 text-[#0A66C2]" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                    </svg>
                                </div>
                            @endif
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ $profile['name'] }}</p>
                                <p class="text-xs text-gray-400">Profil personnel</p>
                            </div>
                        </div>
                    </label>

                    {{-- Organization pages --}}
                    @foreach($organizations as $org)
                        <label class="flex items-center gap-3 px-6 py-4 cursor-pointer hover:bg-gray-50 transition-colors">
                            <input type="checkbox" name="accounts[]" value="{{ $org['id'] }}" checked
                                   class="w-4 h-4 rounded border-gray-300 text-[#0A66C2] focus:ring-[#0A66C2]">
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                @if($org['logo'])
                                    <img src="{{ $org['logo'] }}" alt="{{ $org['name'] }}" class="w-9 h-9 rounded-lg object-cover flex-shrink-0">
                                @else
                                    <div class="w-9 h-9 rounded-lg bg-[#0A66C2]/10 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-4 h-4 text-[#0A66C2]" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5M3.75 3v18m16.5-18v18M5.25 3h13.5M5.25 21V6.75A2.25 2.25 0 0 1 7.5 4.5h9A2.25 2.25 0 0 1 18.75 6.75V21m-13.5-9h1.5m3 0h1.5m-1.5 3h1.5m-3 0h1.5m6-6h.008v.008h-.008V9Zm0 3h.008v.008h-.008V12Zm0 3h.008v.008h-.008V15Z" />
                                        </svg>
                                    </div>
                                @endif
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $org['name'] }}</p>
                                    <p class="text-xs text-gray-400">Page entreprise</p>
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>

                @if(empty($organizations))
                    <div class="px-6 py-3 bg-amber-50 border-t border-amber-100">
                        <p class="text-xs text-amber-700">Aucune page entreprise trouvee. Vous devez etre administrateur de la page sur LinkedIn pour la connecter.</p>
                    </div>
                @endif

                <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between">
                    <a href="{{ route('platforms.linkedin') }}" class="text-sm text-gray-500 hover:text-gray-700">Annuler</a>
                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#0A66C2] text-white text-sm font-medium rounded-xl hover:bg-[#004182] transition-colors shadow-sm">
                        Connecter les comptes selectionnes
                    </button>
                </div>
            </form>
        </div>

    </div>
@endsection
