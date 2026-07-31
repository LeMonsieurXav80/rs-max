{{--
    Brique « Photo + titre bas-gauche ».
    Slots : image (data-URI/URL, plein cadre), title, subtitle (optionnel).
    Fill absolu du slide w×h ; tailles typographiques proportionnelles à $h.
--}}
@php
    $title = trim((string) ($data['title'] ?? ''));
    $subtitle = trim((string) ($data['subtitle'] ?? ''));
    $image = $data['image'] ?? null;

    $text = $theme['text'] ?? '#ffffff';
    $overlay = $theme['overlay'] ?? '#000000';
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    $pad = (int) round($w * 0.07);
    $titleSize = (int) round($h * 0.062);
    $subSize = (int) round($h * 0.030);
@endphp

<div style="position:absolute; inset:0; background:{{ $overlay }};">
    @if ($image)
        <img src="{{ $image }}" alt=""
             style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
    @endif

    {{-- Dégradé sombre du bas pour lisibilité du texte --}}
    <div style="position:absolute; left:0; right:0; bottom:0; height:60%;
                background:linear-gradient(to top,
                    {{ $overlay }} 0%,
                    {{ $overlay }}cc 32%,
                    transparent 100%);"></div>

    <div style="position:absolute; left:{{ $pad }}px; right:{{ $pad }}px; bottom:{{ $pad }}px;">
        @if ($title !== '')
            <h1 style="margin:0; font-family:'{{ $titleFont }}',sans-serif; font-weight:800;
                       font-size:{{ $titleSize }}px; line-height:1.08; letter-spacing:-0.01em;
                       color:{{ $text }}; text-wrap:balance;">{{ $title }}</h1>
        @endif
        @if ($subtitle !== '')
            <p style="margin:{{ (int) round($h * 0.022) }}px 0 0; font-family:'{{ $bodyFont }}',sans-serif;
                      font-weight:400; font-size:{{ $subSize }}px; line-height:1.35;
                      color:{{ $text }}; opacity:0.88;">{{ $subtitle }}</p>
        @endif
    </div>
</div>
