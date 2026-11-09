@php($statusLabels = ['pending'=>'En attente','running'=>'En cours','completed'=>'Terminé','confirmed'=>'Confirmé','unchanged'=>'Déjà présent','failed'=>'Échec','uncertain'=>'À contrôler','cancelled'=>'Annulé'])
<div class="overflow-x-auto">
<table class="w-full text-sm text-left">
    <thead class="bg-gray-50 text-xs text-gray-500"><tr><th class="p-3">Date · Lisbonne</th><th class="p-3">Compte</th><th class="p-3">Action / canal</th><th class="p-3">Résultat</th><th class="p-3">Détails</th></tr></thead>
    <tbody class="divide-y divide-gray-100">
    @forelse($logRows as $log)
        <tr class="align-top">
            <td class="p-3 text-xs whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($log['at'])->timezone('Europe/Lisbon')->format('d/m H:i:s') }}<div class="text-gray-400 mt-1">#{{ $log['id'] }}@if($log['task_id']) · tâche {{ $log['task_id'] }}@endif</div></td>
            <td class="p-3">{{ $log['account'] }}<div class="text-xs text-gray-400">{{ $log['platform'] }}</div></td>
            <td class="p-3"><span class="font-medium">{{ $log['action'] }}</span>@if($log['dry_run'])<div class="text-xs text-indigo-600">Simulation</div>@endif<div class="text-xs text-gray-500 mt-1">{{ $log['source'] === 'browser' ? 'Companion · NAS' : ($log['source'] === 'extension' ? 'Companion · local' : $log['source']) }}</div></td>
            <td class="p-3"><span class="inline-flex rounded px-2 py-1 text-xs {{ in_array($log['status'], ['failed','uncertain','cancelled']) ? 'bg-amber-50 text-amber-800' : (in_array($log['status'], ['confirmed','unchanged']) ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-700') }}">{{ $statusLabels[$log['status']] }}</span>@if($log['duration_ms'] !== null)<div class="text-xs text-gray-400 mt-1">{{ round($log['duration_ms'] / 1000, 1) }} s</div>@endif</td>
            <td class="p-3 min-w-56 max-w-lg break-words">
                @if($log['target_url'])<a href="{{ $log['target_url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs text-indigo-600 underline">Voir la cible</a>@endif
                @if($log['error'])<p class="text-xs text-red-700 mt-1">{{ $log['error'] }}</p>@endif
                <details class="mt-2 text-xs"><summary class="cursor-pointer text-gray-600">Texte, preuve et {{ count($log['steps']) }} étape(s)</summary>
                    @if($log['run_id'])<p class="mt-2 text-gray-500">Companion {{ $log['version'] }} · {{ $log['recipe'] }} · lot {{ $log['run_id'] }}</p>@endif
                    @if($log['target_author'])<p class="mt-2">Cible : {{ $log['target_author'] }}</p>@endif
                    @if($log['text'])<p class="mt-2 whitespace-pre-wrap">{{ $log['text'] }}</p>@endif
                    @if($log['proof'])<pre class="mt-2 whitespace-pre-wrap break-all text-gray-600">{{ json_encode($log['proof'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>@endif
                    @foreach($log['steps'] as $step)<div class="mt-2 border-l-2 border-gray-200 pl-2"><p class="font-medium">{{ $step['stage'] }}@if(isset($step['elapsed_ms'])) · +{{ round($step['elapsed_ms']/1000, 1) }} s @endif</p>@if($step['details'])<pre class="whitespace-pre-wrap break-all text-gray-500">{{ json_encode($step['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>@endif</div>@endforeach
                </details>
            </td>
        </tr>
    @empty<tr><td colspan="5" class="p-5 text-gray-500">Aucun événement sur les sept derniers jours.</td></tr>@endforelse
    </tbody>
</table>
</div>
