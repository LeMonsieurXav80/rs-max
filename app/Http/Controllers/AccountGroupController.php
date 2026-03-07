<?php

namespace App\Http\Controllers;

use App\Models\AccountGroup;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountGroupController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $groups = $user->accountGroups()->with('socialAccounts.platform')->get();

        $allAccounts = ($user->isAdmin()
            ? SocialAccount::query()
            : $user->socialAccounts())
            ->with('platform')
            ->orderBy('name')
            ->get();

        return view('account-groups.index', compact('groups', 'allAccounts'));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'color' => 'nullable|string|max:7',
            'account_ids' => 'nullable|array',
            'account_ids.*' => 'integer|exists:social_accounts,id',
        ]);

        $group = $request->user()->accountGroups()->create([
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#6366f1',
            'sort_order' => $request->user()->accountGroups()->max('sort_order') + 1,
        ]);

        if (! empty($validated['account_ids'])) {
            $group->socialAccounts()->sync($validated['account_ids']);
        }

        return response()->json([
            'success' => true,
            'group' => $group->load('socialAccounts.platform'),
        ]);
    }

    public function update(Request $request, AccountGroup $accountGroup): JsonResponse
    {
        if ($accountGroup->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:50',
            'color' => 'sometimes|string|max:7',
            'account_ids' => 'nullable|array',
            'account_ids.*' => 'integer|exists:social_accounts,id',
        ]);

        $accountGroup->update(collect($validated)->only(['name', 'color'])->filter()->all());

        if (array_key_exists('account_ids', $validated)) {
            $accountGroup->socialAccounts()->sync($validated['account_ids'] ?? []);
        }

        return response()->json([
            'success' => true,
            'group' => $accountGroup->load('socialAccounts.platform'),
        ]);
    }

    public function destroy(Request $request, AccountGroup $accountGroup): JsonResponse
    {
        if ($accountGroup->user_id !== $request->user()->id) {
            abort(403);
        }

        $accountGroup->delete();

        return response()->json(['success' => true]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:account_groups,id',
        ]);

        foreach ($validated['ids'] as $index => $id) {
            AccountGroup::where('id', $id)
                ->where('user_id', $request->user()->id)
                ->update(['sort_order' => $index]);
        }

        return response()->json(['success' => true]);
    }
}
