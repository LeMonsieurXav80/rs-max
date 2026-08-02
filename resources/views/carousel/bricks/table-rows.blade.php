{{--
    Brique « Tableau ».
    Slots : title (optionnel), rows (une ligne = « libellé | valeur »), note (optionnelle).
    Libellé à gauche, valeur à droite en accent, filets fins entre les lignes.
--}}
@php
    $title = trim((string) ($data['title'] ?? ''));
    $note = trim((string) ($data['note'] ?? ''));
    $rows = \App\Services\Carousel\Lines::parse($data['rows'] ?? null, 8);

    $bg = $theme['background'] ?? '#0f0f1a';
    $text = $theme['text'] ?? '#ffffff';
    $accent = $theme['accent'] ?? '#0083ff';
    $titleFont = $theme['title_font'] ?? 'Montserrat';
    $bodyFont = $theme['body_font'] ?? 'Poppins';

    $pad = (int) round($w * 0.09);
    $titleSize = (int) round($h * 0.046);
    // Au-delà de 5 lignes on resserre pour que le tableau tienne dans le cadre.
    $rowSize = (int) round($h * (count($rows) > 6 ? 0.026 : (count($rows) > 4 ? 0.030 : 0.034)));
    $rowPad = (int) round($h * (count($rows) > 6 ? 0.016 : 0.022));
@endphp

<div style="position:absolute; inset:0;
            background:linear-gradient(160deg, {{ $bg }} 0%, {{ $bg }} 60%, {{ $accent }}1f 100%);
            display:flex; flex-direction:column; justify-content:center;
            padding:{{ $pad }}px;">

    @if ($title !== '')
        <h2 style="margin:0 0 {{ (int) round($h * 0.035) }}px; font-family:'{{ $titleFont }}',sans-serif;
                   font-weight:800; font-size:{{ $titleSize }}px; line-height:1.12;
                   letter-spacing:-0.01em; color:{{ $text }}; text-wrap:balance;">{{ $title }}</h2>
    @endif

    <div>
        @foreach ($rows as $i => [$label, $value])
            <div style="display:flex; align-items:baseline; justify-content:space-between; gap:{{ (int) round($w * 0.05) }}px;
                        padding:{{ $rowPad }}px 0;
                        {{ $i > 0 ? 'border-top:1px solid '.$text.'1f;' : '' }}">
                <span style="font-family:'{{ $bodyFont }}',sans-serif; font-weight:400;
                             font-size:{{ $rowSize }}px; line-height:1.3;
                             color:{{ $text }}; opacity:0.85;">{{ $label }}</span>
                <span style="flex:0 0 auto; font-family:'{{ $titleFont }}',sans-serif; font-weight:700;
                             font-size:{{ $rowSize }}px; line-height:1.3;
                             color:{{ $accent }}; white-space:nowrap;">{{ $value }}</span>
            </div>
        @endforeach
    </div>

    @if ($note !== '')
        <p style="margin:{{ (int) round($h * 0.03) }}px 0 0; font-family:'{{ $bodyFont }}',sans-serif;
                  font-weight:400; font-size:{{ (int) round($h * 0.021) }}px; line-height:1.35;
                  color:{{ $text }}; opacity:0.55;">{{ $note }}</p>
    @endif
</div>
