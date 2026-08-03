{{--
    Brique « Histogramme ».
    Slots : title (optionnel), items (une ligne = « libellé | valeur »),
    direction (barres horizontales ou colonnes), note (optionnelle).

    La valeur est affichée TELLE QU'ÉCRITE (« 42 % », « 1 036 € ») : c'est le
    nombre qu'elle contient qui donne la longueur de la barre, proportionnelle au
    plus grand de la série. Pas d'axe ni de graduation — à cette taille, sur un
    téléphone, ils ajoutent du bruit sans rien apprendre.
--}}
@php
    $title = trim((string) ($data['title'] ?? ''));
    $note = trim((string) ($data['note'] ?? ''));
    $columns = ($data['direction'] ?? 'bars') === 'columns';
    $items = \App\Services\Carousel\Lines::parse($data['items'] ?? null, $columns ? 6 : 8);

    $bg = \App\Services\Carousel\Palette::background($theme);
    $text = \App\Services\Carousel\Palette::text($theme);
    $textSecondary = \App\Services\Carousel\Palette::textSecondary($theme);
    $fadeSecondary = \App\Services\Carousel\Palette::fade($theme, 'text_secondary', 0.85);
    $textMuted = \App\Services\Carousel\Palette::textMuted($theme);
    $fadeMuted = \App\Services\Carousel\Palette::fade($theme, 'text_muted', 0.55);
    $accent = \App\Services\Carousel\Palette::accent($theme);
    $accentAlt = \App\Services\Carousel\Palette::accentSecondary($theme);
    // Piste des barres : le fond secondaire s'il est réglé, sinon un voile tiré du
    // texte — il reste visible aussi bien sur un fond clair que sombre.
    $track = \App\Services\Carousel\Palette::isSet($theme, 'background_alt')
        ? \App\Services\Carousel\Palette::backgroundAlt($theme)
        : \App\Services\Carousel\Palette::alpha($text, 0.12);
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    $ts = \App\Services\Carousel\Typography::title($theme);
    $bs = \App\Services\Carousel\Typography::body($theme);

    // Nombre contenu dans la valeur écrite : « 1 036,5 € » => 1036.5. Les espaces
    // (y compris insécables) sont des séparateurs de milliers, la virgule une
    // décimale — sinon « 1 036 » se lirait 1.
    $numberOf = function (string $raw): float {
        $clean = preg_replace('/[^\d,.\-]/u', '', str_replace([',', ' ', "\u{202f}", "\u{a0}"], ['.', '', '', ''], $raw));

        return is_numeric($clean) ? (float) $clean : 0.0;
    };

    $values = array_map(fn (array $item) => $numberOf($item[1]), $items);
    $max = $values ? max($values) : 0.0;
    // Série vide ou à zéro : barres à zéro plutôt qu'une division par zéro.
    $ratio = fn (float $v) => $max > 0 ? max(0.0, min(1.0, $v / $max)) : 0.0;

    $pad = (int) round($w * 0.09);
    $titleSize = (int) round($h * 0.046 * $ts);
    $count = max(1, count($items));
    $labelSize = (int) round($h * ($count > 6 ? 0.021 : 0.025) * $bs);
    $valueSize = (int) round($h * ($count > 6 ? 0.024 : 0.030) * $ts);
    $noteSize = (int) round($h * 0.021 * $bs);
    $barThickness = (int) round($h * ($count > 6 ? 0.026 : ($count > 4 ? 0.032 : 0.040)));
@endphp

