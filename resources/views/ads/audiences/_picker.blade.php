{{--
    Sélecteur avec recherche chez Meta.

    Aucune saisie libre n'entre dans une audience : les identifiants de ciblage
    (intérêt, lieu, langue, comportement) viennent tous de Graph. Un id fabriqué
    de toutes pièces ne renvoie pas d'erreur — il donne une campagne qui ne
    touche personne.

    Variables : $field (clé de la spec), $type (interest|behavior|geo|locale),
    $label, $help.
--}}
<div>
    <label class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</label>
    @if($help)
        <p class="text-xs text-gray-500 mb-2">{{ $help }}</p>
    @endif

    {{-- Le JSON de la liste : c'est lui qui est réellement posté. --}}
    <input type="hidden" name="spec[{{ $field }}]" :value="json('{{ $field }}')">

    <div class="relative">
        <input type="text"
               x-model="query['{{ $field }}']"
               @input.debounce.400ms="search('{{ $field }}', '{{ $type }}')"
               placeholder="Rechercher…"
               class="block w-full rounded-xl border-gray-200 text-sm focus:border-indigo-500 focus:ring-indigo-500">

        <div x-show="searching['{{ $field }}']" x-cloak
             class="absolute right-3 top-2.5 text-xs text-gray-400">…</div>

        <template x-if="(results['{{ $field }}'] || []).length">
            <div class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto bg-white border border-gray-200 rounded-xl shadow-lg">
                <template x-for="item in results['{{ $field }}']" :key="item.id ?? item.key">
                    <button type="button" @click="pick('{{ $field }}', item)"
                            class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 flex items-center justify-between gap-2">
                        <span>
                            <span x-text="item.name"></span>
                            <span class="block text-xs text-gray-400"
                                  x-text="[item.type, item.region, item.country_code].filter(Boolean).join(' · ')"></span>
                        </span>
                        <span class="text-xs text-gray-400 shrink-0"
                              x-text="item.audience_size ? Number(item.audience_size).toLocaleString('fr-FR') : ''"></span>
                    </button>
                </template>
            </div>
        </template>
    </div>

    <div class="flex flex-wrap gap-2 mt-2">
        <template x-for="(item, i) in (picked['{{ $field }}'] || [])" :key="i">
            <span class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 border border-indigo-100">
                <span x-text="item.name"></span>
                <button type="button" @click="remove('{{ $field }}', i)" class="text-indigo-400 hover:text-indigo-700">×</button>
            </span>
        </template>
    </div>
</div>
