{{--
    Brique « Texte plein (sans image) ».
    Slots : title, subtitle (optionnel), position (ancre 3×3), offset (%).
    Fond dégradé subtil + filet d'accent. Ancre par défaut `middle-left`
    = rendu historique (bloc à gauche, centré verticalement).
--}}
@php
    $title = trim((string) ($data['title'] ?? ''));
    $subtitle = trim((string) ($data['subtitle'] ?? ''));

    $bg = $theme['background'] ?? '#0f0f1a';
    $text = $theme['text'] ?? '#ffffff';
    $accent = $theme['accent'] ?? '#0083ff';
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    $anchor = \App\Services\Carousel\Anchor::resolve($data['position'] ?? 'middle-left', $bg);
    $shift = \App\Services\Carousel\Anchor::offsetTransform($data['offset'] ?? 0, $h);

    // Échelle typographique du thème : multiplie les fractions ci-dessous.
    $ts = \App\Services\Carousel\Typography::title($theme);
    $bs = \App\Services\Carousel\Typography::body($theme);

    $pad = (int) round($w * 0.10);
    // Titre adaptatif : une punchline courte reste énorme, un texte long se pose.
    $titleSize = (int) round($h * (mb_strlen($title) > 90 ? 0.058 : (mb_strlen($title) > 55 ? 0.072 : 0.088)) * $ts);
    $subSize = (int) round($h * 0.032 * $bs);
@endphp

{{-- Fond : dégradé très léger plutôt qu'aplat, pour éviter l'effet « bloc mort ». --}}
<div style="position:absolute; inset:0;
            background:linear-gradient(160deg, {{ $bg }} 0%, {{ $bg }} 55%, {{ $accent }}22 100%);
            display:flex; flex-direction:column;
            justify-content:{{ $anchor['justify'] }}; align-items:{{ $anchor['align'] }};
            padding:{{ $pad }}px; {{ $shift }}">
    <div style="text-align:{{ $anchor['text_align'] }}; max-width:100%;">
        <span style="display:block; width:{{ (int) round($w * 0.14) }}px; height:{{ (int) round($h * 0.012) }}px;
                     background:{{ $accent }}; border-radius:999px;
                     margin-bottom:{{ (int) round($h * 0.045) }}px;
                     {{ $anchor['horizontal'] === 'center' ? 'margin-left:auto; margin-right:auto;' : '' }}
                     {{ $anchor['horizontal'] === 'right' ? 'margin-left:auto;' : '' }}"></span>

        @if ($title !== '')
            <h1 style="margin:0; font-family:'{{ $titleFont }}',sans-serif; font-weight:800;
                       font-size:{{ $titleSize }}px; line-height:1.02; letter-spacing:-0.02em;
                       color:{{ $text }}; text-wrap:balance;">{{ $title }}</h1>
        @endif
        @if ($subtitle !== '')
            <p style="margin:{{ (int) round($h * 0.035) }}px 0 0; max-width:{{ (int) round($w * 0.88) }}px;
                      font-family:'{{ $bodyFont }}',sans-serif; font-weight:400;
                      font-size:{{ $subSize }}px; line-height:1.4;
                      color:{{ $text }}; opacity:0.82;
                      {{ $anchor['horizontal'] === 'center' ? 'margin-left:auto; margin-right:auto;' : '' }}">{{ $subtitle }}</p>
        @endif
    </div>
</div>
