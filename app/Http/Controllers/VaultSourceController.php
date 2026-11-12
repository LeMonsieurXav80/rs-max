<?php

namespace App\Http\Controllers;

use App\Models\VaultSource;
use App\Services\Agent\VaultSourceInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        [$attributes, $accounts] = VaultSourceInput::validate($request, $request->user());
        $source = VaultSource::create($attributes + ['is_active' => $request->boolean('is_active')]);
        $source->socialAccounts()->sync($accounts ?? []);

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
        [$attributes, $accounts] = VaultSourceInput::validate($request, $request->user());
        $vaultSource->update($attributes + ['excluded_folders' => [], 'brands' => [], 'is_active' => $request->boolean('is_active')]);
        $vaultSource->socialAccounts()->sync($accounts ?? []);

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
}
