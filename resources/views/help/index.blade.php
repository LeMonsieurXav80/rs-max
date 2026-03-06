@extends('layouts.app')

@section('title', 'Aide')

@section('content')
    <div class="help-layout" x-data="helpToc()" x-init="init()">
        {{-- Sidebar TOC (desktop) --}}
        <nav class="help-toc hidden lg:block">
            <div class="help-toc-inner">
                <p class="help-toc-title">Sommaire</p>
                <ul class="help-toc-list" id="help-toc-list"></ul>
            </div>
        </nav>

        {{-- Mobile TOC toggle --}}
        <div class="lg:hidden mb-4">
            <button @click="mobileOpen = !mobileOpen" class="w-full flex items-center justify-between bg-white rounded-xl border border-gray-200 px-4 py-3 text-sm font-medium text-gray-700">
                <span>Sommaire</span>
                <svg :class="mobileOpen && 'rotate-180'" class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="mobileOpen" x-collapse class="bg-white rounded-b-xl border border-t-0 border-gray-200 px-4 py-3">
                <ul class="help-toc-list" id="help-toc-list-mobile"></ul>
            </div>
        </div>

        {{-- Content --}}
        <div class="help-main">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 lg:p-10">
                <div class="help-content" id="help-content">
                    {!! $content !!}
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Layout */
        .help-layout { display: flex; gap: 2rem; align-items: flex-start; max-width: 80rem; }
        .help-main { flex: 1; min-width: 0; }

        /* TOC sidebar */
        .help-toc { width: 15rem; flex-shrink: 0; position: sticky; top: 1.5rem; max-height: calc(100vh - 3rem); overflow-y: auto; }
        .help-toc-inner { background: #fff; border: 1px solid #e5e7eb; border-radius: 0.75rem; padding: 1rem 0; }
        .help-toc-title { font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af; padding: 0 1rem; margin-bottom: 0.5rem; }
        .help-toc-list { list-style: none; padding: 0; margin: 0; }
        .help-toc-list li { margin: 0; }
        .help-toc-list a { display: block; padding: 0.3rem 1rem; font-size: 0.8125rem; color: #6b7280; text-decoration: none; border-left: 2px solid transparent; transition: all 0.15s; line-height: 1.4; }
        .help-toc-list a:hover { color: #4338ca; background: #f5f3ff; }
        .help-toc-list a.active { color: #4338ca; border-left-color: #4338ca; background: #f5f3ff; font-weight: 500; }
        .help-toc-list a.toc-h3 { padding-left: 1.75rem; font-size: 0.75rem; }
        .help-toc::-webkit-scrollbar { width: 3px; }
        .help-toc::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }

        /* Content styles */
        .help-content { color: #1f2937; line-height: 1.7; }
        .help-content h1 { font-size: 1.875rem; font-weight: 700; color: #111827; margin-bottom: 0.5rem; padding-bottom: 0.75rem; border-bottom: 2px solid #e5e7eb; }
        .help-content h2 { font-size: 1.375rem; font-weight: 600; color: #4338ca; margin-top: 2.5rem; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e5e7eb; scroll-margin-top: 1.5rem; }
        .help-content h3 { font-size: 1.125rem; font-weight: 600; color: #1f2937; margin-top: 1.75rem; margin-bottom: 0.75rem; scroll-margin-top: 1.5rem; }
        .help-content h4 { font-size: 1rem; font-weight: 600; color: #374151; margin-top: 1.25rem; margin-bottom: 0.5rem; }
        .help-content p { margin-bottom: 1rem; }
        .help-content a { color: #4f46e5; text-decoration: underline; text-decoration-color: #c7d2fe; text-underline-offset: 2px; }
        .help-content a:hover { color: #4338ca; text-decoration-color: #4338ca; }
        .help-content strong { font-weight: 600; color: #111827; }
        .help-content em { font-style: italic; }
        .help-content blockquote { border-left: 4px solid #6366f1; background: #eef2ff; padding: 0.75rem 1rem; margin: 1rem 0; border-radius: 0 0.5rem 0.5rem 0; color: #3730a3; }
        .help-content blockquote p { margin-bottom: 0; }
        .help-content hr { border: none; border-top: 1px solid #e5e7eb; margin: 2rem 0; }

        /* Lists */
        .help-content ul { list-style-type: disc; padding-left: 1.5rem; margin-bottom: 1rem; }
        .help-content ol { list-style-type: decimal; padding-left: 1.5rem; margin-bottom: 1rem; }
        .help-content li { margin-bottom: 0.35rem; }
        .help-content li ul, .help-content li ol { margin-top: 0.35rem; margin-bottom: 0.35rem; }

        /* Tables */
        .help-content table { width: 100%; border-collapse: collapse; margin: 1rem 0 1.5rem; font-size: 0.875rem; }
        .help-content thead th { background: #f9fafb; text-align: left; padding: 0.625rem 0.75rem; font-weight: 600; color: #374151; border-bottom: 2px solid #e5e7eb; }
        .help-content tbody td { padding: 0.5rem 0.75rem; border-bottom: 1px solid #f3f4f6; color: #4b5563; }
        .help-content tbody tr:hover { background: #f9fafb; }

        /* Code */
        .help-content code { background: #f3f4f6; color: #dc2626; padding: 0.15rem 0.4rem; border-radius: 0.25rem; font-size: 0.8125rem; font-family: ui-monospace, SFMono-Regular, monospace; }
        .help-content pre { background: #1e293b; color: #e2e8f0; padding: 1rem 1.25rem; border-radius: 0.5rem; overflow-x: auto; margin: 1rem 0 1.5rem; font-size: 0.8125rem; line-height: 1.6; }
        .help-content pre code { background: transparent; color: inherit; padding: 0; border-radius: 0; font-size: inherit; }

        /* Hide the inline TOC from the markdown (we build it dynamically in the sidebar) */
        .help-content > ol:first-of-type { display: none; }
    </style>

    <script>
        function helpToc() {
            return {
                mobileOpen: false,
                headings: [],
                activeId: null,
                init() {
                    const content = document.getElementById('help-content');
                    const headings = content.querySelectorAll('h2, h3');
                    const tocDesktop = document.getElementById('help-toc-list');
                    const tocMobile = document.getElementById('help-toc-list-mobile');

                    headings.forEach((h, i) => {
                        // Ensure each heading has an id for anchor links
                        if (!h.id) {
                            h.id = 'section-' + i;
                        }
                        this.headings.push({ id: h.id, tag: h.tagName.toLowerCase() });

                        const li = document.createElement('li');
                        const a = document.createElement('a');
                        a.href = '#' + h.id;
                        a.textContent = h.textContent;
                        a.dataset.id = h.id;
                        if (h.tagName === 'H3') a.classList.add('toc-h3');
                        a.addEventListener('click', (e) => {
                            e.preventDefault();
                            document.getElementById(h.id).scrollIntoView({ behavior: 'smooth' });
                            this.mobileOpen = false;
                        });
                        li.appendChild(a);
                        tocDesktop.appendChild(li);

                        // Clone for mobile
                        const liMobile = li.cloneNode(true);
                        liMobile.querySelector('a').addEventListener('click', (e) => {
                            e.preventDefault();
                            document.getElementById(h.id).scrollIntoView({ behavior: 'smooth' });
                            this.mobileOpen = false;
                        });
                        tocMobile.appendChild(liMobile);
                    });

                    // Scroll spy
                    const mainArea = document.querySelector('main') || document.documentElement;
                    const scrollTarget = mainArea.closest('[class*="overflow"]') || window;

                    const observer = new IntersectionObserver((entries) => {
                        entries.forEach(entry => {
                            if (entry.isIntersecting) {
                                this.activeId = entry.target.id;
                                document.querySelectorAll('.help-toc-list a').forEach(a => {
                                    a.classList.toggle('active', a.dataset.id === this.activeId);
                                });
                            }
                        });
                    }, { rootMargin: '-10% 0px -80% 0px' });

                    headings.forEach(h => observer.observe(h));
                }
            };
        }
    </script>
@endsection
