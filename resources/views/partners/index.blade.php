@extends('layouts.app')

@section('title', 'Partenaires')

@section('actions')
    <a href="{{ route('partners.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
        </svg>
        Nouveau partenaire
    </a>
@endsection

@section('content')

    @php
        $flash = [
            'partner-created' => 'Partenaire créé avec succès.',
            'partner-updated' => 'Partenaire mis à jour.',
            'partner-deleted' => 'Partenaire supprimé.',
        ][session('status')] ?? null;
    @endphp

    @if($flash)
        <div class="mb-6" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)">
            <div class="rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <p class="text-sm text-green-700">{{ $flash }}</p>
            </div>
        </div>
    @endif

    @if($partners->isEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-16 text-center">
            <div class="w-16 h-16 rounded-2xl bg-gray-50 flex items-center justify-center mx-auto mb-6">
                <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900 mb-2">Aucun partenaire</h3>
            <p class="text-sm text-gray-500 mb-8">
                Les partenaires se taguent sur les photos et se reportent automatiquement sur les publications,
                pour produire des comptes rendus.
            </p>
            <a href="{{ route('partners.create') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Créer un partenaire
            </a>
        </div>
    @else
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">Partenaire</th>
                            <th class="px-6 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500">Contact</th>
                            <th class="px-6 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-gray-500">Photos</th>
                            <th class="px-6 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-gray-500">Publications</th>
                            <th class="px-6 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-gray-500">Fils</th>
                            <th class="px-6 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($partners as $partner)
                            <tr class="hover:bg-gray-50/60 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color: {{ $partner->color }}"></span>
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold text-gray-900 truncate">{{ $partner->name }}</div>
                                            <div class="flex items-center gap-2 mt-0.5">
                                                @if(! $partner->is_active)
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-red-50 text-red-600">Inactif</span>
                                                @endif
                                                @if($partner->origin !== 'manual')
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-500"
                                                          title="Fiche créée automatiquement — à vérifier">
                                                        {{ $partner->origin === 'vision' ? 'détecté par IA' : 'importé' }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    @if($partner->contact_name || $partner->contact_email)
                                        <div class="truncate">{{ $partner->contact_name }}</div>
                                        @if($partner->contact_email)
                                            <a href="mailto:{{ $partner->contact_email }}" class="text-xs text-indigo-600 hover:underline">{{ $partner->contact_email }}</a>
                                        @endif
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-gray-600 tabular-nums">{{ $partner->media_files_count }}</td>
                                <td class="px-6 py-4 text-right">
                                    <a href="{{ route('partners.posts', $partner) }}"
                                       class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-sm font-medium text-indigo-600 hover:bg-indigo-50 transition-colors tabular-nums">
                                        {{ $partner->posts_count }}
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                        </svg>
                                    </a>
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-gray-600 tabular-nums">{{ $partner->threads_count }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('partners.edit', $partner) }}"
                                           class="p-2 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" title="Modifier">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                            </svg>
                                        </a>
                                        <form method="POST" action="{{ route('partners.destroy', $partner) }}"
                                              onsubmit="return confirm('Supprimer « {{ $partner->name }} » ? Les photos et publications perdront ce tag.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

@endsection