<div style="position:absolute; inset:0;
            background:linear-gradient(160deg, {{ $bg }} 0%, {{ $bg }} 60%, {{ $accentAlt }}1f 100%);
            display:flex; flex-direction:column; justify-content:center;
            padding:{{ $pad }}px;">

    @if ($title !== '')
        <h2 style="margin:0 0 {{ (int) round($h * 0.040) }}px; font-family:'{{ $titleFont }}',sans-serif;
                   font-weight:800; font-size:{{ $titleSize }}px; line-height:1.12;
                   letter-spacing:-0.01em; color:{{ $text }}; text-wrap:balance;">{{ $title }}</h2>
    @endif

    @if ($columns)
        {{-- Colonnes : la hauteur porte la comparaison, les libellés passent dessous. --}}
        @php $plot = (int) round($h * 0.34); @endphp
        <div style="display:flex; align-items:flex-end; justify-content:space-between;
                    gap:{{ (int) round($w * 0.035) }}px; height:{{ $plot }}px;">
            @foreach ($items as $i => [$label, $value])
                <div style="flex:1 1 0; display:flex; flex-direction:column; justify-content:flex-end; height:100%;">
                    <div style="font-family:'{{ $titleFont }}',sans-serif; font-weight:800;
                                font-size:{{ $valueSize }}px; line-height:1.2; color:{{ $text }};
                                text-align:center; white-space:nowrap;">{{ $value }}</div>
                    {{-- Hauteur minimale : une valeur faible doit rester visible, pas disparaître. --}}
                    <div style="margin-top:{{ (int) round($h * 0.010) }}px;
                                height:{{ max(4, (int) round($plot * 0.72 * $ratio($values[$i] ?? 0))) }}px;
                                border-radius:{{ (int) round($h * 0.008) }}px;
                                background:linear-gradient(180deg, {{ $accent }} 0%, {{ $accentAlt }} 100%);"></div>
                </div>
            @endforeach
        </div>
        <div style="display:flex; justify-content:space-between; gap:{{ (int) round($w * 0.035) }}px;
                    margin-top:{{ (int) round($h * 0.014) }}px;">
            @foreach ($items as [$label, $value])
                <div style="flex:1 1 0; font-family:'{{ $bodyFont }}',sans-serif; font-weight:400;
                            font-size:{{ $labelSize }}px; line-height:1.25; text-align:center;
                            color:{{ $textSecondary }}; opacity:{{ $fadeSecondary }};">{{ $label }}</div>
            @endforeach
        </div>
    @else
        {{-- Barres horizontales : le libellé a toute la largeur, donc il peut être long. --}}
        <div>
            @foreach ($items as $i => [$label, $value])
                <div style="margin-top:{{ $i === 0 ? 0 : (int) round($h * 0.026) }}px;">
                    <div style="display:flex; align-items:baseline; justify-content:space-between;
                                gap:{{ (int) round($w * 0.04) }}px; margin-bottom:{{ (int) round($h * 0.010) }}px;">
                        <span style="font-family:'{{ $bodyFont }}',sans-serif; font-weight:400;
                                     font-size:{{ $labelSize }}px; line-height:1.25;
                                     color:{{ $textSecondary }}; opacity:{{ $fadeSecondary }};">{{ $label }}</span>
                        <span style="flex:0 0 auto; font-family:'{{ $titleFont }}',sans-serif; font-weight:800;
                                     font-size:{{ $valueSize }}px; line-height:1.2;
                                     color:{{ $text }}; white-space:nowrap;">{{ $value }}</span>
                    </div>
                    <div style="height:{{ $barThickness }}px; border-radius:999px; background:{{ $track }};">
                        <div style="height:100%; border-radius:999px;
                                    width:{{ round(100 * $ratio($values[$i] ?? 0), 2) }}%;
                                    min-width:{{ $barThickness }}px;
                                    background:linear-gradient(90deg, {{ $accent }} 0%, {{ $accentAlt }} 100%);"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($note !== '')
        <p style="margin:{{ (int) round($h * 0.032) }}px 0 0; font-family:'{{ $bodyFont }}',sans-serif;
                  font-weight:400; font-size:{{ $noteSize }}px; line-height:1.35;
                  color:{{ $textMuted }}; opacity:{{ $fadeMuted }};">{{ $note }}</p>
    @endif
</div>
