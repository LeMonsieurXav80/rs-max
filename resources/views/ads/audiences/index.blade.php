@extends('layouts.app')

@section('title', 'Audiences Meta')

@section('actions')
    <a href="{{ route('ads.audiences.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Nouvelle audience
    </a>
@endsection

@section('content')

    @include('ads._flash')

    <div class="mb-6">
        <a href="{{ route('ads.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Publicités</a>
        <p class="text-sm text-gray-500 mt-2 max-w-3xl">
            Meta enterre le ciblage dans l'ad set : il meurt avec lui. Les audiences enregistrées ici
            se redéroulent à chaque campagne — c'est ce qui permet de dire « la même que la dernière fois »
            sans la refaire de mémoire.
        </p>
    </div>

    @if(! $configured)
        <div class="mb-6 rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
            Compte publicitaire non configuré : la recherche de centres d'intérêt et l'estimation de portée
            resteront vides. Les audiences s'enregistrent quand même.
        </div>
    @endif

    @if($audiences->isEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
            <h3 class="text-base font-semibold text-gray-900 mb-2">Aucune audience</h3>
            <p class="text-sm text-gray-500 mb-8 max-w-lg mx-auto">
                Sans audience enregistrée, une sponsorisation part sur un ciblage large par défaut
                ({{ implode(', ', config('meta_ads.default_targeting.countries')) }},
                {{ config('meta_ads.default_targeting.age_min') }}-{{ config('meta_ads.default_targeting.age_max') }} ans).
            </p>
            <a href="{{ route('ads.audiences.create') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700">
                Créer une audience
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @foreach($audiences as $audience)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-gray-900">{{ $audience->name }}</h3>
                                @if($audience->is_default)
                                    <span class="text-[10px] px-2 py-0.5 rounded bg-indigo-50 text-indigo-700">par défaut</span>
                                @endif
                            </div>
                            @if($audience->description)
                                <p class="text-xs text-gray-500 mt-1">{{ $audience->description }}</p>
                            @endif
                            <p class="text-xs text-gray-600 mt-2">{{ $audience->summary() }}</p>
                            @if($audience->estimated_reach)
                                <p class="text-xs text-gray-400 mt-1">
                                    ~{{ number_format($audience->estimated_reach, 0, ',', ' ') }} personnes
                                    ({{ $audience->estimated_at?->diffForHumans() }})
                                </p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <a href="{{ route('ads.audiences.edit', $audience) }}" class="text-xs text-indigo-600 hover:underline">Modifier</a>
                            <form method="POST" action="{{ route('ads.audiences.destroy', $audience) }}"
                                  onsubmit="return confirm('Supprimer cette audience ? Les campagnes en cours gardent leur ciblage.')">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs text-red-600 hover:underline">Supprimer</button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
