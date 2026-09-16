@props(['sourceType' => null])

{{--
    Signale d'ou vient une publication quand ce n'est pas du composer.

    « Natif » est le cas qui compte a l'oeil : la publication a ete faite sur le
    reseau, hors RS-Max, puis rapatriee. Sans ce reperage, une adoption
    automatique ferait apparaitre des publications que personne ne se souvient
    d'avoir ecrites ici.
--}}
@php
    $badges = [
        'native' => ['Natif', 'bg-purple-100 text-purple-700'],
        'reshare' => ['Repartage', 'bg-indigo-100 text-indigo-700'],
    ];

    $badge = $badges[$sourceType] ?? null;
@endphp

@if($badge)
    <span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium {$badge[1]}"]) }}
          title="{{ $sourceType === 'native' ? 'Publiée nativement sur le réseau, puis rapatriée dans RS-Max' : 'Repartage d’une publication existante' }}">
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v13.5m0 0 4.5-4.5M12 16.5 7.5 12M3.75 18.75h16.5" />
        </svg>
        {{ $badge[0] }}
    </span>
@endif
