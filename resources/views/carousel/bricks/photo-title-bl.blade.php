{{--
    Brique « Photo + titre positionnable ».
    Slots : image (plein cadre), title, subtitle, position (ancre 3×3), offset (%).
    L'ancre par défaut `bottom-left` reproduit le rendu historique (overlay).
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

    $anchor = \App\Services\Carousel\Anchor::resolve($data['position'] ?? 'bottom-left', $overlay);
    $shift = \App\Services\Carousel\Anchor::offsetTransform($data['offset'] ?? 0, $h);

    // Échelle typographique du thème : multiplie les fractions ci-dessous.
    $ts = \App\Services\Carousel\Typography::title($theme);
    $bs = \App\Services\Carousel\Typography::body($theme);

    $pad = (int) round($w * 0.07);
    // Titre adaptatif : au-delà de ~60 caractères on réduit pour éviter le débordement.
    $titleSize = (int) round($h * (mb_strlen($title) > 90 ? 0.046 : (mb_strlen($title) > 60 ? 0.053 : 0.062)) * $ts);
    $subSize = (int) round($h * 0.030 * $bs);
@endphp

<div style="position:absolute; inset:0; background:{{ $overlay }};">
    @if ($image)
        <img src="{{ $image }}" alt=""
             style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
    @endif

    {{-- Voile de lisibilité, orienté selon l'ancre verticale --}}
    <div style="{{ $anchor['scrim'] }}"></div>

    <div style="position:absolute; inset:0; display:flex; flex-direction:column;
                justify-content:{{ $anchor['justify'] }}; align-items:{{ $anchor['align'] }};
                padding:{{ $pad }}px; {{ $shift }}">
        <div style="text-align:{{ $anchor['text_align'] }}; max-width:100%;">
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
</div>
