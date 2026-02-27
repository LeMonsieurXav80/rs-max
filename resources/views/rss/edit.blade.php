@extends('layouts.app')

@section('title', 'Modifier flux RSS')

@section('actions')
    <a href="{{ route('rss-feeds.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
        </svg>
        Retour
    </a>
@endsection

@section('content')

    <form method="POST" action="{{ route('rss-feeds.update', $rssFeed) }}" class="max-w-3xl"
          x-data="{
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
              }
          }">
        @csrf
        @method('PUT')

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-6">

            {{-- Infos du flux --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12.75 19.5v-.75a7.5 7.5 0 0 0-7.5-7.5H4.5m0-6.75h.75c7.87 0 14.25 6.38 14.25 14.25v.75M6 18.75a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                    </svg>
                    Informations du flux
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                        <input type="text" name="name" id="name" value="{{ old('name', $rssFeed->name) }}" required
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Catégorie</label>
                        <input type="text" name="category" id="category" value="{{ old('category', $rssFeed->category) }}"
                               class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        @error('category') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-4">
                    <label for="url" class="block text-sm font-medium text-gray-700 mb-1">URL du flux (RSS / XML / Atom) *</label>
                    <input type="url" name="url" id="url" value="{{ old('url', $rssFeed->url) }}" required
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    @error('url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="mt-4">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="description" id="description" value="{{ old('description', $rssFeed->description) }}"
                           class="w-full rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Multi-part sitemap --}}
                <div class="mt-4 flex items-center gap-3">
                    <input type="hidden" name="is_multi_part_sitemap" value="0">
                    <input type="checkbox" name="is_multi_part_sitemap" id="is_multi_part_sitemap" value="1"
                           {{ old('is_multi_part_sitemap', $rssFeed->is_multi_part_sitemap) ? 'checked' : '' }}
                           class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                    <div>
                        <label for="is_multi_part_sitemap" class="text-sm font-medium text-gray-700">Sitemap en plusieurs parties</label>
                        <p class="text-xs text-gray-400">Si l'URL pointe vers un sitemap numéroté (ex: post-sitemap1.xml), toutes les parties seront récupérées automatiquement.</p>
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
                <p class="text-xs text-gray-500 mb-4">Sélectionnez les comptes sociaux qui recevront les publications générées depuis ce flux.</p>

                <div class="space-y-3">
                    @foreach($accounts as $account)
                        <div class="border rounded-xl transition-colors"
                             :class="isSelected({{ $account->id }}) ? 'border-indigo-200 bg-indigo-50/30' : 'border-gray-200'">
                            {{-- Account row --}}
                            <div class="flex items-center gap-3 p-3 cursor-pointer" @click="toggleAccount({{ $account->id }})">
                                <input type="checkbox" :checked="isSelected({{ $account->id }})" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" @click.stop>

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

                            {{-- Settings --}}
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

                {{-- Hidden inputs --}}
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
                <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $rssFeed->is_active) ? 'checked' : '' }}
                       class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                <label for="is_active" class="text-sm font-medium text-gray-700">Flux actif</label>
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
            <a href="{{ route('rss-feeds.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annuler</a>
        </div>
    </form>

@endsection
