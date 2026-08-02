{{--
    Assembleur de carrousel — rend une BANDE CONTINUE de slides côte à côte
    HORIZONTALEMENT (largeur N×w, hauteur h), dans le sens du swipe.

    Sert l'aperçu (affiché tel quel dans l'iframe du Studio) et posera les
    fondations de la continuité d'image inter-slides (Phase 3) : une image posée à
    cheval sur une couture verticale sera coupée pile à la frontière quand on swipe.

    Variables attendues :
      $w, $h       int    dimensions d'un slide (px de design, avant deviceScaleFactor)
      $fontFaces   string bloc <style> de @font-face (TTF embarqués en base64)
      $slides      array  [ ['view'=>..., 'data'=>[...], 'theme'=>[...]], ... ]
                          les slots image de $data sont DÉJÀ résolus en data-URI/URL.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #000; }
    [data-carousel-root] {
        display: flex;
        flex-direction: row;
        width: {{ $w * count($slides) }}px;
        height: {{ $h }}px;
    }
    [data-carousel-slide] {
        flex: 0 0 auto;
        width: {{ $w }}px;
        height: {{ $h }}px;
        overflow: hidden;
        position: relative;
    }
    img { display: block; }
</style>
{!! $fontFaces !!}
</head>
<body>
<div data-carousel-root>
    @foreach ($slides as $i => $slide)
        <section data-carousel-slide data-index="{{ $i }}">
            @include($slide['view'], [
                'w' => $w,
                'h' => $h,
                'data' => $slide['data'] ?? [],
                'theme' => $slide['theme'] ?? [],
                // Gabarit des briques stockées en base (null pour les briques en code).
                'template' => $slide['template'] ?? null,
            ])
        </section>
    @endforeach
</div>
</body>
</html>
