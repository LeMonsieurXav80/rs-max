{{--
    Brique « Slide numérotée ».
    Slots : number (ex. 01), number_style, title, body (optionnel), image de fond
    (optionnelle), position, offset.
    `number_style` décide de l'habillage du numéro : pastille au-dessus du titre,
    filigrane géant rogné par le cadre, les deux (défaut, rendu historique) ou aucun.
--}}
@php
    $number = trim((string) ($data['number'] ?? ''));
    // Valeur vide ou inconnue => rendu historique (pastille + filigrane).
    $numberStyle = (string) ($data['number_style'] ?? '');
    $numberStyle = in_array($numberStyle, ['both', 'badge', 'watermark', 'none'], true) ? $numberStyle : 'both';
    $showBadge = $number !== '' && in_array($numberStyle, ['both', 'badge'], true);
    $showWatermark = $number !== '' && in_array($numberStyle, ['both', 'watermark'], true);
    $title = trim((string) ($data['title'] ?? ''));
    $body = trim((string) ($data['body'] ?? ''));
    $image = $data['image'] ?? null;

    $bg = \App\Services\Carousel\Palette::background($theme);
    $text = \App\Services\Carousel\Palette::text($theme);
    // Couleur dédiée si réglée, sinon le texte principal atténué (rendu d'origine).
    $textSecondary = \App\Services\Carousel\Palette::textSecondary($theme);
    $fadeSecondary = \App\Services\Carousel\Palette::fade($theme, 'text_secondary', 0.85);
    $accent = \App\Services\Carousel\Palette::accent($theme);
    $overlay = \App\Services\Carousel\Palette::overlay($theme);
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    $anchor = \App\Services\Carousel\Anchor::resolve($data['position'] ?? 'middle-left', $image ? $overlay : $bg);
    $shift = \App\Services\Carousel\Anchor::offsetTransform($data['offset'] ?? 0, $h);

    // Échelle typographique du thème : multiplie les fractions ci-dessous.
    $ts = \App\Services\Carousel\Typography::title($theme);
    $bs = \App\Services\Carousel\Typography::body($theme);

    $pad = (int) round($w * 0.09);
    $titleSize = (int) round($h * (mb_strlen($title) > 60 ? 0.052 : 0.064) * $ts);
    $bodySize = (int) round($h * 0.028 * $bs);
    $badgeSize = (int) round($h * 0.030 * $ts);
@endphp

<div style="position:absolute; inset:0; background:{{ $image ? $overlay : $bg }};">
    @if ($image)
        {{-- Cadre de l'image : la slide entière, ou tout le groupe quand la photo
             se prolonge sur la ou les slides suivantes (Backdrop). --}}
        <div style="{{ \App\Services\Carousel\Backdrop::frame($data, $w, $h) }}">
            <img src="{{ $image }}" alt=""
                 style="display:block; width:100%; height:100%; object-fit:cover;">
        </div>
        <div style="{{ $anchor['scrim'] }}"></div>
    @endif

    {{-- Filigrane : le numéro en très grand, volontairement rogné par le cadre --}}
    @if ($showWatermark)
        <div style="position:absolute; right:{{ (int) round($w * -0.02) }}px; bottom:{{ (int) round($h * -0.06) }}px;
                    font-family:'{{ $titleFont }}',sans-serif; font-weight:800;
                    font-size:{{ (int) round($h * 0.42 * $ts) }}px; line-height:0.8; letter-spacing:-0.04em;
                    color:{{ $accent }}; opacity:0.16;">{{ $number }}</div>
    @endif

    <div style="position:absolute; inset:0; display:flex; flex-direction:column;
                justify-content:{{ $anchor['justify'] }}; align-items:{{ $anchor['align'] }};
                padding:{{ $pad }}px; {{ $shift }}">
        <div style="text-align:{{ $anchor['text_align'] }}; max-width:100%;">
            @if ($showBadge)
                <span style="display:inline-block; margin-bottom:{{ (int) round($h * 0.025) }}px;
                             padding:{{ (int) round($h * 0.008) }}px {{ (int) round($w * 0.030) }}px;
                             border-radius:999px; background:{{ $accent }};
                             font-family:'{{ $titleFont }}',sans-serif; font-weight:700;
                             font-size:{{ $badgeSize }}px; line-height:1.4; color:#ffffff;">{{ $number }}</span>
            @endif

            @if ($title !== '')
                <h2 style="margin:0; font-family:'{{ $titleFont }}',sans-serif; font-weight:800;
                           font-size:{{ $titleSize }}px; line-height:1.08; letter-spacing:-0.015em;
                           color:{{ $text }}; text-wrap:balance;">{{ $title }}</h2>
            @endif

            @if ($body !== '')
                <p style="margin:{{ (int) round($h * 0.028) }}px 0 0; max-width:{{ (int) round($w * 0.82) }}px;
                          font-family:'{{ $bodyFont }}',sans-serif; font-weight:400;
                          font-size:{{ $bodySize }}px; line-height:1.4;
                          color:{{ $textSecondary }}; opacity:{{ $fadeSecondary }};
                          {{ $anchor['horizontal'] === 'center' ? 'margin-left:auto; margin-right:auto;' : '' }}">{{ $body }}</p>
            @endif
        </div>
    </div>
</div>
