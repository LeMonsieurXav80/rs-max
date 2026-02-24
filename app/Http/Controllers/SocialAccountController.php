<?php

namespace App\Http\Controllers;

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
     * Admin sees all accounts; regular user sees only their own.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = SocialAccount::with('platform', 'user');

        if (! $user->is_admin) {
            $query->where('user_id', $user->id);
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
            'language'       => 'nullable|string|max:10',
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

        SocialAccount::create([
            'user_id'      => $user->id,
            'platform_id'  => $validated['platform_id'],
            'name'         => $validated['name'],
            'credentials'  => $credentials,
            'language'     => $validated['language'] ?? $user->default_language ?? 'fr',
            'branding'     => $validated['branding'] ?? null,
            'show_branding' => $validated['show_branding'] ?? false,
            'is_active'    => true,
        ]);

        return redirect()->route('accounts.index')
            ->with('success', 'Social account added successfully.');
    }

    /**
     * Show the form for editing the specified social account.
     */
    public function edit(Request $request, int $id): View
    {
        $account = SocialAccount::with('platform')->findOrFail($id);

        // Regular users can only edit their own accounts
        if (! $request->user()->is_admin && $account->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }

        $platforms = Platform::where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('accounts.edit', compact('account', 'platforms'));
    }

    /**
     * Update the specified social account.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $account = SocialAccount::findOrFail($id);

        // Regular users can only update their own accounts
        if (! $request->user()->is_admin && $account->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'language'       => 'nullable|string|max:10',
            'branding'       => 'nullable|string|max:500',
            'show_branding'  => 'nullable|boolean',
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
            'language'      => $validated['language'] ?? $account->language,
            'branding'      => $validated['branding'] ?? $account->branding,
            'show_branding' => $validated['show_branding'] ?? false,
            'credentials'   => $account->credentials,
        ]);

        return redirect()->route('accounts.index')
            ->with('success', 'Social account updated successfully.');
    }

    /**
     * Delete the specified social account.
     */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        $account = SocialAccount::findOrFail($id);

        // Regular users can only delete their own accounts
        if (! $request->user()->is_admin && $account->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }

        // Check if there are pending/publishing posts using this account
        $activePosts = $account->postPlatforms()
            ->whereIn('status', ['pending', 'publishing'])
            ->count();

        if ($activePosts > 0) {
            return back()->withErrors([
                'account' => 'Cannot delete this account because it has posts that are pending or currently being published.',
            ]);
        }

        $account->delete();

        return redirect()->route('accounts.index')
            ->with('success', 'Social account deleted successfully.');
    }

    /**
     * Toggle the is_active status of the specified social account.
     * Returns JSON for AJAX requests.
     */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $account = SocialAccount::findOrFail($id);

        // Regular users can only toggle their own accounts
        if (! $request->user()->is_admin && $account->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        $account->is_active = ! $account->is_active;
        $account->save();

        return response()->json([
            'success'   => true,
            'is_active' => $account->is_active,
            'message'   => $account->is_active
                ? 'Account activated successfully.'
                : 'Account deactivated successfully.',
        ]);
    }
}
