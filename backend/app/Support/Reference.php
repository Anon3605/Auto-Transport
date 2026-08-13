<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Human-facing identifiers -- the number a customer reads out over the phone.
 * Separate from the ULID on purpose (design doc §4.5): the ULID is the API key
 * and must be unguessable, this is short enough to dictate and scoped per year
 * so the counter restarts every January.
 */
class Reference
{
    public static function forQuoteRequest(): string
    {
        return self::next('quote_requests', 'reference', 'QR', 7);
    }

    public static function forQuote(): string
    {
        return self::next('quotes', 'reference', 'Q', 7);
    }

    public static function forBooking(): string
    {
        return self::next('bookings', 'booking_number', 'BK', 6);
    }

    /**
     * The sequence is derived from the table rather than stored in a counter
     * row, so two submissions landing in the same millisecond can read the same
     * MAX. lockForUpdate() serialises the readers on engines that implement row
     * locks (SQLite ignores it -- it serialises writers at the file level
     * instead), and the column's UNIQUE index is the real guarantee: a losing
     * racer gets a QueryException the caller can retry, never two bookings
     * quietly sharing a number.
     */
    private static function next(string $table, string $column, string $prefix, int $width): string
    {
        $stem = sprintf('%s-%d-', $prefix, (int) now()->format('Y'));

        return DB::transaction(function () use ($table, $column, $stem, $width): string {
            $latest = DB::table($table)
                ->where($column, 'like', $stem.'%')
                ->lockForUpdate()
                ->max($column);

            // Zero padding is fixed-width, which is what makes the lexicographic
            // MAX above equal the numeric maximum.
            $sequence = $latest === null ? 0 : (int) substr((string) $latest, strlen($stem));

            return $stem.str_pad((string) ($sequence + 1), $width, '0', STR_PAD_LEFT);
        });
    }
}
