{{--
    Status pill. $status is a backed enum instance or a raw string.

    BookingStatus already owns label()/color(), so defer to it whenever the
    methods exist -- duplicating that mapping here would let the badge drift
    from the domain. The other status enums (Quote, QuoteRequest, Review,
    Payment) carry no presentation methods, so their raw values fall through to
    the map below. Every value used by any of them is covered; anything
    unrecognised degrades to neutral rather than throwing.

    @param mixed  $status  enum|string|null
    @param string $size    '' | 'lg'
--}}
@php
    $raw = $status instanceof \BackedEnum ? (string) $status->value : (string) ($status ?? '');

    $label = is_object($status) && method_exists($status, 'label')
        ? $status->label()
        : \Illuminate\Support\Str::headline($raw);

    $color = is_object($status) && method_exists($status, 'color')
        ? $status->color()
        : match ($raw) {
            'approved', 'accepted', 'captured', 'delivered', 'active', 'published', 'replied' => 'success',
            'confirmed', 'assigned', 'reviewing', 'sent', 'viewed', 'read', 'authorized' => 'info',
            'quoted', 'picked_up', 'in_transit' => 'primary',
            'pending', 'pending_payment', 'new', 'draft', 'refunded' => 'warning',
            'rejected', 'declined', 'cancelled', 'failed', 'disputed', 'spam', 'suspended' => 'danger',
            default => 'neutral',
        };
@endphp

@if ($raw === '')
    <span class="muted" aria-label="No status recorded">&mdash;</span>
@else
    <span class="badge badge--{{ $color }}{{ ($size ?? '') === 'lg' ? ' badge--lg' : '' }}">{{ $label }}</span>
@endif
