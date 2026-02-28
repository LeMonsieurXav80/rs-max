@props(['status'])

@php
    $classes = match($status) {
        'draft' => 'bg-gray-100 text-gray-700',
        'scheduled' => 'bg-blue-100 text-blue-700',
        'publishing' => 'bg-yellow-100 text-yellow-700',
        'published' => 'bg-green-100 text-green-700',
        'partial' => 'bg-orange-100 text-orange-700',
        'failed' => 'bg-red-100 text-red-700',
        default => 'bg-gray-100 text-gray-700',
    };

    $labels = [
        'draft' => 'Brouillon',
        'scheduled' => 'Programmé',
        'publishing' => 'En cours',
        'published' => 'Publié',
        'partial' => 'Partiel',
        'failed' => 'Erreur',
    ];

    $label = $labels[$status] ?? ucfirst($status);
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {$classes}"]) }}>
    {{ $label }}
</span>
