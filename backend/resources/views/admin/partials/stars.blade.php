{{--
    0-5 star rating.

    The filled layer is a clipped overlay of the same glyphs, so any fraction
    renders -- including the half star -- without needing half-glyph assets.
    Width snaps to the nearest half for a crisp edge while the aria-label keeps
    the real number, because a screen reader should hear "4.3 out of 5", not a
    rounded lie or a run of star characters.

    @param mixed  $value  numeric (decimal casts arrive as strings)
    @param int    $count  optional review count to print after the glyphs
    @param string $size   '' | 'sm' | 'lg'
    @param bool   $showValue
--}}
@php
    $starValue = max(0.0, min(5.0, (float) ($value ?? 0)));
    $snapped = round($starValue * 2) / 2;
    $pct = ($snapped / 5) * 100;
    $starSize = ($size ?? '') !== '' ? ' stars--' . $size : '';
    $starCount = $count ?? null;
@endphp

<span class="rating">
    <span class="stars{{ $starSize }}" role="img"
          aria-label="{{ rtrim(rtrim(number_format($starValue, 1), '0'), '.') ?: '0' }} out of 5 stars">
        <span class="stars__track" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
        <span class="stars__fill" style="width: {{ $pct }}%" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
    </span>
    @if ($showValue ?? true)
        <span class="rating__value" aria-hidden="true">{{ number_format($starValue, 1) }}</span>
    @endif
    @if (! is_null($starCount))
        <span class="rating__count">({{ number_format((int) $starCount) }})</span>
    @endif
</span>
