@extends('layouts.app')

@section('title', 'Bluesky')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">

        {{-- Ajouter un compte --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <x-platform-icon platform="bluesky" size="lg" />
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Connecter un compte Bluesky</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Utilisez un App Password pour connecter votre compte.</p>
                    </div>
                </div>
            </div>

            <div class="p-6">
                <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-5">
                    <div class="flex gap-3">
                        <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                        </svg>
                        <div class="text-sm text-blue-800">
                            <p class="font-medium mb-1">Comment obtenir un App Password ?</p>
                            <ol class="list-decimal list-inside space-y-1 text-blue-700">
                                <li>Allez sur <strong>bsky.app</strong> &rarr; Settings &rarr; Privacy and Security</li>
                                <li>Cliquez sur <strong>App Passwords</strong></li>
                                <li>Cliquez sur <strong>Add App Password</strong></li>
                                <li>Donnez un nom (ex: "RS-Max") et copiez le mot de passe</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <form action="{{ route('platforms.bluesky.addAccount') }}" method="POST" class="space-y-4" x-data="blueskyForm()">
                    @csrf

                    <div>
                        <label for="bs_handle" class="block text-sm font-medium text-gray-700 mb-1">Handle</label>
                        <input type="text" name="handle" id="bs_handle" value="{{ old('handle') }}" placeholder="votre-handle.bsky.social" required
                            x-model="handle"
                            class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="bs_app_password" class="block text-sm font-medium text-gray-700 mb-1">App Password</label>
                        <input type="password" name="app_password" id="bs_app_password" required
                            x-model="appPassword"
                            class="w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>

                    {{-- Validation result --}}
                    <div x-show="validated" x-cloak class="flex items-center gap-3 p-4 bg-green-50 border border-green-200 rounded-xl">
                        <img x-show="profilePic" :src="profilePic" class="w-10 h-10 rounded-full" alt="">
                        <div>
                            <p class="text-sm font-medium text-green-900" x-text="displayName"></p>
                            <p class="text-xs text-green-700">@<span x-text="resolvedHandle"></span></p>
                        </div>
                    </div>

                    <div x-show="error" x-cloak class="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700" x-text="error"></div>

                    <div class="flex items-center gap-3 justify-end pt-2">
                        <button type="button" @click="validate()" :disabled="validating || !handle || !appPassword"
                            class="inline-flex items-center gap-2 px-4 py-2.5 bg-white text-sm font-medium text-gray-700 border border-gray-300 rounded-xl hover:bg-gray-50 transition-colors shadow-sm disabled:opacity-50">
                            <svg x-show="!validating" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <svg x-show="validating" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Tester la connexion
                        </button>
                        <button type="submit" :disabled="!validated"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-900 text-white text-sm font-medium rounded-xl hover:bg-gray-800 transition-colors shadow-sm disabled:opacity-50">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            Connecter le compte
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Comptes connectes --}}
        @if($accounts->isNotEmpty())
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-base font-semibold text-gray-900">Comptes Bluesky</h3>
                </div>

                <div class="divide-y divide-gray-100">
                    @foreach($accounts as $account)
                        <div class="px-6 py-4 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                @if($account->profile_picture_url)
                                    <img src="{{ $account->profile_picture_url }}" alt="" class="w-10 h-10 rounded-full">
                                @else
                                    <x-platform-icon platform="bluesky" size="lg" />
                                @endif
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ $account->name }}</p>
                                    <p class="text-xs text-gray-500">@{{ $account->credentials['handle'] ?? '' }}</p>
                                </div>
                            </div>

                            <form action="{{ route('platforms.destroyAccount', $account) }}" method="POST"
                                onsubmit="return confirm('Supprimer ce compte Bluesky ?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-500 hover:text-red-700 transition-colors">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                    </svg>
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

    </div>

    @push('scripts')
    <script>
        function blueskyForm() {
            return {
                handle: '{{ old("handle", "") }}',
                appPassword: '',
                validating: false,
                validated: false,
                error: null,
                resolvedHandle: '',
                displayName: '',
                profilePic: null,

                async validate() {
                    this.validating = true;
                    this.validated = false;
                    this.error = null;

                    try {
                        const response = await fetch('{{ route("platforms.bluesky.validateAccount") }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                handle: this.handle,
                                app_password: this.appPassword,
                            }),
                        });

                        const data = await response.json();

                        if (data.success) {
                            this.validated = true;
                            this.resolvedHandle = data.handle;
                            this.displayName = data.display_name || data.handle;
                            this.profilePic = data.profile_picture_url;
                        } else {
                            this.error = data.error || 'Identifiants invalides.';
                        }
                    } catch (e) {
                        this.error = 'Erreur de connexion. Veuillez reessayer.';
                    } finally {
                        this.validating = false;
                    }
                }
            };
        }
    </script>
    @endpush
@endsection
