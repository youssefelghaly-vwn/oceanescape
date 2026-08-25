{{-- resources/views/vendor/pagination/tailwind.blade.php

     Our own paginator markup, overriding `pagination::tailwind`.

     WHY THIS FILE HAS TO EXIST — IT IS NOT A COSMETIC PREFERENCE

     Tailwind v4 detects its own content automatically, and that detection SKIPS anything
     matched by .gitignore. `/vendor` is gitignored, so the utility classes that appear only
     inside Laravel's own paginator view (`leading-5`, `border-gray-300`, `rounded-l-md`, the
     whole gray scale, every dark: variant) were never compiled. The markup rendered; it just
     arrived with no styling — bare underlined links with no spacing, which reads as "there is
     no pagination on this screen".

     Keeping the view here fixes it at the root: resources/ is scanned, so these classes are
     always built. The alternative — pointing `@source` at the vendor directory — would compile
     Laravel's grays into a project whose palette is ink/tide/fog/brand, so it would look
     foreign on every admin table.

     Every paginated screen shares this: admin reservations, messages, business stays, guest
     photos and the audit trail. --}}

@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}"
         class="flex items-center justify-between gap-4">

        {{-- ------------------------------------------------------------ mobile --}}
        {{-- Numbered links are unusable at this width; prev/next is the whole control. --}}
        <div class="flex flex-1 items-center justify-between gap-2 sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="rounded-full bg-fog-100 px-4 py-2 font-mono text-[10px] uppercase tracking-wide text-tide-400">
                    {!! __('pagination.previous') !!}
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                   class="rounded-full bg-white px-4 py-2 font-mono text-[10px] uppercase tracking-wide text-tide-700 ring-1 ring-fog-300 transition hover:ring-brand-300">
                    {!! __('pagination.previous') !!}
                </a>
            @endif

            <span class="font-mono text-[10px] uppercase tracking-wide text-tide-500">
                {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
            </span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                   class="rounded-full bg-white px-4 py-2 font-mono text-[10px] uppercase tracking-wide text-tide-700 ring-1 ring-fog-300 transition hover:ring-brand-300">
                    {!! __('pagination.next') !!}
                </a>
            @else
                <span class="rounded-full bg-fog-100 px-4 py-2 font-mono text-[10px] uppercase tracking-wide text-tide-400">
                    {!! __('pagination.next') !!}
                </span>
            @endif
        </div>

        {{-- ----------------------------------------------------------- desktop --}}
        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between sm:gap-4">
            {{-- The count is half the point of a paginator: "25 of 4,000" is the difference
                 between a filter that worked and one that did nothing. --}}
            <p class="text-xs text-tide-600">
                Showing
                <span class="font-medium text-ink-900">{{ $paginator->firstItem() }}</span>
                to
                <span class="font-medium text-ink-900">{{ $paginator->lastItem() }}</span>
                of
                <span class="font-medium text-ink-900">{{ number_format($paginator->total()) }}</span>
            </p>

            <span class="inline-flex items-center gap-1">
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}"
                          class="grid h-8 w-8 place-items-center rounded-full text-tide-300">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                            <path d="M15 18l-6-6 6-6"/>
                        </svg>
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                       aria-label="{{ __('pagination.previous') }}"
                       class="grid h-8 w-8 place-items-center rounded-full text-tide-600 transition hover:bg-fog-100 hover:text-ink-900">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                            <path d="M15 18l-6-6 6-6"/>
                        </svg>
                    </a>
                @endif

                @foreach ($elements as $element)
                    {{-- Laravel hands us a mix: a string for the gap, an array of page => url. --}}
                    @if (is_string($element))
                        <span aria-disabled="true" class="px-1.5 font-mono text-[11px] text-tide-400">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page"
                                      class="grid h-8 min-w-8 place-items-center rounded-full bg-ink-900 px-2 font-mono text-[11px] font-medium text-white">
                                    {{ $page }}
                                </span>
                            @else
                                <a href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}"
                                   class="grid h-8 min-w-8 place-items-center rounded-full px-2 font-mono text-[11px] text-tide-700 transition hover:bg-fog-100 hover:text-ink-900">
                                    {{ $page }}
                                </a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                       aria-label="{{ __('pagination.next') }}"
                       class="grid h-8 w-8 place-items-center rounded-full text-tide-600 transition hover:bg-fog-100 hover:text-ink-900">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                            <path d="M9 6l6 6-6 6"/>
                        </svg>
                    </a>
                @else
                    <span aria-disabled="true" aria-label="{{ __('pagination.next') }}"
                          class="grid h-8 w-8 place-items-center rounded-full text-tide-300">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                            <path d="M9 6l6 6-6 6"/>
                        </svg>
                    </span>
                @endif
            </span>
        </div>
    </nav>
@endif
