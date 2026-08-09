{{--
    App-wide pagination component — replaces Laravel's default Tailwind
    pagination view (vendor/laravel/framework/.../tailwind.blade.php),
    which lives OUTSIDE this project's own resources/ directory and was
    therefore never scanned by Tailwind's content-based CSS compiler (see
    tailwind.config.js's `content` glob) — every class it used silently
    never made it into the compiled CSS, so pagination rendered with zero
    styling everywhere it appeared. Living inside resources/views/ fixes
    that automatically, and this also matches the app's own surface/
    primary color tokens instead of Tailwind's generic gray/blue.
    Registered as the framework-wide default via Paginator::defaultView()
    in AppServiceProvider::boot() — every {{ '{{ $paginator->links() }}' }}
    call in the app picks this up with no per-page changes needed.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}"
        class="flex items-center justify-center gap-1 rounded-2xl border border-surface-200 bg-white px-3 py-2 text-sm w-fit mx-auto">
        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-surface-300 cursor-default select-none" aria-disabled="true">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                {{ __('Previous') }}
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-surface-600 hover:bg-surface-50 transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                {{ __('Previous') }}
            </a>
        @endif

        <div class="flex items-center gap-1">
            @foreach ($elements as $element)
                {{-- "Three Dots" Separator --}}
                @if (is_string($element))
                    <span class="w-8 h-8 flex items-center justify-center text-surface-400 select-none">{{ $element }}</span>
                @endif

                {{-- Array Of Links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page"
                                class="w-8 h-8 flex items-center justify-center rounded-lg border-2 border-surface-800 font-semibold text-surface-900">
                                {{ $page }}
                            </span>
                        @else
                            <a href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}"
                                class="w-8 h-8 flex items-center justify-center rounded-lg text-surface-600 hover:bg-surface-50 transition-colors">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </div>

        <div class="w-px h-5 bg-surface-200 mx-1"></div>

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-surface-600 hover:bg-surface-50 transition-colors">
                {{ __('Next') }}
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </a>
        @else
            <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-surface-300 cursor-default select-none" aria-disabled="true">
                {{ __('Next') }}
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </span>
        @endif
    </nav>
@endif
