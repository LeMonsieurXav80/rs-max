{{--
    Brique « Grille de chiffres ».
    Slots : title (optionnel), items (une ligne = « chiffre | libellé »), columns.
    Sans image : fond du thème + chiffres à l'accent.
--}}
@php
    $title = trim((string) ($data['title'] ?? ''));
    $items = \App\Services\Carousel\Lines::parse($data['items'] ?? null, 6);
    $cols = (int) ($data['columns'] ?? 2);

    $bg = \App\Services\Carousel\Palette::background($theme);
    $text = \App\Services\Carousel\Palette::text($theme);
    // Libellés : couleur dédiée si réglée, sinon texte principal atténué (rendu d'origine).
    $textSecondary = \App\Services\Carousel\Palette::textSecondary($theme);
    $fadeSecondary = \App\Services\Carousel\Palette::fade($theme, 'text_secondary', 0.78);
    $accent = \App\Services\Carousel\Palette::accent($theme);
    // Le voile de fond part vers la seconde couleur de marque quand elle existe.
    $accentAlt = \App\Services\Carousel\Palette::accentSecondary($theme);
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    // Échelle typographique du thème : les chiffres sont composés en police de
    // titre, les libellés en police de texte.
    $ts = \App\Services\Carousel\Typography::title($theme);
    $bs = \App\Services\Carousel\Typography::body($theme);

    $pad = (int) round($w * 0.09);
    $titleSize = (int) round($h * 0.046 * $ts);

    // Le chiffre rétrécit quand la grille se densifie (nb d'items et de colonnes).
    $rows = max(1, (int) ceil(max(1, count($items)) / max(1, $cols)));
    $numSize = (int) round($h * match (true) {
        $rows >= 3 => 0.070,
        $rows === 2 => 0.090,
        default => 0.110,
    } * $ts / max(1, $cols / 2));
    $labelSize = (int) round($h * 0.024 * $bs);
@endphp

<div style="position:absolute; inset:0;
            background:linear-gradient(160deg, {{ $bg }} 0%, {{ $bg }} 60%, {{ $accentAlt }}1f 100%);
            display:flex; flex-direction:column; justify-content:center;
            padding:{{ $pad }}px;">

    @if ($title !== '')
        <h2 style="margin:0 0 {{ (int) round($h * 0.05) }}px; font-family:'{{ $titleFont }}',sans-serif;
                   font-weight:800; font-size:{{ $titleSize }}px; line-height:1.12;
                   letter-spacing:-0.01em; color:{{ $text }}; text-wrap:balance;">{{ $title }}</h2>
    @endif

    <div style="display:grid; grid-template-columns:repeat({{ max(1, $cols) }}, 1fr);
                align-items:start;
                gap:{{ (int) round($h * 0.045) }}px {{ (int) round($w * 0.06) }}px;">
        @foreach ($items as [$value, $label])
            @php
                // Une valeur longue (« 12 sem. », « 1 036 € ») doit rester sur UNE ligne :
                // on la rétrécit plutôt que de la laisser passer à la ligne.
                $len = mb_strlen($value);
                $itemSize = (int) round($numSize * match (true) {
                    $len > 7 => 0.55,
                    $len > 5 => 0.72,
                    default => 1.0,
                });
            @endphp
            <div>
                <div style="font-family:'{{ $titleFont }}',sans-serif; font-weight:800;
                            font-size:{{ $itemSize }}px; line-height:1; letter-spacing:-0.03em;
                            color:{{ $accent }};">{{ $value }}</div>
                @if ($label !== '')
                    <div style="margin-top:{{ (int) round($h * 0.012) }}px; font-family:'{{ $bodyFont }}',sans-serif;
                                font-weight:400; font-size:{{ $labelSize }}px; line-height:1.3;
                                color:{{ $textSecondary }}; opacity:{{ $fadeSecondary }};">{{ $label }}</div>
                @endif
            </div>
        @endforeach
    </div>
</div>
