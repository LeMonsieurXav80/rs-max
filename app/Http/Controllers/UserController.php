<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::withCount('socialAccounts', 'posts')->orderByDesc('is_admin')->orderBy('name')->get();

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        $accounts = SocialAccount::with('platform')
            ->orderBy('platform_id')
            ->orderBy('name')
            ->get()
            ->reject(fn ($a) => str_starts_with($a->platform_account_id ?? '', 'bot_'))
            ->groupBy(fn ($a) => $a->platform->name);

        return view('users.create', compact('accounts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'default_language' => 'required|in:fr,en,pt,es,de,it',
            'accounts' => 'nullable|array',
            'accounts.*' => 'exists:social_accounts,id',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'default_language' => $validated['default_language'],
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        // Assign selected social accounts
        if (! empty($validated['accounts'])) {
            $user->socialAccounts()->attach(
                collect($validated['accounts'])->mapWithKeys(fn ($id) => [$id => ['is_active' => true]])->all()
            );
        }

        return redirect()->route('users.index')
            ->with('success', "Utilisateur \"{$user->name}\" créé avec succès.");
    }

    public function edit(User $user): View
    {
        $accounts = SocialAccount::with('platform')
            ->orderBy('platform_id')
            ->orderBy('name')
            ->get()
            ->reject(fn ($a) => str_starts_with($a->platform_account_id ?? '', 'bot_'))
            ->groupBy(fn ($a) => $a->platform->name);

        $assignedIds = $user->socialAccounts()->pluck('social_accounts.id')->toArray();

        return view('users.edit', compact('user', 'accounts', 'assignedIds'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'default_language' => 'required|in:fr,en,pt,es,de,it',
            'accounts' => 'nullable|array',
            'accounts.*' => 'exists:social_accounts,id',
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'default_language' => $validated['default_language'],
        ]);

        if (! empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        // Sync social accounts (preserve is_active state for existing links)
        $newAccountIds = $validated['accounts'] ?? [];
        $currentAccountIds = $user->socialAccounts()->pluck('social_accounts.id')->toArray();

        // Detach removed accounts
        $toDetach = array_diff($currentAccountIds, $newAccountIds);
        if ($toDetach) {
            $user->socialAccounts()->detach($toDetach);
        }

        // Attach new accounts
        $toAttach = array_diff($newAccountIds, $currentAccountIds);
        foreach ($toAttach as $accountId) {
            $user->socialAccounts()->attach($accountId, ['is_active' => true]);
        }

        return redirect()->route('users.index')
            ->with('success', "Utilisateur \"{$user->name}\" mis à jour.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        // Cannot delete yourself
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'Vous ne pouvez pas supprimer votre propre compte.']);
        }

        // Check for pending posts
        $pendingPosts = $user->posts()->whereIn('status', ['pending', 'scheduled', 'publishing'])->count();
        if ($pendingPosts > 0) {
            return back()->withErrors(['user' => "Impossible de supprimer : {$pendingPosts} publication(s) en attente."]);
        }

        $name = $user->name;
        $user->socialAccounts()->detach();
        $user->delete();

        return redirect()->route('users.index')
            ->with('success', "Utilisateur \"{$name}\" supprimé.");
    }

    public function toggleAdmin(Request $request, User $user): JsonResponse
    {
        // Cannot toggle yourself
        if ($user->id === $request->user()->id) {
            return response()->json(['error' => 'Vous ne pouvez pas modifier votre propre rôle.'], 422);
        }

        $user->update(['is_admin' => ! $user->is_admin]);

        return response()->json([
            'success' => true,
            'is_admin' => $user->is_admin,
            'message' => $user->is_admin ? 'Promu administrateur.' : 'Rétrogradé en utilisateur.',
        ]);
    }
}
