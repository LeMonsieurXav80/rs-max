@extends('layouts.app')

@section('title', 'Bot Actions')

@section('actions')
    <a href="{{ route('bot.logs') }}"
       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
        </svg>
        Voir les logs
    </a>
@endsection

@section('content')
    <div class="space-y-6">

        @if (session('success'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-xl bg-green-50 border border-green-200 p-4 text-sm text-green-700 font-medium">
                {{ session('success') }}
            </div>
        @endif

        {{-- KPIs du jour --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-sm font-semibold text-gray-900 mb-4">Activite du jour</h3>
            @if ($todayStats->isEmpty())
                <p class="text-sm text-gray-500 italic">Aucune action aujourd'hui.</p>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                    @foreach ($todayStats as $type => $total)
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500">{{ str_replace('_', ' ', $type) }}</div>
                            <div class="text-2xl font-semibold text-gray-900 mt-1">{{ $total }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Cards des plateformes --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            @foreach ($platformCards as $card)
                <a href="{{ route($card['route']) }}"
                   class="group block bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md hover:border-indigo-200 transition-all p-6">
                    <div class="flex items-start justify-between mb-3">
                        <h2 class="text-lg font-semibold text-gray-900">{{ $card['name'] }}</h2>
                        <span class="text-xs text-gray-500 bg-gray-100 rounded-full px-2 py-0.5">
                            {{ $card['accounts_count'] }} {{ $card['accounts_count'] > 1 ? 'comptes' : 'compte' }}
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($card['tabs'] as $tab)
                            <span class="text-xs bg-indigo-50 text-indigo-700 rounded-md px-2 py-1">{{ $tab }}</span>
                        @endforeach
                    </div>
                    <div class="mt-4 text-sm text-gray-500 group-hover:text-indigo-600 transition-colors">
                        Configurer →
                    </div>
                </a>
            @endforeach
        </div>

    </div>
@endsection
