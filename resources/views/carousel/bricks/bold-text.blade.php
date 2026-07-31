{{--
    Brique « Texte plein (sans image) ».
    Slots : title (large, centré), subtitle (optionnel). Fond uni + accent.
--}}
@php
    $title = trim((string) ($data['title'] ?? ''));
    $subtitle = trim((string) ($data['subtitle'] ?? ''));

    $bg = $theme['background'] ?? '#0f0f1a';
    $text = $theme['text'] ?? '#ffffff';
    $accent = $theme['accent'] ?? '#0083ff';
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    $pad = (int) round($w * 0.10);
    $titleSize = (int) round($h * 0.088);
    $subSize = (int) round($h * 0.032);
@endphp

<div style="position:absolute; inset:0; background:{{ $bg }};
            display:flex; flex-direction:column; align-items:flex-start; justify-content:center;
            padding:{{ $pad }}px;">
    <span style="width:{{ (int) round($w * 0.14) }}px; height:{{ (int) round($h * 0.012) }}px;
                 background:{{ $accent }}; border-radius:999px;
                 margin-bottom:{{ (int) round($h * 0.045) }}px;"></span>

    @if ($title !== '')
        <h1 style="margin:0; font-family:'{{ $titleFont }}',sans-serif; font-weight:800;
                   font-size:{{ $titleSize }}px; line-height:1.02; letter-spacing:-0.02em;
                   color:{{ $text }}; text-wrap:balance;">{{ $title }}</h1>
    @endif
    @if ($subtitle !== '')
        <p style="margin:{{ (int) round($h * 0.035) }}px 0 0; max-width:88%;
                  font-family:'{{ $bodyFont }}',sans-serif; font-weight:400;
                  font-size:{{ $subSize }}px; line-height:1.4;
                  color:{{ $text }}; opacity:0.82;">{{ $subtitle }}</p>
    @endif
</div>
