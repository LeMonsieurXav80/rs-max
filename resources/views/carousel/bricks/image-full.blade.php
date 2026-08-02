{{--
    Brique « Image seule ».
    Slot unique : image (data-URI/URL), affichée plein cadre en object-fit:cover.
    AUCUN texte, AUCUN dégradé, AUCUN overlay — sert à insérer une image existante
    telle quelle comme slide du carrousel.
--}}
@php
    $image = $data['image'] ?? null;
    $background = $theme['background'] ?? '#000000';
@endphp

<div style="position:absolute; inset:0; background:{{ $background }};">
    @if ($image)
        <img src="{{ $image }}" alt=""
             style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
    @endif
</div>
