{{--
    Brique « Texte sur image de fond ».
    Slots : image (fond assombri), title, body (optionnel), position (ancre 3×3), offset (%).
    Ancre par défaut `middle-center` = rendu historique centré.
--}}
@php
    $title = trim((string) ($data['title'] ?? ''));
    $body = trim((string) ($data['body'] ?? ''));
    $image = $data['image'] ?? null;

    $text = $theme['text'] ?? '#ffffff';
    $overlay = $theme['overlay'] ?? '#000000';
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    $anchor = \App\Services\Carousel\Anchor::resolve($data['position'] ?? 'middle-center', $overlay);
    $shift = \App\Services\Carousel\Anchor::offsetTransform($data['offset'] ?? 0, $h);

    // Échelle typographique du thème : multiplie les fractions ci-dessous.
    $ts = \App\Services\Carousel\Typography::title($theme);
    $bs = \App\Services\Carousel\Typography::body($theme);

    $pad = (int) round($w * 0.10);
    // Titre adaptatif : on réduit au-delà de ~60 puis ~90 caractères.
    $titleSize = (int) round($h * (mb_strlen($title) > 90 ? 0.052 : (mb_strlen($title) > 60 ? 0.062 : 0.072)) * $ts);
    $bodySize = (int) round($h * 0.030 * $bs);
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
                           font-size:{{ $titleSize }}px; line-height:1.1; letter-spacing:-0.01em;
                           color:{{ $text }}; text-wrap:balance;">{{ $title }}</h1>
            @endif
            @if ($body !== '')
                {{-- Largeur de ligne bornée : au-delà, la lecture décroche. --}}
                <p style="margin:{{ (int) round($h * 0.03) }}px 0 0; max-width:{{ (int) round($w * 0.78) }}px;
                          font-family:'{{ $bodyFont }}',sans-serif; font-weight:400;
                          font-size:{{ $bodySize }}px; line-height:1.4;
                          color:{{ $text }}; opacity:0.9;
                          {{ $anchor['horizontal'] === 'center' ? 'margin-left:auto; margin-right:auto;' : '' }}">{{ $body }}</p>
            @endif
        </div>
    </div>
</div>
