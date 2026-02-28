@extends('layouts.app')

@section('title', 'Nouvelle chaîne YouTube')

@section('actions')
    <a href="{{ route('youtube-channels.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
        </svg>
        Retour
    </a>
@endsection

@section('content')

    <form method="POST" action="{{ route('youtube-channels.store') }}" class="max-w-3xl"
          x-data="{
              testing: false,
              testResult: null,
              testError: null,
              channelId: '',
              channelName: '',
              thumbnailUrl: '',
              selectedAccounts: [],
              toggleAccount(id) {
                  const idx = this.selectedAccounts.findIndex(a => a.id === id);
                  if (idx >= 0) {
                      this.selectedAccounts.splice(idx, 1);
                  } else {
                      this.selectedAccounts.push({ id, persona_id: '', auto_post: false, post_frequency: 'daily', max_posts_per_day: 1 });
                  }
              },
              isSelected(id) {
                  return this.selectedAccounts.some(a => a.id === id);
              },
              getAccount(id) {
                  return this.selectedAccounts.find(a => a.id === id);
              },
              async testConnection() {
                  this.testing = true;
                  this.testResult = null;
                  this.testError = null;

                  try {
                      const resp = await fetch('{{ route('youtube-channels.testConnection') }}', {
                          method: 'POST',
                          headers: {
                              'Content-Type': 'application/json',
                              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                              'Accept': 'application/json',
                          },
                          body: JSON.stringify({
                              channel_url: document.getElementById('channel_url').value,
                          }),
                      });

                      const data = await resp.json();

                      if (data.success) {
                          this.testResult = data;
                          this.channelId = data.channel_id || '';
                          this.channelName = data.channel_name || '';
                          this.thumbnailUrl = data.thumbnail_url || '';
                      } else {
                          this.testError = data.error || 'Erreur inconnue.';
                      }
                  } catch (e) {
                      this.testError = 'Erreur de connexion : ' + e.message;
                  }

                  this.testing = false;
              }
          }">
        @csrf

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-6">

            {{-- Infos de la chaîne --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-red-600" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                    </svg>
                    Informations de la chaîne
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                        <input type="text" name="name" id="name" value="{{ old('name') }}" required
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm"
                               placeholder="Ex: Ma Chaîne YouTube">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <input type="text" name="description" id="description" value="{{ old('description') }}"
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm"
                               placeholder="Description optionnelle">
                        @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-4">
                    <label for="channel_url" class="block text-sm font-medium text-gray-700 mb-1">URL de la chaîne YouTube *</label>
                    <input type="url" name="channel_url" id="channel_url" value="{{ old('channel_url') }}" required
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm"
                           placeholder="Ex: https://www.youtube.com/@MaChaine">
                    @error('channel_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Test connexion --}}
                <div class="mt-4">
                    <button type="button" @click="testConnection()" :disabled="testing"
                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-red-600 bg-red-50 rounded-xl hover:bg-red-100 transition-colors disabled:opacity-50">
                        <template x-if="!testing">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.348 14.652a3.75 3.75 0 0 1 0-5.304m5.304 0a3.75 3.75 0 0 1 0 5.304m-7.425 2.121a6.75 6.75 0 0 1 0-9.546m9.546 0a6.75 6.75 0 0 1 0 9.546M5.106 18.894c-3.808-3.807-3.808-9.98 0-13.788m13.788 0c3.808 3.807 3.808 9.98 0 13.788M12 12h.008v.008H12V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                            </svg>
                        </template>
                        <template x-if="testing">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </template>
                        <span x-text="testing ? 'Test en cours...' : 'Tester la connexion'"></span>
                    </button>

                    {{-- Test result --}}
                    <div x-show="testResult" x-transition class="mt-3 rounded-xl bg-green-50 border border-green-200 p-4">
                        <div class="flex items-center gap-3">
                            <template x-if="thumbnailUrl">
                                <img :src="thumbnailUrl" class="w-12 h-12 rounded-lg object-cover flex-shrink-0" alt="">
                            </template>
                            <div>
                                <p class="text-sm text-green-700 font-medium">Connexion réussie</p>
                                <p class="text-xs text-green-600 mt-1" x-text="channelName"></p>
                            </div>
                        </div>
                    </div>
                    <div x-show="testError" x-transition class="mt-3 rounded-xl bg-red-50 border border-red-200 p-4">
                        <p class="text-sm text-red-700" x-text="testError"></p>
                    </div>
                </div>

                {{-- Hidden fields populated by AJAX --}}
                <input type="hidden" name="channel_id" :value="channelId">
                <input type="hidden" name="channel_name" :value="channelName">
                <input type="hidden" name="thumbnail_url" :value="thumbnailUrl">
            </div>

            <hr class="border-gray-100">

            {{-- Périodicité --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Périodicité de publication
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="schedule_frequency" class="block text-sm font-medium text-gray-700 mb-1">Fréquence</label>
                        <select name="schedule_frequency" id="schedule_frequency"
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm">
                            <option value="daily" {{ old('schedule_frequency') === 'daily' ? 'selected' : '' }}>Quotidien</option>
                            <option value="twice_weekly" {{ old('schedule_frequency') === 'twice_weekly' ? 'selected' : '' }}>2x par semaine</option>
                            <option value="weekly" {{ old('schedule_frequency', 'weekly') === 'weekly' ? 'selected' : '' }}>Hebdomadaire</option>
                            <option value="biweekly" {{ old('schedule_frequency') === 'biweekly' ? 'selected' : '' }}>Tous les 15 jours</option>
                            <option value="monthly" {{ old('schedule_frequency') === 'monthly' ? 'selected' : '' }}>Mensuel</option>
                        </select>
                        @error('schedule_frequency') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="schedule_time" class="block text-sm font-medium text-gray-700 mb-1">Heure de publication</label>
                        <input type="time" name="schedule_time" id="schedule_time" value="{{ old('schedule_time', '10:00') }}"
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm">
                        @error('schedule_time') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <hr class="border-gray-100">

            {{-- Comptes liés --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-2 flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                    </svg>
                    Comptes liés
                </h3>
                <p class="text-xs text-gray-500 mb-4">Sélectionnez les comptes sociaux qui recevront les publications générées depuis cette chaîne.</p>

                @if($accounts->isEmpty())
                    <p class="text-sm text-gray-400 italic">Aucun compte social actif.</p>
                @else
                    <div class="space-y-3">
                        @foreach($accounts as $account)
                            <div class="border rounded-xl transition-colors"
                                 :class="isSelected({{ $account->id }}) ? 'border-red-200 bg-red-50/30' : 'border-gray-200'">
                                <div class="flex items-center gap-3 p-3 cursor-pointer" @click="toggleAccount({{ $account->id }})">
                                    <input type="checkbox" :checked="isSelected({{ $account->id }})" class="rounded border-gray-300 text-red-600 shadow-sm focus:ring-red-500" @click.stop="toggleAccount({{ $account->id }})">

                                    @if($account->profile_picture_url)
                                        <img src="{{ $account->profile_picture_url }}" class="w-8 h-8 rounded-lg object-cover flex-shrink-0" alt="">
                                    @else
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background-color: {{ $account->platform->color }}20; color: {{ $account->platform->color }}">
                                            <span class="text-xs font-bold">{{ substr($account->name, 0, 2) }}</span>
                                        </div>
                                    @endif

                                    <div class="min-w-0 flex-1">
                                        <span class="text-sm font-medium text-gray-900">{{ $account->name }}</span>
                                        <span class="text-xs text-gray-400 ml-2">{{ $account->platform->name }}</span>
                                    </div>
                                </div>

                                <template x-if="isSelected({{ $account->id }})">
                                    <div class="px-3 pb-3 pt-0 border-t border-gray-100 mt-0">
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Persona</label>
                                                <select x-model="getAccount({{ $account->id }}).persona_id"
                                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-xs">
                                                    <option value="">Aucun</option>
                                                    @foreach($personas as $persona)
                                                        <option value="{{ $persona->id }}">{{ $persona->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Fréquence</label>
                                                <select x-model="getAccount({{ $account->id }}).post_frequency"
                                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-xs">
                                                    <option value="hourly">Toutes les heures</option>
                                                    <option value="every_6h">Toutes les 6h</option>
                                                    <option value="daily">Quotidien</option>
                                                    <option value="weekly">Hebdomadaire</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Max posts/jour</label>
                                                <input type="number" min="1" max="10"
                                                       x-model.number="getAccount({{ $account->id }}).max_posts_per_day"
                                                       class="w-full rounded-lg border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-xs">
                                            </div>
                                        </div>
                                        <div class="mt-3 flex items-center gap-2">
                                            <input type="checkbox" x-model="getAccount({{ $account->id }}).auto_post"
                                                   class="rounded border-gray-300 text-red-600 shadow-sm focus:ring-red-500">
                                            <label class="text-xs font-medium text-gray-600">Publication automatique (cron)</label>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        @endforeach
                    </div>

                    <template x-for="(account, index) in selectedAccounts" :key="account.id">
                        <div>
                            <input type="hidden" :name="'accounts['+index+'][id]'" :value="account.id">
                            <input type="hidden" :name="'accounts['+index+'][persona_id]'" :value="account.persona_id">
                            <input type="hidden" :name="'accounts['+index+'][auto_post]'" :value="account.auto_post ? 1 : 0">
                            <input type="hidden" :name="'accounts['+index+'][post_frequency]'" :value="account.post_frequency">
                            <input type="hidden" :name="'accounts['+index+'][max_posts_per_day]'" :value="account.max_posts_per_day">
                        </div>
                    </template>
                @endif
            </div>

            <hr class="border-gray-100">

            {{-- Actif --}}
            <div class="flex items-center gap-3">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" id="is_active" value="1" checked
                       class="rounded border-gray-300 text-red-600 shadow-sm focus:ring-red-500">
                <label for="is_active" class="text-sm font-medium text-gray-700">Source active</label>
            </div>
        </div>

        {{-- Submit --}}
        <div class="mt-6 flex items-center gap-3">
            <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-red-600 text-white text-sm font-medium rounded-xl hover:bg-red-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                Ajouter la chaîne
            </button>
            <a href="{{ route('youtube-channels.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annuler</a>
        </div>
    </form>

@endsection
