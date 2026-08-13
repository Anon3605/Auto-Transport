{{--
    Paginator footer.

    withQueryString() is applied here rather than trusted to each controller:
    losing the active filters on page 2 is the classic admin-panel bug, and
    appends() deliberately skips the page parameter so re-applying is harmless.

    Falls back to prev/next only for a simple paginator, which knows neither its
    total nor its last page.

    @param \Illuminate\Contracts\Pagination\Paginator $paginator
--}}
@php
    $paginator = $paginator->withQueryString();
    $isLengthAware = $paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    $elements = [];
    if ($isLengthAware) {
        $window = \Illuminate\Pagination\UrlWindow::make($paginator);

        $elements = array_filter([
            $window['first'],
            is_array($window['slider']) ? '...' : null,
            $window['slider'],
            is_array($window['last']) ? '...' : null,
            $window['last'],
        ]);
    }
@endphp

@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pagination">
        <p class="pagination__summary">
            @if ($isLengthAware)
                Showing {{ number_format($paginator->firstItem() ?? 0) }}&ndash;{{ number_format($paginator->lastItem() ?? 0) }}
                of {{ number_format($paginator->total()) }}
            @else
                Page {{ number_format($paginator->currentPage()) }}
            @endif
        </p>

        <ul class="pagination__list">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="pagination__link pagination__link--disabled" aria-hidden="true">&lsaquo;</span>
                @else
                    <a class="pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev"
                       aria-label="Previous page">&lsaquo;</a>
                @endif
            </li>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="pagination__gap" aria-hidden="true">{{ $element }}</span></li>
                @else
                    @foreach ($element as $page => $url)
                        <li>
                            @if ($page == $paginator->currentPage())
                                <a class="pagination__link" href="{{ $url }}" aria-current="page"
                                   aria-label="Page {{ $page }}, current page">{{ $page }}</a>
                            @else
                                <a class="pagination__link" href="{{ $url }}"
                                   aria-label="Page {{ $page }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li>
                @if ($paginator->hasMorePages())
                    <a class="pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next"
                       aria-label="Next page">&rsaquo;</a>
                @else
                    <span class="pagination__link pagination__link--disabled" aria-hidden="true">&rsaquo;</span>
                @endif
            </li>
        </ul>
    </nav>
@elseif ($isLengthAware && $paginator->total() > 0)
    {{-- One page of results still deserves a count; dispatchers reconcile against it. --}}
    <div class="pagination">
        <p class="pagination__summary">
            {{ number_format($paginator->total()) }}
            {{ Str::plural('result', $paginator->total()) }}
        </p>
    </div>
@endif
