@extends('layouts.app')

@section('title', 'Profil')

@section('content')
    <div class="max-w-2xl space-y-6">
        {{-- Profile information --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h2 class="text-base font-semibold text-gray-900">Informations du profil</h2>
            <p class="text-sm text-gray-500 mt-1">Mettez a jour votre nom et votre adresse e-mail.</p>

            <form method="POST" action="{{ route('profile.update') }}" class="mt-5 space-y-4">
                @csrf
                @method('patch')

                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required autofocus
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    @error('name')
                        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    @error('email')
                        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit"
                        class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                        Enregistrer
                    </button>
                    @if (session('status') === 'profile-updated')
                        <p x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                           class="text-sm text-green-600 font-medium">Enregistre.</p>
                    @endif
                </div>
            </form>
        </div>

        {{-- Password update --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <h2 class="text-base font-semibold text-gray-900">Mot de passe</h2>
            <p class="text-sm text-gray-500 mt-1">Utilisez un mot de passe long et unique pour securiser votre compte.</p>

            <form method="POST" action="{{ route('password.update') }}" class="mt-5 space-y-4">
                @csrf
                @method('put')

                <div>
                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Mot de passe actuel</label>
                    <input id="current_password" name="current_password" type="password" autocomplete="current-password"
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    @error('current_password', 'updatePassword')
                        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Nouveau mot de passe</label>
                    <input id="password" name="password" type="password" autocomplete="new-password"
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    @error('password', 'updatePassword')
                        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Confirmer le mot de passe</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    @error('password_confirmation', 'updatePassword')
                        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit"
                        class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                        Changer le mot de passe
                    </button>
                    @if (session('status') === 'password-updated')
                        <p x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                           class="text-sm text-green-600 font-medium">Mot de passe mis a jour.</p>
                    @endif
                </div>
            </form>
        </div>

        {{-- Delete account --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6" x-data="{ confirmDelete: false }">
            <h2 class="text-base font-semibold text-gray-900">Supprimer le compte</h2>
            <p class="text-sm text-gray-500 mt-1">
                Une fois votre compte supprime, toutes ses donnees seront definitivement effacees.
            </p>

            <button type="button" @click="confirmDelete = true"
                class="mt-4 px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-xl hover:bg-red-700 transition-colors shadow-sm">
                Supprimer mon compte
            </button>

            {{-- Delete confirmation modal --}}
            <div x-show="confirmDelete" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="confirmDelete = false">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 p-6">
                    <form method="POST" action="{{ route('profile.destroy') }}">
                        @csrf
                        @method('delete')

                        <h3 class="text-base font-semibold text-gray-900">Confirmer la suppression</h3>
                        <p class="text-sm text-gray-500 mt-2">
                            Cette action est irreversible. Entrez votre mot de passe pour confirmer.
                        </p>

                        <div class="mt-4">
                            <input name="password" type="password" placeholder="Mot de passe"
                                   class="w-full rounded-xl border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm">
                            @error('password', 'userDeletion')
                                <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex justify-end gap-3 mt-6">
                            <button type="button" @click="confirmDelete = false"
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition-colors">
                                Annuler
                            </button>
                            <button type="submit"
                                class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-xl hover:bg-red-700 transition-colors">
                                Supprimer definitivement
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
