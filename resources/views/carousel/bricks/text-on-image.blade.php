{{--
    Brique « Texte sur image de fond ».
    Slots : image (fond assombri), title (large, centré), body (optionnel).
--}}
@php
    $title = trim((string) ($data['title'] ?? ''));
    $body = trim((string) ($data['body'] ?? ''));
    $image = $data['image'] ?? null;

    $text = $theme['text'] ?? '#ffffff';
    $overlay = $theme['overlay'] ?? '#000000';
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    $pad = (int) round($w * 0.10);
    $titleSize = (int) round($h * 0.072);
    $bodySize = (int) round($h * 0.030);
@endphp

<div style="position:absolute; inset:0; background:{{ $overlay }};">
    @if ($image)
        <img src="{{ $image }}" alt=""
             style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
    @endif

    {{-- Voile sombre uniforme pour la lisibilité --}}
    <div style="position:absolute; inset:0; background:{{ $overlay }}; opacity:0.55;"></div>

    <div style="position:absolute; inset:0; display:flex; flex-direction:column;
                align-items:center; justify-content:center; text-align:center;
                padding:{{ $pad }}px;">
        @if ($title !== '')
            <h1 style="margin:0; font-family:'{{ $titleFont }}',sans-serif; font-weight:800;
                       font-size:{{ $titleSize }}px; line-height:1.1; letter-spacing:-0.01em;
                       color:{{ $text }}; text-wrap:balance;">{{ $title }}</h1>
        @endif
        @if ($body !== '')
            <p style="margin:{{ (int) round($h * 0.03) }}px 0 0; max-width:82%;
                      font-family:'{{ $bodyFont }}',sans-serif; font-weight:400;
                      font-size:{{ $bodySize }}px; line-height:1.4;
                      color:{{ $text }}; opacity:0.9;">{{ $body }}</p>
        @endif
    </div>
</div>
