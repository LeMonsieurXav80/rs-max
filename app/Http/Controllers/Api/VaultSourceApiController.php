<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VaultSource;
use App\Services\Agent\VaultSourceInput;
use App\Services\Agent\XReplyVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Sources du Vault par API, rôle manager. Écritures en aperçu tant que dry_run n’est pas false (convention du dépôt). */
class VaultSourceApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isManager(), 403);

        return response()->json(['sources' => VaultSource::with('socialAccounts.platform')->orderBy('name')->get()->map(fn ($s) => $this->format($s))]);
    }

    public function show(Request $request, VaultSource $vaultSource): JsonResponse
    {
        abort_unless($request->user()->isManager(), 403);

        return response()->json(['source' => $this->format($vaultSource->load('socialAccounts.platform'))]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isManager(), 403);
        [$attributes, $accounts] = VaultSourceInput::validate($request, $request->user());
        $source = new VaultSource($attributes + ['is_active' => true, 'excluded_folders' => [], 'brands' => []]);
        if ($request->boolean('dry_run', true)) {
            return response()->json(['dry_run' => true, 'source' => $this->format($source, $accounts ?? [])]);
        }
        DB::transaction(function () use ($source, $accounts) {
            $source->save();
            $source->socialAccounts()->sync($accounts ?? []);
        });

        return response()->json(['dry_run' => false, 'source' => $this->format($source->load('socialAccounts.platform'))], 201);
    }

    public function update(Request $request, VaultSource $vaultSource): JsonResponse
    {
        abort_unless($request->user()->isManager(), 403);
        [$attributes, $accounts] = VaultSourceInput::validate($request, $request->user(), partial: true);
        $vaultSource->fill($attributes);
        if ($request->boolean('dry_run', true)) {
            return response()->json(['dry_run' => true, 'source' => $this->format($vaultSource, $accounts ?? $vaultSource->socialAccounts()->pluck('social_accounts.id')->all())]);
        }
        DB::transaction(function () use ($vaultSource, $accounts) {
            $vaultSource->save();
            if ($accounts !== null) {
                $vaultSource->socialAccounts()->sync($accounts);
            }
        });

        return response()->json(['dry_run' => false, 'source' => $this->format($vaultSource->fresh()->load('socialAccounts.platform'))]);
    }

    public function destroy(Request $request, VaultSource $vaultSource): JsonResponse
    {
        abort_unless($request->user()->isManager(), 403);
        if ($request->boolean('dry_run', true)) {
            return response()->json(['dry_run' => true, 'deleted' => false, 'source' => $this->format($vaultSource->load('socialAccounts.platform'))]);
        }
        $vaultSource->delete();

        return response()->json(['dry_run' => false, 'deleted' => true]);
    }

    /** Lecture seule : ce que la source renverrait pour un texte donné. */
    public function test(Request $request, VaultSource $vaultSource, XReplyVault $vault): JsonResponse
    {
        abort_unless($request->user()->isManager(), 403);
        $data = $request->validate(['text' => 'required|string|max:6000', 'origin' => 'nullable|string|max:6000']);

        return response()->json($vault->lookupSource($vaultSource, $data['text'], $data['origin'] ?? null));
    }

    private function format(VaultSource $source, ?array $accountIds = null): array
    {
        return $source->only(['id', 'name', 'corpus', 'format', 'folders', 'excluded_folders', 'brands', 'is_active']) + [
            'accounts' => $accountIds ?? $source->socialAccounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'platform' => $a->platform?->slug])->all(),
        ];
    }
}
