{{--
    Assembleur de carrousel — rend une BANDE CONTINUE de slides empilés
    verticalement (largeur w, hauteur N×h). Cette bande est ensuite rasterisée
    en une seule image puis tranchée en N slides de w×h par le CarouselRenderService.

    Rendre la bande d'un seul tenant (plutôt qu'un screenshot par slide) pose les
    fondations de la continuité d'image inter-slides (Phase 3) : une image posée à
    cheval sur une couture sera coupée pile à la frontière.

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
    [data-carousel-root] { width: {{ $w }}px; }
    [data-carousel-slide] {
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
            ])
        </section>
    @endforeach
</div>
</body>
</html>
