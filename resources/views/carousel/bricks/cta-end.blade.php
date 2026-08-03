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

    $bg = \App\Services\Carousel\Palette::background($theme);
    $text = \App\Services\Carousel\Palette::text($theme);
    // Couleur dédiée si réglée, sinon le texte principal atténué (rendu d'origine).
    $textSecondary = \App\Services\Carousel\Palette::textSecondary($theme);
    $fadeSecondary = \App\Services\Carousel\Palette::fade($theme, 'text_secondary', 0.85);
    $accent = \App\Services\Carousel\Palette::accent($theme);
    // Le dégradé de fond part vers la seconde couleur de marque quand elle existe.
    $accentAlt = \App\Services\Carousel\Palette::accentSecondary($theme);
    $overlay = \App\Services\Carousel\Palette::overlay($theme);
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    $anchor = \App\Services\Carousel\Anchor::resolve($data['position'] ?? 'middle-center', $image ? $overlay : $bg);
    $shift = \App\Services\Carousel\Anchor::offsetTransform($data['offset'] ?? 0, $h);

    // Échelle typographique du thème : multiplie les fractions ci-dessous.
    $ts = \App\Services\Carousel\Typography::title($theme);
    $bs = \App\Services\Carousel\Typography::body($theme);

    $pad = (int) round($w * 0.10);
    $titleSize = (int) round($h * (mb_strlen($title) > 60 ? 0.058 : 0.072) * $ts);
    $subSize = (int) round($h * 0.030 * $bs);
    // La pastille est composée en police de titre, elle suit donc l'échelle des titres.
    $handleSize = (int) round($h * 0.030 * $ts);
@endphp

<div style="position:absolute; inset:0;
            background:{{ $image ? $overlay : 'linear-gradient(160deg, '.$bg.' 0%, '.$bg.' 55%, '.$accentAlt.'2b 100%)' }};">
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
                          color:{{ $textSecondary }}; opacity:{{ $fadeSecondary }};
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
