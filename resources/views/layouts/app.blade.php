<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Dashboard') - {{ config('app.name', 'RS-Max') }}</title>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gray-50 text-gray-900" x-data="{ sidebarOpen: false }">
        <div class="min-h-screen flex">
            {{-- Mobile sidebar overlay --}}
            <div
                x-show="sidebarOpen"
                x-transition:enter="transition-opacity ease-linear duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-linear duration-300"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 z-40 bg-gray-600/50 lg:hidden"
                @click="sidebarOpen = false"
            ></div>

            {{-- Sidebar --}}
            <aside
                :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
                class="fixed inset-y-0 left-0 z-50 w-64 bg-white border-r border-gray-200 flex flex-col transition-transform duration-300 ease-in-out lg:translate-x-0"
            >
                {{-- Logo --}}
                <div class="flex items-center h-16 px-6 border-b border-gray-100">
                    <a href="{{ url('/dashboard') }}" class="text-xl font-bold text-indigo-600 tracking-tight">
                        RS-Max
                    </a>
                </div>

                {{-- Navigation --}}
                <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto">
                    @php
                        $currentRoute = request()->path();
                    @endphp

                    {{-- Nouveau post --}}
                    <a href="{{ route('posts.create') }}"
                       class="flex items-center gap-3 px-3 py-2.5 mb-3 rounded-xl text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700 transition-colors shadow-sm">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Nouveau post
                    </a>

                    {{-- Dashboard --}}
                    <a href="{{ url('/dashboard') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ $currentRoute === 'dashboard' ? 'bg-indigo-50 text-indigo-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        Dashboard
                    </a>

                    {{-- Publications --}}
                    <a href="{{ url('/posts') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ str_starts_with($currentRoute, 'posts') ? 'bg-indigo-50 text-indigo-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                        </svg>
                        Publications
                    </a>

                    {{-- Médias --}}
                    <a href="{{ url('/media') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ $currentRoute === 'media' ? 'bg-indigo-50 text-indigo-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                        </svg>
                        Médias
                    </a>

                    {{-- Comptes sociaux --}}
                    <a href="{{ url('/accounts') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ str_starts_with($currentRoute, 'accounts') ? 'bg-indigo-50 text-indigo-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                        Comptes sociaux
                    </a>

                    {{-- Statistiques --}}
                    <a href="{{ route('stats.dashboard') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ str_starts_with($currentRoute, 'stats') ? 'bg-indigo-50 text-indigo-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                        </svg>
                        Statistiques
                    </a>

                    {{-- Personas (admin, global - utilisable par RSS et d'autres features) --}}
                    @if(auth()->user()->is_admin)
                    <a href="{{ url('/personas') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ str_starts_with($currentRoute, 'personas') ? 'bg-indigo-50 text-indigo-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                        </svg>
                        Personas
                    </a>
                    @endif

                    {{-- Sources de contenu (collapsible) --}}
                    @if(auth()->user()->is_admin)
                    <div x-data="{ rssOpen: {{ str_starts_with($currentRoute, 'rss') ? 'true' : 'false' }} }">
                        <button
                            @click="rssOpen = !rssOpen"
                            class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ str_starts_with($currentRoute, 'rss') ? 'text-indigo-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}"
                        >
                            <span class="flex items-center gap-3">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12.75 19.5v-.75a7.5 7.5 0 0 0-7.5-7.5H4.5m0-6.75h.75c7.87 0 14.25 6.38 14.25 14.25v.75M6 18.75a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                                </svg>
                                Sources de contenu
                            </span>
                            <svg class="w-4 h-4 transition-transform" :class="rssOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div x-show="rssOpen" x-collapse class="ml-5 mt-1 space-y-0.5 border-l border-gray-200 pl-3">
                            <a href="{{ url('/rss-feeds') }}"
                               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors {{ str_starts_with($currentRoute, 'rss-feeds') ? 'bg-indigo-50 text-indigo-600 font-medium' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' }}">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12.75 19.5v-.75a7.5 7.5 0 0 0-7.5-7.5H4.5m0-6.75h.75c7.87 0 14.25 6.38 14.25 14.25v.75M6 18.75a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                                </svg>
                                Flux RSS/XML
                            </a>
                        </div>
                    </div>
                    @endif

                    {{-- Plateformes (collapsible with sub-menus) --}}
                    <div x-data="{ platformsOpen: {{ str_starts_with($currentRoute, 'platforms') ? 'true' : 'false' }} }">
                        <button
                            @click="platformsOpen = !platformsOpen"
                            class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ str_starts_with($currentRoute, 'platforms') ? 'text-indigo-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}"
                        >
                            <span class="flex items-center gap-3">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 16.875h3.375m0 0h3.375m-3.375 0V13.5m0 3.375v3.375M6 10.5h2.25a2.25 2.25 0 0 0 2.25-2.25V6a2.25 2.25 0 0 0-2.25-2.25H6A2.25 2.25 0 0 0 3.75 6v2.25A2.25 2.25 0 0 0 6 10.5Zm0 9.75h2.25A2.25 2.25 0 0 0 10.5 18v-2.25a2.25 2.25 0 0 0-2.25-2.25H6a2.25 2.25 0 0 0-2.25 2.25V18A2.25 2.25 0 0 0 6 20.25Zm9.75-9.75H18a2.25 2.25 0 0 0 2.25-2.25V6A2.25 2.25 0 0 0 18 3.75h-2.25A2.25 2.25 0 0 0 13.5 6v2.25a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                Plateformes
                            </span>
                            <svg class="w-4 h-4 transition-transform" :class="platformsOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div x-show="platformsOpen" x-collapse class="ml-5 mt-1 space-y-0.5 border-l border-gray-200 pl-3">
                            {{-- Facebook / Instagram --}}
                            <a href="{{ url('/platforms/facebook') }}"
                               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors {{ $currentRoute === 'platforms/facebook' ? 'bg-indigo-50 text-indigo-600 font-medium' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' }}">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                                </svg>
                                Facebook / Instagram
                            </a>

                            {{-- Telegram --}}
                            <a href="{{ url('/platforms/telegram') }}"
                               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors {{ $currentRoute === 'platforms/telegram' ? 'bg-indigo-50 text-indigo-600 font-medium' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' }}">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                                </svg>
                                Telegram
                            </a>

                            {{-- Threads --}}
                            <a href="{{ url('/platforms/threads') }}"
                               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors {{ $currentRoute === 'platforms/threads' ? 'bg-indigo-50 text-indigo-600 font-medium' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' }}">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.509 5.467l-2.04.569c-1.104-3.96-3.898-5.984-8.304-6.015-2.91.022-5.11.936-6.54 2.717C4.307 6.504 3.616 8.914 3.59 12c.025 3.086.718 5.496 2.057 7.164 1.432 1.783 3.631 2.698 6.54 2.717 2.623-.02 4.358-.631 5.8-2.045 1.647-1.613 1.618-3.593 1.09-4.798-.31-.71-.873-1.3-1.634-1.75-.192 1.352-.622 2.446-1.284 3.272-.886 1.102-2.14 1.704-3.73 1.79-1.202.065-2.361-.218-3.259-.801-1.063-.689-1.685-1.74-1.752-2.96-.065-1.17.408-2.253 1.33-3.05.81-.7 1.91-1.12 3.192-1.216 1.074-.082 2.068.022 2.97.283-.039-1.31-.494-2.282-1.321-2.796-.573-.356-1.363-.54-2.281-.54l-.026.002c-1.378.014-2.396.454-3.024 1.305l-1.602-1.197C8.022 5.628 9.47 4.96 11.48 4.94l.04-.001c1.321 0 2.459.298 3.38.886 1.222.781 1.937 2.02 2.098 3.634.58.17 1.11.403 1.578.695 1.202.75 2.084 1.798 2.55 3.032.77 2.034.712 4.89-1.512 7.067-1.836 1.795-4.103 2.628-7.343 2.647l-.085.1zm-1.57-8.357c-1.452.111-2.458.784-2.396 1.896.026.472.258.863.673 1.13.553.357 1.287.508 2.063.472 1.083-.058 1.907-.455 2.449-1.18.392-.525.652-1.21.78-2.05-.876-.303-1.875-.38-2.857-.296l-.713.028z"/>
                                </svg>
                                Threads
                            </a>

                            {{-- Twitter / X --}}
                            <a href="{{ url('/platforms/twitter') }}"
                               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors {{ $currentRoute === 'platforms/twitter' ? 'bg-indigo-50 text-indigo-600 font-medium' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' }}">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.74 1.04.586 1.641a4.483 4.483 0 0 1-.923 1.785A5.969 5.969 0 0 0 6 21c1.282 0 2.47-.402 3.445-1.087.81.22 1.668.337 2.555.337Z" />
                                </svg>
                                Twitter / X
                            </a>

                            {{-- YouTube --}}
                            <a href="{{ url('/platforms/youtube') }}"
                               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors {{ $currentRoute === 'platforms/youtube' ? 'bg-indigo-50 text-indigo-600 font-medium' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' }}">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                </svg>
                                YouTube
                            </a>
                        </div>
                    </div>

                    {{-- Paramètres (admin only) --}}
                    @if(auth()->user()->is_admin)
                    <a href="{{ url('/settings') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ str_starts_with($currentRoute, 'settings') ? 'bg-indigo-50 text-indigo-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        Paramètres
                    </a>
                    @endif

                    {{-- Profil --}}
                    <a href="{{ url('/profile') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ str_starts_with($currentRoute, 'profile') ? 'bg-indigo-50 text-indigo-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                        </svg>
                        Profil
                    </a>
                </nav>

                {{-- User / Logout --}}
                <div class="px-4 py-4 border-t border-gray-100">
                    <div class="flex items-center justify-between px-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-sm font-semibold flex-shrink-0">
                                {{ substr(auth()->user()->name ?? 'U', 0, 1) }}
                            </div>
                            <span class="text-sm font-medium text-gray-700 truncate">
                                {{ auth()->user()->name ?? 'Utilisateur' }}
                            </span>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-gray-400 hover:text-gray-600 transition-colors" title="Se déconnecter">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>
            </aside>

            {{-- Main content --}}
            <div class="flex-1 flex flex-col min-h-screen lg:ml-64">
                {{-- Top header bar --}}
                <header class="sticky top-0 z-30 bg-white border-b border-gray-200">
                    <div class="flex items-center justify-between h-16 px-6 lg:px-8">
                        <div class="flex items-center gap-4">
                            {{-- Mobile hamburger --}}
                            <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden text-gray-500 hover:text-gray-700">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                </svg>
                            </button>
                            <h1 class="text-lg font-semibold text-gray-900">@yield('title', 'Dashboard')@yield('title_extra')</h1>
                        </div>
                        <div class="flex items-center gap-3">
                            @yield('actions')
                        </div>
                    </div>
                </header>

                {{-- Flash messages --}}
                @if(session('success'))
                    <div class="mx-6 lg:mx-8 mt-6" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)">
                        <div class="rounded-xl bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                            <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <p class="text-sm text-green-700">{{ session('success') }}</p>
                        </div>
                    </div>
                @endif

                @if(session('error'))
                    <div class="mx-6 lg:mx-8 mt-6" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)">
                        <div class="rounded-xl bg-red-50 border border-red-200 p-4 flex items-center gap-3">
                            <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                            <p class="text-sm text-red-700">{{ session('error') }}</p>
                        </div>
                    </div>
                @endif

                {{-- Page content --}}
                <main class="flex-1 p-6 lg:p-8">
                    @yield('content')
                </main>

                {{-- Footer with git version --}}
                @php
                    // 1. File from Docker build, 2. Env var (Coolify), 3. Local git
                    $versionFile = storage_path('app/git-version.txt');
                    $dateFile = storage_path('app/git-date.txt');

                    $gitCommit = null;
                    if (file_exists($versionFile) && trim(file_get_contents($versionFile)) !== 'unknown') {
                        $gitCommit = trim(file_get_contents($versionFile));
                    }
                    if (!$gitCommit && !empty(env('SOURCE_COMMIT'))) {
                        $gitCommit = substr(env('SOURCE_COMMIT'), 0, 7);
                    }
                    if (!$gitCommit) {
                        $gitCommit = trim(exec('git rev-parse --short HEAD 2>/dev/null') ?: '');
                    }

                    $gitDate = file_exists($dateFile)
                        ? trim(file_get_contents($dateFile))
                        : trim(exec('git log -1 --format=%ci 2>/dev/null') ?: '');
                @endphp
                @if($gitCommit)
                    <footer class="px-6 lg:px-8 pb-4">
                        <p class="text-xs text-gray-400 text-right">
                            v. {{ $gitCommit }}
                            @if($gitDate) &middot; {{ \Carbon\Carbon::parse($gitDate)->format('d/m/Y H:i') }} @endif
                        </p>
                    </footer>
                @endif
            </div>
        </div>

        {{-- Custom scripts from child views --}}
        @stack('scripts')
    </body>
</html>
