{{-- Social account assignment checkboxes, grouped by platform --}}
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
    <h2 class="text-base font-semibold text-gray-900 mb-2">Comptes sociaux assignes</h2>
    <p class="text-xs text-gray-400 mb-6">Selectionnez les comptes auxquels cet utilisateur aura acces.</p>

    @if($accounts->isEmpty())
        <p class="text-sm text-gray-400">Aucun compte social disponible.</p>
    @else
        <div class="space-y-6">
            @foreach($accounts as $platformName => $platformAccounts)
                <div>
                    <h3 class="text-sm font-medium text-gray-700 mb-3">{{ $platformName }}</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @foreach($platformAccounts as $account)
                            <label class="flex items-center gap-3 px-3 py-2.5 rounded-xl border border-gray-200 hover:bg-indigo-50/50 cursor-pointer transition-colors">
                                <input
                                    type="checkbox"
                                    name="accounts[]"
                                    value="{{ $account->id }}"
                                    {{ in_array($account->id, $assignedIds ?? []) ? 'checked' : '' }}
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                >
                                @if($account->profile_picture_url)
                                    <img src="{{ $account->profile_picture_url }}" alt="" class="w-6 h-6 rounded-lg object-cover flex-shrink-0">
                                @else
                                    <div class="w-6 h-6 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                                        <span class="text-xs font-bold text-gray-400">{{ strtoupper(substr($account->name, 0, 1)) }}</span>
                                    </div>
                                @endif
                                <span class="text-sm text-gray-700 truncate">{{ $account->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @error('accounts')
        <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
