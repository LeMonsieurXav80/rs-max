@extends('layouts.app')

@section('title', 'Modifier site WordPress')

@section('actions')
    <a href="{{ route('wordpress-sites.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
        </svg>
        Retour
    </a>
@endsection

@section('content')

    <form method="POST" action="{{ route('wordpress-sites.update', $wpSource) }}" class="max-w-3xl"
          x-data="{
              testing: false,
              testResult: null,
              testError: null,
              availableTypes: @js($wpSource->post_types ? collect($wpSource->post_types)->map(fn($t) => ['slug' => $t, 'name' => $t, 'rest_base' => $t])->values() : []),
              selectedTypes: @js($wpSource->post_types ?? []),
              availableCategories: [],
              selectedCategories: @js($wpSource->categories ?? []),
              selectedAccounts: @js(collect($linkedAccounts)->map(fn($data, $id) => array_merge($data, ['id' => (int)$id, 'persona_id' => $data['persona_id'] ?? '', 'auto_post' => (bool)$data['auto_post']]))->values()),
              toggleType(slug) {
                  const idx = this.selectedTypes.indexOf(slug);
                  if (idx >= 0) {
                      this.selectedTypes.splice(idx, 1);
                  } else {
                      this.selectedTypes.push(slug);
                  }
              },
              isTypeSelected(slug) {
                  return this.selectedTypes.includes(slug);
              },
              toggleCategory(id) {
                  const idx = this.selectedCategories.indexOf(id);
                  if (idx >= 0) {
                      this.selectedCategories.splice(idx, 1);
                  } else {
                      this.selectedCategories.push(id);
                  }
              },
              isCategorySelected(id) {
                  return this.selectedCategories.includes(id);
              },
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
                      const resp = await fetch('{{ route('wordpress-sites.testConnection') }}', {
                          method: 'POST',
                          headers: {
                              'Content-Type': 'application/json',
                              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                              'Accept': 'application/json',
                          },
                          body: JSON.stringify({
                              url: document.getElementById('url').value,
                              auth_username: document.getElementById('auth_username').value,
                              auth_password: document.getElementById('auth_password').value,
                          }),
                      });

                      const data = await resp.json();

                      if (data.success) {
                          this.testResult = data;
                          this.availableTypes = data.post_types || [];
                          this.availableCategories = data.categories || [];
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
        @method('PUT')

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-6">

            {{-- Infos du site --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-400" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M21.469 6.825c.84 1.537 1.318 3.3 1.318 5.175 0 3.979-2.156 7.456-5.363 9.325l3.295-9.527c.615-1.539.82-2.771.82-3.864 0-.397-.026-.765-.07-1.109m-7.981.105c.647-.034 1.23-.1 1.23-.1.579-.068.51-.919-.069-.886 0 0-1.742.137-2.865.137-1.056 0-2.83-.137-2.83-.137-.579-.033-.648.852-.068.886 0 0 .549.06 1.128.103l1.674 4.59-2.35 7.05-3.911-11.64c.647-.034 1.23-.1 1.23-.1.579-.068.51-.919-.069-.886 0 0-1.742.137-2.865.137-.201 0-.44-.005-.693-.014C4.758 3.668 8.088 2 11.869 2c2.81 0 5.371 1.075 7.294 2.833-.046-.003-.091-.009-.141-.009-1.056 0-1.803.919-1.803 1.907 0 .886.51 1.636 1.055 2.523.41.717.889 1.636.889 2.962 0 .919-.354 1.985-.82 3.47l-1.075 3.59-3.896-11.586.003-.002zM11.869 24c-1.886 0-3.673-.429-5.265-1.196l5.596-16.252 5.728 15.69c.038.092.083.178.131.257C15.997 23.467 14.008 24 11.869 24M.926 12c0-2.335.73-4.5 1.974-6.278l5.441 14.906C3.597 18.705.926 15.641.926 12"/>
                    </svg>
                    Informations du site
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                        <input type="text" name="name" id="name" value="{{ old('name', $wpSource->name) }}" required
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <input type="text" name="description" id="description" value="{{ old('description', $wpSource->description) }}"
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-4">
                    <label for="url" class="block text-sm font-medium text-gray-700 mb-1">URL du site WordPress *</label>
                    <input type="url" name="url" id="url" value="{{ old('url', $wpSource->url) }}" required
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    @error('url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Auth optionnel --}}
                <div class="mt-4 p-4 bg-gray-50 rounded-xl">
                    <p class="text-xs font-medium text-gray-600 mb-3">Authentification (optionnel)</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="auth_username" class="block text-xs font-medium text-gray-500 mb-1">Identifiant</label>
                            <input type="text" name="auth_username" id="auth_username" value="{{ old('auth_username', $wpSource->auth_username) }}"
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label for="auth_password" class="block text-xs font-medium text-gray-500 mb-1">Mot de passe d'application</label>
                            <input type="password" name="auth_password" id="auth_password"
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                   placeholder="{{ $wpSource->auth_password ? '••••••••' : '' }}">
                            <p class="text-xs text-gray-400 mt-1">Laissez vide pour conserver le mot de passe actuel.</p>
                        </div>
                    </div>
                </div>

                {{-- Test connexion --}}
                <div class="mt-4">
                    <button type="button" @click="testConnection()" :disabled="testing"
                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-xl hover:bg-blue-100 transition-colors disabled:opacity-50">
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
                        <span x-text="testing ? 'Test en cours...' : 'Re-tester la connexion'"></span>
                    </button>

                    <div x-show="testResult" x-transition class="mt-3 rounded-xl bg-green-50 border border-green-200 p-4">
                        <p class="text-sm text-green-700 font-medium">Connexion réussie <span x-text="testResult?.site_name ? '(' + testResult.site_name + ')' : ''"></span></p>
                        <p class="text-xs text-green-600 mt-1" x-text="availableTypes.length + ' type(s) de contenu disponible(s)'"></p>
                    </div>
                    <div x-show="testError" x-transition class="mt-3 rounded-xl bg-red-50 border border-red-200 p-4">
                        <p class="text-sm text-red-700" x-text="testError"></p>
                    </div>
                </div>
            </div>

            <hr class="border-gray-100">

            {{-- Types de contenu --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-2 flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
                    </svg>
                    Types de contenu *
                </h3>
                <p class="text-xs text-gray-500 mb-4">Cliquez "Re-tester la connexion" pour actualiser les types disponibles.</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <template x-for="type in availableTypes" :key="type.slug">
                        <div class="border rounded-xl p-3 cursor-pointer transition-colors"
                             :class="isTypeSelected(type.slug) ? 'border-blue-300 bg-blue-50/50' : 'border-gray-200 hover:border-gray-300'"
                             @click="toggleType(type.slug)">
                            <div class="flex items-center gap-3">
                                <input type="checkbox" :checked="isTypeSelected(type.slug)"
                                       class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500"
                                       @click.stop="toggleType(type.slug)">
                                <div>
                                    <p class="text-sm font-medium text-gray-900" x-text="type.name"></p>
                                    <p class="text-xs text-gray-400" x-text="type.slug"></p>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <template x-for="slug in selectedTypes" :key="'type-' + slug">
                    <input type="hidden" name="post_types[]" :value="slug">
                </template>

                @error('post_types') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Catégories (dynamiques, optionnel) --}}
            <template x-if="availableCategories.length > 0">
                <div>
                    <hr class="border-gray-100 mb-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-2 flex items-center gap-2">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" />
                        </svg>
                        Filtrer par catégories
                    </h3>
                    <p class="text-xs text-gray-500 mb-4">Optionnel. Si aucune catégorie n'est cochée, tous les articles seront importés.</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <template x-for="cat in availableCategories" :key="cat.id">
                            <div class="border rounded-xl p-3 cursor-pointer transition-colors"
                                 :class="isCategorySelected(cat.id) ? 'border-blue-300 bg-blue-50/50' : 'border-gray-200 hover:border-gray-300'"
                                 @click="toggleCategory(cat.id)">
                                <div class="flex items-center gap-3">
                                    <input type="checkbox" :checked="isCategorySelected(cat.id)"
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500"
                                           @click.stop="toggleCategory(cat.id)">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900" x-text="cat.name"></p>
                                        <p class="text-xs text-gray-400" x-text="cat.count + ' article(s)'"></p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Hidden inputs for categories --}}
                    <template x-for="catId in selectedCategories" :key="'cat-' + catId">
                        <input type="hidden" name="categories[]" :value="catId">
                    </template>
                </div>
            </template>

            <div x-show="availableCategories.length === 0 && selectedCategories.length > 0" class="mt-2">
                <p class="text-xs text-gray-400 italic">Catégories filtrées : <span x-text="selectedCategories.length"></span> sélectionnée(s). Cliquez "Re-tester la connexion" pour les voir.</p>
                <template x-for="catId in selectedCategories" :key="'cat-saved-' + catId">
                    <input type="hidden" name="categories[]" :value="catId">
                </template>
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
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <option value="daily" {{ old('schedule_frequency', $wpSource->schedule_frequency) === 'daily' ? 'selected' : '' }}>Quotidien</option>
                            <option value="twice_weekly" {{ old('schedule_frequency', $wpSource->schedule_frequency) === 'twice_weekly' ? 'selected' : '' }}>2x par semaine</option>
                            <option value="weekly" {{ old('schedule_frequency', $wpSource->schedule_frequency) === 'weekly' ? 'selected' : '' }}>Hebdomadaire</option>
                            <option value="biweekly" {{ old('schedule_frequency', $wpSource->schedule_frequency) === 'biweekly' ? 'selected' : '' }}>Tous les 15 jours</option>
                            <option value="monthly" {{ old('schedule_frequency', $wpSource->schedule_frequency) === 'monthly' ? 'selected' : '' }}>Mensuel</option>
                        </select>
                        @error('schedule_frequency') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="schedule_time" class="block text-sm font-medium text-gray-700 mb-1">Heure de publication</label>
                        <input type="time" name="schedule_time" id="schedule_time" value="{{ old('schedule_time', $wpSource->schedule_time ?? '10:00') }}"
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
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
                <p class="text-xs text-gray-500 mb-4">Sélectionnez les comptes sociaux qui recevront les publications générées depuis ce site.</p>

                <div class="space-y-3">
                    @foreach($accounts as $account)
                        <div class="border rounded-xl transition-colors"
                             :class="isSelected({{ $account->id }}) ? 'border-indigo-200 bg-indigo-50/30' : 'border-gray-200'">
                            <div class="flex items-center gap-3 p-3 cursor-pointer" @click="toggleAccount({{ $account->id }})">
                                <input type="checkbox" :checked="isSelected({{ $account->id }})" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" @click.stop="toggleAccount({{ $account->id }})">

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
                                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs">
                                                <option value="">Aucun</option>
                                                @foreach($personas as $persona)
                                                    <option value="{{ $persona->id }}">{{ $persona->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">Fréquence</label>
                                            <select x-model="getAccount({{ $account->id }}).post_frequency"
                                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs">
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
                                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs">
                                        </div>
                                    </div>
                                    <div class="mt-3 flex items-center gap-2">
                                        <input type="checkbox" x-model="getAccount({{ $account->id }}).auto_post"
                                               class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
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
            </div>

            <hr class="border-gray-100">

            {{-- Actif --}}
            <div class="flex items-center gap-3">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $wpSource->is_active) ? 'checked' : '' }}
                       class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                <label for="is_active" class="text-sm font-medium text-gray-700">Source active</label>
            </div>
        </div>

        {{-- Submit --}}
        <div class="mt-6 flex items-center gap-3">
            <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                Enregistrer
            </button>
            <a href="{{ route('wordpress-sites.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annuler</a>
        </div>
    </form>

@endsection
