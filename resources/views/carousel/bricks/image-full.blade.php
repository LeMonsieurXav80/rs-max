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
        {{-- Cadre de l'image : la slide entière, ou tout le groupe quand la photo
             se prolonge sur la ou les slides suivantes (Backdrop). --}}
        <div style="{{ \App\Services\Carousel\Backdrop::frame($data, $w, $h) }}">
            <img src="{{ $image }}" alt=""
                 style="display:block; width:100%; height:100%; object-fit:cover;">
        </div>
    @endif
</div>
