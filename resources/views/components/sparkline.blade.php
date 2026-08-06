@props([
    'points' => [],      // valeurs numériques, dans l'ordre chronologique
    'labels' => [],      // libellé de survol par point (facultatif)
    'label' => 'Valeur', // nom de la série (une seule série : pas de légende)
    'width' => 96,
    'height' => 24,
])

@php
    $values = array_values(array_map('intval', $points));
    $count = count($values);

    $pad = 3; // marge pour que le marqueur de fin ne soit pas rogné
    $w = (int) $width;
    $h = (int) $height;
    $innerW = $w - 2 * $pad;
    $innerH = $h - 2 * $pad;

    $min = $count > 0 ? min($values) : 0;
    $max = $count > 0 ? max($values) : 0;
    $span = $max - $min;

    // Série plate : on la pose à mi-hauteur plutôt que de diviser par zéro.
    $coords = [];
    foreach ($values as $i => $v) {
        $x = $count > 1 ? $pad + ($i / ($count - 1)) * $innerW : $pad + $innerW / 2;
        $y = $span > 0
            ? $pad + $innerH - (($v - $min) / $span) * $innerH
            : $pad + $innerH / 2;
        $coords[] = [round($x, 2), round($y, 2)];
    }

    $polyline = implode(' ', array_map(fn ($c) => $c[0].','.$c[1], $coords));
    $last = $coords[$count - 1] ?? null;
@endphp

@if($count === 0)
    <span {{ $attributes->merge(['class' => 'text-xs text-gray-300']) }}>—</span>
@else
    <svg {{ $attributes->merge(['class' => 'text-indigo-500']) }}
         width="{{ $w }}" height="{{ $h }}" viewBox="0 0 {{ $w }} {{ $h }}"
         fill="none" role="img"
         aria-label="{{ $label }} : évolution sur {{ $count }} relevés, de {{ number_format($min, 0, ',', ' ') }} à {{ number_format($values[$count - 1], 0, ',', ' ') }}">
        <title>{{ $label }} — {{ number_format($values[$count - 1], 0, ',', ' ') }} au dernier relevé</title>

        @if($count > 1)
            <polyline points="{{ $polyline }}"
                      stroke="currentColor" stroke-width="2"
                      stroke-linecap="round" stroke-linejoin="round" />
        @endif

        {{-- Extrémité de la donnée, cerclée de la couleur de surface pour rester lisible sur la ligne --}}
        @if($last)
            <circle cx="{{ $last[0] }}" cy="{{ $last[1] }}" r="2.5"
                    fill="currentColor" stroke="#fff" stroke-width="2" />
        @endif

        {{-- Zones de survol : infobulle native, sans JS --}}
        @foreach($coords as $i => $c)
            <circle cx="{{ $c[0] }}" cy="{{ $c[1] }}" r="5" fill="transparent">
                <title>{{ $labels[$i] ?? '' }}{{ isset($labels[$i]) ? ' — ' : '' }}{{ number_format($values[$i], 0, ',', ' ') }}</title>
            </circle>
        @endforeach
    </svg>
@endif
