{{--
    Brique « Texte long ».
    Slots : title (OPTIONNEL), body, align, image de fond (optionnelle), position, offset.

    Différence avec `text-on-image` : celle-ci sert un paragraphe court sous un
    grand titre. Ici c'est le TEXTE qui commande — jusqu'à ~1800 caractères, la
    taille descend par paliers et l'interligne s'ouvre, pour rester lisible sur
    un écran de téléphone plutôt que de déborder du cadre.
--}}
@php
    $title = trim((string) ($data['title'] ?? ''));
    $body = trim((string) ($data['body'] ?? ''));
    $image = $data['image'] ?? null;
    $align = (string) ($data['align'] ?? 'left');
    $align = in_array($align, ['left', 'center', 'justify'], true) ? $align : 'left';

    $bg = \App\Services\Carousel\Palette::background($theme);
    $text = \App\Services\Carousel\Palette::text($theme);
    $textSecondary = \App\Services\Carousel\Palette::textSecondary($theme);
    $fadeSecondary = \App\Services\Carousel\Palette::fade($theme, 'text_secondary', 0.92);
    $accent = \App\Services\Carousel\Palette::accent($theme);
    $accentAlt = \App\Services\Carousel\Palette::accentSecondary($theme);
    $overlay = \App\Services\Carousel\Palette::overlay($theme);
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    $anchor = \App\Services\Carousel\Anchor::resolve($data['position'] ?? 'middle-left', $image ? $overlay : $bg);
    $shift = \App\Services\Carousel\Anchor::offsetTransform($data['offset'] ?? 0, $h);

    $ts = \App\Services\Carousel\Typography::title($theme);
    $bs = \App\Services\Carousel\Typography::body($theme);

    $pad = (int) round($w * 0.09);
    $titleSize = (int) round($h * (mb_strlen($title) > 60 ? 0.042 : 0.050) * $ts);

    // Paliers de lecture : un texte de 300 signes peut respirer, un de 1500 doit
    // se resserrer. L'interligne suit en sens inverse — plus le corps est petit,
    // plus l'air entre les lignes compte pour que le bloc reste lisible.
    $len = mb_strlen($body);
    [$bodyFraction, $lineHeight] = match (true) {
        $len > 1200 => [0.0185, 1.50],
        $len > 800 => [0.0215, 1.50],
        $len > 500 => [0.0245, 1.55],
        $len > 250 => [0.0280, 1.55],
        default => [0.0320, 1.60],
    };
    $bodySize = (int) round($h * $bodyFraction * $bs);

    // Les paragraphes sont séparés par une ligne vide dans le champ ; on les rend
    // comme des paragraphes plutôt que d'aplatir en un pavé unique.
    $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/u', $body) ?: [])));
@endphp

<div style="position:absolute; inset:0;
            background:{{ $image ? $overlay : 'linear-gradient(160deg, '.$bg.' 0%, '.$bg.' 62%, '.$accentAlt.'1a 100%)' }};">
    @if ($image)
        {{-- Cadre de l'image : la slide entière, ou tout le groupe quand la photo
             se prolonge sur la ou les slides suivantes (Backdrop). --}}
        <div style="{{ \App\Services\Carousel\Backdrop::frame($data, $w, $h) }}">
            <img src="{{ $image }}" alt=""
                 style="display:block; width:100%; height:100%; object-fit:cover;">
        </div>
        <div style="{{ $anchor['scrim'] }}"></div>
    @endif

    <div style="position:absolute; inset:0; display:flex; flex-direction:column;
                justify-content:{{ $anchor['justify'] }}; align-items:{{ $anchor['align'] }};
                padding:{{ $pad }}px; {{ $shift }}">
        <div style="max-width:100%;">
            @if ($title !== '')
                {{-- Filet d'accent : marque le départ de lecture quand il y a un titre. --}}
                <span style="display:block; width:{{ (int) round($w * 0.10) }}px; height:{{ (int) round($h * 0.008) }}px;
                             background:{{ $accent }}; border-radius:999px;
                             margin-bottom:{{ (int) round($h * 0.028) }}px;
                             {{ $align === 'center' ? 'margin-left:auto; margin-right:auto;' : '' }}"></span>

                <h2 style="margin:0 0 {{ (int) round($h * 0.030) }}px; font-family:'{{ $titleFont }}',sans-serif;
                           font-weight:800; font-size:{{ $titleSize }}px; line-height:1.12;
                           letter-spacing:-0.015em; color:{{ $text }};
                           text-align:{{ $align === 'justify' ? 'left' : $align }}; text-wrap:balance;">{{ $title }}</h2>
            @endif

            @foreach ($paragraphs as $i => $paragraph)
                <p style="margin:{{ $i === 0 ? 0 : (int) round($h * 0.020) }}px 0 0;
                          font-family:'{{ $bodyFont }}',sans-serif; font-weight:400;
                          font-size:{{ $bodySize }}px; line-height:{{ $lineHeight }};
                          color:{{ $textSecondary }}; opacity:{{ $fadeSecondary }};
                          text-align:{{ $align }};
                          {{ $align === 'justify' ? 'hyphens:auto;' : '' }}">{{ $paragraph }}</p>
            @endforeach
        </div>
    </div>
</div>
