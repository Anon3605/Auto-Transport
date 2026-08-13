{{--
    Zero-results placeholder.

    Two situations read very differently to an operator -- "there is no work
    waiting" versus "your filter hid everything" -- so the caller supplies the
    wording and, where it helps, a way out.

    @param string  $title
    @param string  $text        optional
    @param string  $icon        optional glyph, decorative (a literal character,
                                not an HTML entity -- it is echoed escaped)
    @param string  $actionUrl   optional
    @param string  $actionLabel optional, required with $actionUrl
--}}
<div class="empty-state">
    <span class="empty-state__icon" aria-hidden="true">{{ $icon ?? '○' }}</span>

    <p class="empty-state__title">{{ $title ?? 'Nothing here yet' }}</p>

    @if (! empty($text))
        <p class="empty-state__text">{{ $text }}</p>
    @endif

    @if (! empty($actionUrl) && ! empty($actionLabel))
        <p class="empty-state__action">
            <a class="btn btn--secondary" href="{{ $actionUrl }}">{{ $actionLabel }}</a>
        </p>
    @endif
</div>
