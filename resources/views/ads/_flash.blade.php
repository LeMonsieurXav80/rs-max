{{-- Messages de retour communs aux écrans Publicités. --}}
@php
    $adsFlash = [
        'boost-created' => 'Campagne créée EN PAUSE. Elle ne dépense rien tant qu\'elle n\'est pas lancée.',
        'campaign-activated' => 'Diffusion lancée — la dépense a commencé.',
        'campaign-paused' => 'Diffusion mise en pause.',
        'budget-updated' => 'Budget mis à jour.',
        'adset-updated' => 'Ad set mis à jour.',
        'audience-created' => 'Audience enregistrée.',
        'audience-updated' => 'Audience mise à jour.',
        'audience-deleted' => 'Audience supprimée. Les campagnes en cours gardent leur ciblage.',
    ][session('status')] ?? null;
@endphp

@if($adsFlash)
    <div class="mb-6 rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
        <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </svg>
        <p class="text-sm text-green-700">{{ $adsFlash }}</p>
    </div>
@endif

@if(session('error'))
    <div class="mb-6 rounded-xl bg-red-50 border border-red-200 p-4 text-sm text-red-700">
        {{ session('error') }}
    </div>
@endif

@if($errors->any())
    <div class="mb-6 rounded-xl bg-red-50 border border-red-200 p-4">
        <ul class="text-sm text-red-700 space-y-1">
            @foreach($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
