{{--
    Brique « Citation ».
    Slots : quote, author (optionnel), image de fond (optionnelle), position, offset.
    Guillemet typographique en accent, citation en police de titre.
--}}
@php
    $quote = trim((string) ($data['quote'] ?? ''));
    $author = trim((string) ($data['author'] ?? ''));
    $image = $data['image'] ?? null;

    $bg = $theme['background'] ?? '#0f0f1a';
    $text = $theme['text'] ?? '#ffffff';
    $accent = $theme['accent'] ?? '#0083ff';
    $overlay = $theme['overlay'] ?? '#000000';
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    $anchor = \App\Services\Carousel\Anchor::resolve($data['position'] ?? 'middle-left', $image ? $overlay : $bg);
    $shift = \App\Services\Carousel\Anchor::offsetTransform($data['offset'] ?? 0, $h);

    // Échelle typographique du thème : la citation est composée en police de
    // titre (guillemet compris), la signature en police de texte.
    $ts = \App\Services\Carousel\Typography::title($theme);
    $bs = \App\Services\Carousel\Typography::body($theme);

    $pad = (int) round($w * 0.10);
    // Une citation courte peut respirer en grand ; une longue doit rentrer.
    $quoteSize = (int) round($h * (mb_strlen($quote) > 160 ? 0.040 : (mb_strlen($quote) > 90 ? 0.050 : 0.062)) * $ts);
    $authorSize = (int) round($h * 0.026 * $bs);
    $markSize = (int) round($h * 0.16 * $ts);
@endphp

<div style="position:absolute; inset:0; background:{{ $image ? $overlay : $bg }};">
    @if ($image)
        <img src="{{ $image }}" alt=""
             style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
        <div style="{{ $anchor['scrim'] }}"></div>
    @endif

    <div style="position:absolute; inset:0; display:flex; flex-direction:column;
                justify-content:{{ $anchor['justify'] }}; align-items:{{ $anchor['align'] }};
                padding:{{ $pad }}px; {{ $shift }}">
        <div style="text-align:{{ $anchor['text_align'] }}; max-width:100%;">
            {{-- Guillemet ouvrant : repère visuel, pas du texte lisible --}}
            <div style="font-family:'{{ $titleFont }}',sans-serif; font-weight:800;
                        font-size:{{ $markSize }}px; line-height:0.7; color:{{ $accent }};
                        margin-bottom:{{ (int) round($h * 0.015) }}px;">&ldquo;</div>

            @if ($quote !== '')
                <p style="margin:0; font-family:'{{ $titleFont }}',sans-serif; font-weight:700;
                          font-size:{{ $quoteSize }}px; line-height:1.22; letter-spacing:-0.01em;
                          color:{{ $text }}; text-wrap:balance;">{{ $quote }}</p>
            @endif

            @if ($author !== '')
                <p style="margin:{{ (int) round($h * 0.035) }}px 0 0; font-family:'{{ $bodyFont }}',sans-serif;
                          font-weight:400; font-size:{{ $authorSize }}px; line-height:1.3;
                          color:{{ $text }}; opacity:0.7;">— {{ $author }}</p>
            @endif
        </div>
    </div>
</div>
