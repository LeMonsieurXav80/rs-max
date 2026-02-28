@extends('layouts.app')

@section('title', 'Modifier subreddit Reddit')

@section('actions')
    <a href="{{ route('reddit-sources.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
        </svg>
        Retour
    </a>
@endsection

@section('content')

    <form method="POST" action="{{ route('reddit-sources.update', $redditSource) }}" class="max-w-3xl"
          x-data="{
              testing: false,
              testResult: null,
              testError: null,
              sortBy: @js($redditSource->sort_by ?? 'hot'),
              selectedAccounts: @js(collect($linkedAccounts)->map(fn($data, $id) => array_merge($data, ['id' => (int)$id, 'persona_id' => $data['persona_id'] ?? '', 'auto_post' => (bool)$data['auto_post']]))->values()),
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
                      const resp = await fetch('{{ route('reddit-sources.testConnection') }}', {
                          method: 'POST',
                          headers: {
                              'Content-Type': 'application/json',
                              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                              'Accept': 'application/json',
                          },
                          body: JSON.stringify({
                              subreddit: document.getElementById('subreddit').value,
                          }),
                      });

                      const data = await resp.json();

                      if (data.success) {
                          this.testResult = data;
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

            {{-- Infos du subreddit --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-orange-600" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0zm5.01 4.744c.688 0 1.25.561 1.25 1.249a1.25 1.25 0 0 1-2.498.056l-2.597-.547-.8 3.747c1.824.07 3.48.632 4.674 1.488.308-.309.73-.491 1.207-.491.968 0 1.754.786 1.754 1.754 0 .716-.435 1.333-1.01 1.614a3.111 3.111 0 0 1 .042.52c0 2.694-3.13 4.87-6.985 4.87-3.856 0-6.987-2.176-6.987-4.87 0-.183.015-.366.043-.534A1.748 1.748 0 0 1 4.028 12c0-.968.786-1.754 1.754-1.754.463 0 .898.196 1.207.49 1.207-.883 2.878-1.43 4.744-1.487l.885-4.182a.342.342 0 0 1 .14-.197.35.35 0 0 1 .238-.042l2.906.617a1.214 1.214 0 0 1 1.108-.701zM9.25 12C8.561 12 8 12.562 8 13.25c0 .687.561 1.248 1.25 1.248.687 0 1.248-.561 1.248-1.249 0-.688-.561-1.249-1.249-1.249zm5.5 0c-.687 0-1.248.561-1.248 1.25 0 .687.561 1.248 1.249 1.248.688 0 1.249-.561 1.249-1.249 0-.687-.562-1.249-1.25-1.249zm-5.466 3.99a.327.327 0 0 0-.231.094.33.33 0 0 0 0 .463c.842.842 2.484.913 2.961.913.477 0 2.105-.056 2.961-.913a.361.361 0 0 0 .029-.463.33.33 0 0 0-.464 0c-.547.533-1.684.73-2.512.73-.828 0-1.979-.196-2.512-.73a.326.326 0 0 0-.232-.095z"/>
                    </svg>
                    Informations du subreddit
                </h3>
                <div class="grid grid-cols-1 gap-5">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                        <input type="text" name="name" id="name" value="{{ old('name', $redditSource->name) }}" required
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 text-sm">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="subreddit" class="block text-sm font-medium text-gray-700 mb-1">Subreddit *</label>
                        <input type="text" name="subreddit" id="subreddit" value="{{ old('subreddit', $redditSource->subreddit) }}" required
                               placeholder="javascript (sans r/)"
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 text-sm">
                        @error('subreddit') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="sort_by" class="block text-sm font-medium text-gray-700 mb-1">Tri *</label>
                        <select name="sort_by" id="sort_by" x-model="sortBy"
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 text-sm">
                            <option value="hot" {{ old('sort_by', $redditSource->sort_by) === 'hot' ? 'selected' : '' }}>Hot</option>
                            <option value="new" {{ old('sort_by', $redditSource->sort_by) === 'new' ? 'selected' : '' }}>New</option>
                            <option value="top" {{ old('sort_by', $redditSource->sort_by) === 'top' ? 'selected' : '' }}>Top</option>
                            <option value="rising" {{ old('sort_by', $redditSource->sort_by) === 'rising' ? 'selected' : '' }}>Rising</option>
                        </select>
                        @error('sort_by') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div x-show="sortBy === 'top'">
                        <label for="time_filter" class="block text-sm font-medium text-gray-700 mb-1">Filtre temporel</label>
                        <select name="time_filter" id="time_filter"
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 text-sm">
                            <option value="hour" {{ old('time_filter', $redditSource->time_filter) === 'hour' ? 'selected' : '' }}>Dernière heure</option>
                            <option value="day" {{ old('time_filter', $redditSource->time_filter) === 'day' ? 'selected' : '' }}>Dernier jour</option>
                            <option value="week" {{ old('time_filter', $redditSource->time_filter) === 'week' ? 'selected' : '' }}>Dernière semaine</option>
                            <option value="month" {{ old('time_filter', $redditSource->time_filter) === 'month' ? 'selected' : '' }}>Dernier mois</option>
                            <option value="year" {{ old('time_filter', $redditSource->time_filter) === 'year' ? 'selected' : '' }}>Dernière année</option>
                            <option value="all" {{ old('time_filter', $redditSource->time_filter) === 'all' ? 'selected' : '' }}>Tout le temps</option>
                        </select>
                        @error('time_filter') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="min_score" class="block text-sm font-medium text-gray-700 mb-1">Score minimum</label>
                        <input type="number" name="min_score" id="min_score" value="{{ old('min_score', $redditSource->min_score) }}" min="0"
                               placeholder="0"
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 text-sm">
                        @error('min_score') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Test connexion --}}
                <div class="mt-4">
                    <button type="button" @click="testConnection()" :disabled="testing"
                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-orange-600 bg-orange-50 rounded-xl hover:bg-orange-100 transition-colors disabled:opacity-50">
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
                        <p class="text-sm text-green-700 font-medium">Connexion réussie</p>
                        <p class="text-xs text-green-600 mt-1" x-text="testResult?.message || ''"></p>
                    </div>
                    <div x-show="testError" x-transition class="mt-3 rounded-xl bg-red-50 border border-red-200 p-4">
                        <p class="text-sm text-red-700" x-text="testError"></p>
                    </div>
                </div>
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
                                class="w-full rounded-xl border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 text-sm">
                            <option value="daily" {{ old('schedule_frequency', $redditSource->schedule_frequency) === 'daily' ? 'selected' : '' }}>Quotidien</option>
                            <option value="twice_weekly" {{ old('schedule_frequency', $redditSource->schedule_frequency) === 'twice_weekly' ? 'selected' : '' }}>2x par semaine</option>
                            <option value="weekly" {{ old('schedule_frequency', $redditSource->schedule_frequency) === 'weekly' ? 'selected' : '' }}>Hebdomadaire</option>
                            <option value="biweekly" {{ old('schedule_frequency', $redditSource->schedule_frequency) === 'biweekly' ? 'selected' : '' }}>Tous les 15 jours</option>
                            <option value="monthly" {{ old('schedule_frequency', $redditSource->schedule_frequency) === 'monthly' ? 'selected' : '' }}>Mensuel</option>
                        </select>
                        @error('schedule_frequency') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="schedule_time" class="block text-sm font-medium text-gray-700 mb-1">Heure de publication</label>
                        <input type="time" name="schedule_time" id="schedule_time" value="{{ old('schedule_time', $redditSource->schedule_time ?? '10:00') }}"
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 text-sm">
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
                <p class="text-xs text-gray-500 mb-4">Sélectionnez les comptes sociaux qui recevront les publications générées depuis ce subreddit.</p>

                <div class="space-y-3">
                    @foreach($accounts as $account)
                        <div class="border rounded-xl transition-colors"
                             :class="isSelected({{ $account->id }}) ? 'border-orange-200 bg-orange-50/30' : 'border-gray-200'">
                            <div class="flex items-center gap-3 p-3 cursor-pointer" @click="toggleAccount({{ $account->id }})">
                                <input type="checkbox" :checked="isSelected({{ $account->id }})" class="rounded border-gray-300 text-orange-600 shadow-sm focus:ring-orange-500" @click.stop="toggleAccount({{ $account->id }})">

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
                                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 text-xs">
                                                <option value="">Aucun</option>
                                                @foreach($personas as $persona)
                                                    <option value="{{ $persona->id }}">{{ $persona->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1">Fréquence</label>
                                            <select x-model="getAccount({{ $account->id }}).post_frequency"
                                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 text-xs">
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
                                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 text-xs">
                                        </div>
                                    </div>
                                    <div class="mt-3 flex items-center gap-2">
                                        <input type="checkbox" x-model="getAccount({{ $account->id }}).auto_post"
                                               class="rounded border-gray-300 text-orange-600 shadow-sm focus:ring-orange-500">
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
                <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $redditSource->is_active) ? 'checked' : '' }}
                       class="rounded border-gray-300 text-orange-600 shadow-sm focus:ring-orange-500">
                <label for="is_active" class="text-sm font-medium text-gray-700">Source active</label>
            </div>
        </div>

        {{-- Submit --}}
        <div class="mt-6 flex items-center gap-3">
            <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-orange-600 text-white text-sm font-medium rounded-xl hover:bg-orange-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                Enregistrer
            </button>
            <a href="{{ route('reddit-sources.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annuler</a>
        </div>
    </form>

@endsection
