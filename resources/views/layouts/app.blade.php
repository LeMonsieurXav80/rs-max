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
                class="fixed inset-y-0 left-0 z-50 w-64 bg-white border-r border-gray-200 flex flex-col transition-transform duration-300 ease-in-out lg:translate-x-0 lg:static lg:z-auto"
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

                    {{-- Comptes sociaux --}}
                    <a href="{{ url('/accounts') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors {{ str_starts_with($currentRoute, 'accounts') ? 'bg-indigo-50 text-indigo-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                        Comptes sociaux
                    </a>

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
            <div class="flex-1 flex flex-col min-h-screen lg:ml-0">
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
                            <h1 class="text-lg font-semibold text-gray-900">@yield('title', 'Dashboard')</h1>
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
            </div>
        </div>
    </body>
</html>
