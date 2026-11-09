@extends('layouts.app')
@section('title', 'Journal — '.$definition['name'])
@section('actions')
<div class="flex gap-2"><a href="{{ route('agent.platform', $platform) }}" class="px-4 py-2 rounded-xl bg-white border text-sm">Retour au réseau</a><a href="{{ request()->fullUrl() }}" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm">Actualiser</a></div>
@endsection
@section('content')
<div class="space-y-4">
    <p class="text-sm text-gray-500">Lectures, gestes et diagnostics remontés à RS-Max. Suppression automatique après sept jours. Les compteurs de cadence et la mémoire anti-doublon restent séparés.</p>
    <form method="GET" class="bg-white rounded-2xl border p-4 flex flex-wrap gap-3 text-sm">
        <select name="account_id" class="rounded-lg border-gray-300"><option value="">Tous les comptes de ce réseau</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(request('account_id') == $account->id)>{{ $account->name }}</option>@endforeach</select>
        <select name="status" class="rounded-lg border-gray-300"><option value="">Tous les résultats</option>@foreach(['pending'=>'En attente','running'=>'En cours','completed'=>'Terminé','confirmed'=>'Confirmé','unchanged'=>'Déjà présent','failed'=>'Échec','uncertain'=>'À contrôler','cancelled'=>'Annulé'] as $value=>$label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select>
        <input name="action" value="{{ request('action') }}" maxlength="100" placeholder="Action, ex. x.like" class="rounded-lg border-gray-300 text-sm">
        <select name="source" class="rounded-lg border-gray-300"><option value="">Tous les canaux</option>@foreach(['browser'=>'Companion · NAS','extension'=>'Companion · local','api'=>'API','agent'=>'Agent'] as $value=>$label)<option value="{{ $value }}" @selected(request('source') === $value)>{{ $label }}</option>@endforeach</select>
        <button class="px-4 py-2 rounded-lg bg-indigo-600 text-white">Filtrer</button><a href="{{ route('agent.logs', $platform) }}" class="px-3 py-2 text-gray-500">Réinitialiser</a>
    </form>
    <div class="bg-white border rounded-2xl overflow-hidden">@include('agent._logs')</div>
    {{ $logs->links() }}
</div>
@endsection
