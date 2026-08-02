{{--
    Vue-pont des briques stockées en BASE (« templates » de l'interface).

    Le gabarit n'est pas du Blade : il est rendu par TemplateRenderer, qui fait
    uniquement de la substitution échappée, des conditionnels et des boucles —
    aucune exécution. Le {!! !!} est donc sûr : la sortie du moteur est déjà
    échappée slot par slot et nettoyée des balises interdites.
--}}
{!! app(\App\Services\Carousel\TemplateRenderer::class)->render((string) ($template ?? ''), $data, $theme, $w, $h) !!}
