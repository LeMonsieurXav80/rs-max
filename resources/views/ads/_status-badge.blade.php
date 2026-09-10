{{--
    Statut Meta.

    `effective_status` prime sur `status` partout où il est disponible : une
    campagne ACTIVE dont le compte a atteint son plafond ne diffuse pas, et seul
    le statut effectif le dit.
--}}
@php
    $adsStatus = $status ?? 'UNKNOWN';
    $adsTone = match ($adsStatus) {
        'ACTIVE' => 'bg-green-50 text-green-700 border-green-200',
        'PAUSED', 'ADSET_PAUSED', 'CAMPAIGN_PAUSED' => 'bg-amber-50 text-amber-700 border-amber-200',
        'FAILED', 'DISAPPROVED', 'WITH_ISSUES' => 'bg-red-50 text-red-700 border-red-200',
        default => 'bg-gray-50 text-gray-600 border-gray-200',
    };
    $adsLabel = match ($adsStatus) {
        'ACTIVE' => 'diffuse',
        'PAUSED' => 'en pause',
        'ADSET_PAUSED' => 'ad set en pause',
        'CAMPAIGN_PAUSED' => 'campagne en pause',
        'FAILED' => 'échec',
        'IN_PROCESS' => 'en cours de validation',
        'PENDING_REVIEW' => 'en revue',
        'DISAPPROVED' => 'refusée',
        'WITH_ISSUES' => 'problème',
        'COMPLETED' => 'terminée',
        default => mb_strtolower($adsStatus),
    };
@endphp

<span class="inline-flex items-center text-[11px] px-2 py-0.5 rounded border {{ $adsTone }}">{{ $adsLabel }}</span>
