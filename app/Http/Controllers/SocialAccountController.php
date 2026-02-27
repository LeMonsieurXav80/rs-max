<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use App\Models\Platform;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SocialAccountController extends Controller
{
    /**
     * Display a list of social accounts grouped by platform.
     * Admin sees all accounts; regular user sees only their linked accounts.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        if ($user->is_admin) {
            $query = SocialAccount::with('platform', 'users');
        } else {
            $query = $user->socialAccounts()->with('platform', 'users');
        }

        $accounts = $query->orderBy('platform_id')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (SocialAccount $account) => $account->platform->name);

        return view('accounts.index', compact('accounts'));
    }

    /**
     * Show the form for creating a new social account.
     */
    public function create(): View
    {
        $platforms = Platform::where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('accounts.create', compact('platforms'));
    }

    /**
     * Validate and store a newly created social account with encrypted credentials.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'platform_id'   => 'required|integer|exists:platforms,id',
            'name'          => 'required|string|max:255',
            'languages'      => 'required|array|min:1',
            'languages.*'    => 'in:fr,en,pt,es,de,it',
            'branding'       => 'nullable|string|max:500',
            'show_branding'  => 'nullable|boolean',
            'credentials'    => 'required|array',
            'credentials.*'  => 'nullable|string|max:2000',
        ]);

        $user = $request->user();

        // Fetch the platform to validate credential fields
        $platform = Platform::findOrFail($validated['platform_id']);
        $expectedFields = $platform->config['credential_fields'] ?? [];

        // Build the credentials array using only expected fields
        $credentials = [];
        foreach ($expectedFields as $field) {
            $fieldKey = is_array($field) ? ($field['key'] ?? $field['name'] ?? null) : $field;
            if ($fieldKey && isset($validated['credentials'][$fieldKey])) {
                $credentials[$fieldKey] = $validated['credentials'][$fieldKey];
            }
        }

        // Extract platform_account_id based on platform
        $idFieldMap = ['facebook' => 'page_id', 'instagram' => 'account_id', 'telegram' => 'chat_id'];
        $idField = $idFieldMap[$platform->slug] ?? null;
        $platformAccountId = ($idField && isset($credentials[$idField])) ? $credentials[$idField] : null;

        // Check for existing account with same platform + platform_account_id
        $account = null;
        if ($platformAccountId) {
            $account = SocialAccount::where('platform_id', $platform->id)
                ->where('platform_account_id', $platformAccountId)
                ->first();
        }

        if ($account) {
            $account->update([
                'name' => $validated['name'],
                'credentials' => $credentials,
                'languages' => $validated['languages'],
                'branding' => $validated['branding'] ?? $account->branding,
                'show_branding' => $validated['show_branding'] ?? $account->show_branding,
            ]);
        } else {
            $account = SocialAccount::create([
                'platform_id'  => $validated['platform_id'],
                'platform_account_id' => $platformAccountId,
                'name'         => $validated['name'],
                'credentials'  => $credentials,
                'languages'    => $validated['languages'],
                'branding'     => $validated['branding'] ?? null,
                'show_branding' => $validated['show_branding'] ?? false,
                'is_active'    => true,
            ]);
        }

        // Link to current user
        if (! $account->users()->where('user_id', $user->id)->exists()) {
            $account->users()->attach($user->id);
        }

        return redirect()->route('accounts.index')
            ->with('success', 'Compte social ajouté avec succès.');
    }

    /**
     * Show the form for editing the specified social account.
     */
    public function edit(Request $request, int $id): View
    {
        $account = SocialAccount::with('platform')->findOrFail($id);

        if (! $this->userCanAccess($request->user(), $account)) {
            abort(403, 'Unauthorized.');
        }

        $platforms = Platform::where('is_active', true)
            ->orderBy('name')
            ->get();

        $personas = Persona::where('is_active', true)->orderBy('name')->get();

        return view('accounts.edit', compact('account', 'platforms', 'personas'));
    }

    /**
     * Update the specified social account.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $account = SocialAccount::findOrFail($id);

        if (! $this->userCanAccess($request->user(), $account)) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'languages'      => 'required|array|min:1',
            'languages.*'    => 'in:fr,en,pt,es,de,it',
            'branding'       => 'nullable|string|max:500',
            'show_branding'  => 'nullable|boolean',
            'persona_id'     => 'nullable|exists:personas,id',
            'credentials'    => 'nullable|array',
            'credentials.*'  => 'nullable|string|max:2000',
        ]);

        // Build credentials: merge with existing so blank fields don't wipe saved values
        if (isset($validated['credentials'])) {
            $platform = $account->platform;
            $expectedFields = $platform->config['credential_fields'] ?? [];
            $existingCredentials = $account->credentials ?? [];

            $credentials = $existingCredentials;
            foreach ($expectedFields as $field) {
                $fieldKey = is_array($field) ? ($field['key'] ?? $field['name'] ?? null) : $field;
                if ($fieldKey && isset($validated['credentials'][$fieldKey]) && $validated['credentials'][$fieldKey] !== '') {
                    $credentials[$fieldKey] = $validated['credentials'][$fieldKey];
                }
            }

            $account->credentials = $credentials;
        }

        $account->update([
            'name'          => $validated['name'],
            'languages'     => $validated['languages'],
            'branding'      => $validated['branding'] ?? $account->branding,
            'show_branding' => $validated['show_branding'] ?? false,
            'persona_id'    => $validated['persona_id'] ?? null,
            'credentials'   => $account->credentials,
        ]);

        return redirect()->route('accounts.index')
            ->with('success', 'Compte social mis à jour.');
    }

    /**
     * Delete the specified social account.
     */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        $account = SocialAccount::findOrFail($id);

        if (! $this->userCanAccess($request->user(), $account)) {
            abort(403, 'Unauthorized.');
        }

        // Check if there are pending/publishing posts using this account
        $activePosts = $account->postPlatforms()
            ->whereIn('status', ['pending', 'publishing'])
            ->count();

        if ($activePosts > 0) {
            return back()->withErrors([
                'account' => 'Impossible de supprimer ce compte car il a des publications en attente.',
            ]);
        }

        $account->delete();

        return redirect()->route('accounts.index')
            ->with('success', 'Compte social supprimé.');
    }

    /**
     * Toggle the is_active status of the specified social account.
     */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $account = SocialAccount::findOrFail($id);

        if (! $this->userCanAccess($request->user(), $account)) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        $account->is_active = ! $account->is_active;
        $account->save();

        return response()->json([
            'success'   => true,
            'is_active' => $account->is_active,
            'message'   => $account->is_active
                ? 'Compte activé.'
                : 'Compte désactivé.',
        ]);
    }

    private function userCanAccess($user, SocialAccount $account): bool
    {
        return $user->is_admin || $account->users()->where('user_id', $user->id)->exists();
    }
}
