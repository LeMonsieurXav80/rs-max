@php
    $partner = $partner ?? null;
@endphp

<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-6">
    <div>
        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom <span class="text-red-500">*</span></label>
        <input type="text" name="name" id="name" required maxlength="80"
               value="{{ old('name', $partner?->name) }}"
               class="block w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
        <p class="mt-1 text-xs text-gray-400">
            C'est ce nom qui sert à taguer les photos. Le renommer met à jour toutes les photos déjà taguées.
        </p>
        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="contact_name" class="block text-sm font-medium text-gray-700 mb-1">Contact</label>
            <input type="text" name="contact_name" id="contact_name" maxlength="255"
                   value="{{ old('contact_name', $partner?->contact_name) }}"
                   class="block w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
            @error('contact_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="contact_email" class="block text-sm font-medium text-gray-700 mb-1">Email du contact</label>
            <input type="email" name="contact_email" id="contact_email" maxlength="255"
                   value="{{ old('contact_email', $partner?->contact_email) }}"
                   class="block w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
            @error('contact_email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="website" class="block text-sm font-medium text-gray-700 mb-1">Site web</label>
            <input type="url" name="website" id="website" placeholder="https://…" maxlength="2048"
                   value="{{ old('website', $partner?->website) }}"
                   class="block w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
            @error('website')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="color" class="block text-sm font-medium text-gray-700 mb-1">Couleur de la pastille</label>
            <input type="color" name="color" id="color"
                   value="{{ old('color', $partner?->color ?? '#f59e0b') }}"
                   class="h-10 w-20 rounded-xl border border-gray-300 p-1">
            @error('color')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>

    <div>
        <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes internes</label>
        <textarea name="notes" id="notes" rows="4" maxlength="5000"
                  class="block w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">{{ old('notes', $partner?->notes) }}</textarea>
        @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <label class="flex items-center gap-3">
        <input type="checkbox" name="is_active" value="1"
               @checked(old('is_active', $partner?->is_active ?? true))
               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
        <span class="text-sm text-gray-700">
            Actif
            <span class="block text-xs text-gray-400">Un partenaire inactif n'apparaît plus dans les sélecteurs, mais garde ses tags existants.</span>
        </span>
    </label>
</div>
