<?php

namespace App\Http\Controllers;

use App\Models\VaultSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class VaultSourceController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isManager(), 403);

        return view('vault-sources.index', ['sources' => VaultSource::with('socialAccounts.platform')->orderBy('name')->get()]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->isManager(), 403);

        return view('vault-sources.form', ['source' => new VaultSource(['format' => 'notes', 'is_active' => true]), 'accounts' => $this->accounts($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isManager(), 403);
        $source = VaultSource::create($this->validated($request));
        $source->socialAccounts()->sync($this->accountIds($request));

        return redirect()->route('vault-sources.index')->with('status', 'Source du Vault créée.');
    }

    public function edit(Request $request, VaultSource $vaultSource): View
    {
        abort_unless($request->user()->isManager(), 403);

        return view('vault-sources.form', ['source' => $vaultSource, 'accounts' => $this->accounts($request)]);
    }

    public function update(Request $request, VaultSource $vaultSource): RedirectResponse
    {
        abort_unless($request->user()->isManager(), 403);
        $vaultSource->update($this->validated($request));
        $vaultSource->socialAccounts()->sync($this->accountIds($request));

        return redirect()->route('vault-sources.index')->with('status', 'Source du Vault mise à jour.');
    }

    public function destroy(Request $request, VaultSource $vaultSource): RedirectResponse
    {
        abort_unless($request->user()->isManager(), 403);
        $vaultSource->delete();

        return redirect()->route('vault-sources.index')->with('status', 'Source du Vault supprimée.');
    }

    private function accounts(Request $request)
    {
        return $request->user()->visibleSocialAccounts()->with('platform')->orderBy('name')->get();
    }

    private function accountIds(Request $request): array
    {
        $ids = array_map('intval', (array) $request->input('accounts', []));

        return $this->accounts($request)->whereIn('id', $ids)->pluck('id')->all();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'corpus' => ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{0,30}$/'],
            'format' => ['required', Rule::in(array_keys(VaultSource::FORMATS))],
            'folders' => 'required|string|max:6000',
            'excluded_folders' => 'nullable|string|max:6000',
            'brands' => 'nullable|string|max:3000',
            'accounts' => 'nullable|array',
            'accounts.*' => 'integer',
        ]);
        $lines = fn (?string $text) => collect(preg_split('/\R/', (string) $text))->map(fn ($l) => trim($l, " \t/"))->filter()->unique()->values()->all();
        $folders = $lines($data['folders']);
        $refused = array_values(array_filter($folders, fn ($folder) => VaultSource::forbidden($folder)));
        if ($refused !== [] || $folders === [] || count($folders) > 30) {
            throw ValidationException::withMessages(['folders' => $refused !== []
                ? 'Dossiers refusés (zone sensible) : '.implode(', ', $refused).'.'
                : 'Indiquez entre 1 et 30 dossiers, un par ligne.']);
        }

        return ['name' => $data['name'], 'corpus' => $data['corpus'], 'format' => $data['format'], 'folders' => $folders,
            'excluded_folders' => $lines($data['excluded_folders'] ?? null), 'brands' => $lines($data['brands'] ?? null),
            'is_active' => $request->boolean('is_active')];
    }
}
