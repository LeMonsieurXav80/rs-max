@extends('layouts.app')

@section('title', 'Nouveau partenaire')

@section('actions')
    <a href="{{ route('partners.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 text-gray-600 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
        </svg>
        Retour
    </a>
@endsection

@section('content')
    <form method="POST" action="{{ route('partners.store') }}" class="max-w-3xl">
        @csrf

        @include('partners._form', ['partner' => null])

        <div class="flex items-center gap-3 mt-6">
            <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                Créer le partenaire
            </button>
            <a href="{{ route('partners.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annuler</a>
        </div>
    </form>
@endsection
