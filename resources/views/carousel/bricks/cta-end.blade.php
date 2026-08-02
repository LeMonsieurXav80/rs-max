{{--
    Brique « Slide de fin (appel à l'action) ».
    Slots : title, subtitle (optionnel), handle (optionnel), image de fond (optionnelle),
    position, offset. Dernière slide type « abonne-toi / enregistre ce post ».
--}}
@php
    $title = trim((string) ($data['title'] ?? ''));
    $subtitle = trim((string) ($data['subtitle'] ?? ''));
    $handle = trim((string) ($data['handle'] ?? ''));
    $image = $data['image'] ?? null;

    $bg = $theme['background'] ?? '#0f0f1a';
    $text = $theme['text'] ?? '#ffffff';
    $accent = $theme['accent'] ?? '#0083ff';
    $overlay = $theme['overlay'] ?? '#000000';
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    $anchor = \App\Services\Carousel\Anchor::resolve($data['position'] ?? 'middle-center', $image ? $overlay : $bg);
    $shift = \App\Services\Carousel\Anchor::offsetTransform($data['offset'] ?? 0, $h);

    $pad = (int) round($w * 0.10);
    $titleSize = (int) round($h * (mb_strlen($title) > 60 ? 0.058 : 0.072));
    $subSize = (int) round($h * 0.030);
    $handleSize = (int) round($h * 0.030);
@endphp

<div style="position:absolute; inset:0;
            background:{{ $image ? $overlay : 'linear-gradient(160deg, '.$bg.' 0%, '.$bg.' 55%, '.$accent.'2b 100%)' }};">
    @if ($image)
        <img src="{{ $image }}" alt=""
             style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
        <div style="{{ $anchor['scrim'] }}"></div>
    @endif

    <div style="position:absolute; inset:0; display:flex; flex-direction:column;
                justify-content:{{ $anchor['justify'] }}; align-items:{{ $anchor['align'] }};
                padding:{{ $pad }}px; {{ $shift }}">
        <div style="text-align:{{ $anchor['text_align'] }}; max-width:100%;">
            @if ($title !== '')
                <h2 style="margin:0; font-family:'{{ $titleFont }}',sans-serif; font-weight:800;
                           font-size:{{ $titleSize }}px; line-height:1.06; letter-spacing:-0.02em;
                           color:{{ $text }}; text-wrap:balance;">{{ $title }}</h2>
            @endif

            @if ($subtitle !== '')
                <p style="margin:{{ (int) round($h * 0.028) }}px 0 0; max-width:{{ (int) round($w * 0.80) }}px;
                          font-family:'{{ $bodyFont }}',sans-serif; font-weight:400;
                          font-size:{{ $subSize }}px; line-height:1.4;
                          color:{{ $text }}; opacity:0.85;
                          {{ $anchor['horizontal'] === 'center' ? 'margin-left:auto; margin-right:auto;' : '' }}">{{ $subtitle }}</p>
            @endif

            @if ($handle !== '')
                <div style="margin-top:{{ (int) round($h * 0.045) }}px;">
                    <span style="display:inline-block; padding:{{ (int) round($h * 0.014) }}px {{ (int) round($w * 0.045) }}px;
                                 border-radius:999px; background:{{ $accent }};
                                 font-family:'{{ $titleFont }}',sans-serif; font-weight:700;
                                 font-size:{{ $handleSize }}px; line-height:1.4;
                                 color:#ffffff;">{{ $handle }}</span>
                </div>
            @endif
        </div>
    </div>
</div>
