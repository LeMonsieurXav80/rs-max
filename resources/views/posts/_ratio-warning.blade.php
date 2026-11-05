{{-- Carrousel Instagram de ratios différents : le serveur refusera de le planifier
     (cf MediaRatioService). Attend mediaLibraryData() et mediaItems côté x-data parent. --}}
<div x-show="instagramSelected && ratioConflict()" x-cloak
     class="mt-3 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
    <svg class="w-5 h-5 flex-shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008v.008H12v-.008ZM10.34 3.94 2.77 17.06c-.77 1.33.19 3 1.73 3h15c1.54 0 2.5-1.67 1.73-3L13.66 3.94c-.77-1.33-2.69-1.33-3.46 0Z" />
    </svg>
    <span>
        <strong>Ratios différents :</strong> <span x-text="selectedRatios().join(', ')"></span>.
        Instagram recadrerait tout le carrousel sur la première image, cette publication ne pourra donc pas partir.
    </span>
</div>
