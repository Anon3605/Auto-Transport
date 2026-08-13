<?php

namespace App\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\Service;
use App\Models\User;
use App\Services\QuoteEstimator;
use App\Support\Reference;
use Illuminate\Support\Facades\DB;

/**
 * Books a service straight from the app.
 *
 * The schema builds a booking out of an ACCEPTED QUOTE, not out of thin air
 * (docs/database-design.md §4.1), so this does not insert a bare booking row
 * even though bookings.quote_id is nullable. It writes the whole chain in one
 * transaction:
 *
 *      quote_request  (the intake record, append-only)
 *   -> quote          (version 1, the offer that was accepted)
 *   -> booking        (the shipment, with price and addresses snapshotted)
 *   -> booking_vehicles + an opening booking_event
 *
 * Skipping the first two links would leave every instantly-booked shipment with
 * no answer to "what were they quoted, and when" -- which is the exact question
 * §4.1 exists to protect, and it arrives from a chargeback rather than from
 * curiosity.
 *
 * PRICING CAVEAT, stated where it is implemented: the total here is the
 * AUTOMATED estimate. §7 is explicit that the estimate is not a binding quote.
 * Booking at it means the business is committing to a machine-generated number
 * before a human has seen the lane. That is why the booking opens at
 * `pending_payment` rather than `confirmed` -- staff still gate it, and the
 * timeline records that the price came from the estimator.
 */
class BookService
{
    public function __construct(private readonly QuoteEstimator $estimator) {}

    /**
     * @param  array<string, mixed>  $data  validated StoreBookingRequest payload
     */
    public function handle(User $user, Service $service, array $data): Booking
    {
        return DB::transaction(function () use ($user, $service, $data): Booking {
            $vehicles = $data['vehicles'];

            $lane = [
                'pickup_lat' => $data['pickup']['lat'] ?? null,
                'pickup_lng' => $data['pickup']['lng'] ?? null,
                'dropoff_lat' => $data['dropoff']['lat'] ?? null,
                'dropoff_lng' => $data['dropoff']['lng'] ?? null,
            ];

            $estimate = $this->estimator->estimate($service, $vehicles, null, $lane);

            // --- 1. intake -------------------------------------------------
            $quoteRequest = QuoteRequest::create([
                'reference' => Reference::forQuoteRequest(),
                'user_id' => $user->id,
                'service_id' => $service->id,
                'status' => 'accepted',
                'contact_name' => $user->name,
                'contact_email' => $user->email,
                'contact_phone' => $user->phone,
                'pickup_line1' => $data['pickup']['line1'] ?? null,
                'pickup_city' => $data['pickup']['city'],
                'pickup_state' => $data['pickup']['state'] ?? null,
                'pickup_postal_code' => $data['pickup']['postal_code'] ?? null,
                'pickup_country_code' => $data['pickup']['country_code'] ?? 'US',
                'pickup_location_type' => $data['pickup']['location_type'] ?? 'residential',
                'dropoff_line1' => $data['dropoff']['line1'] ?? null,
                'dropoff_city' => $data['dropoff']['city'],
                'dropoff_state' => $data['dropoff']['state'] ?? null,
                'dropoff_postal_code' => $data['dropoff']['postal_code'] ?? null,
                'dropoff_country_code' => $data['dropoff']['country_code'] ?? 'US',
                'dropoff_location_type' => $data['dropoff']['location_type'] ?? 'residential',
                'pickup_date_earliest' => $data['pickup_date_earliest'],
                'pickup_date_latest' => $data['pickup_date_latest'] ?? null,
                'dates_flexible' => $data['dates_flexible'] ?? false,
                'vehicle_count' => count($vehicles),
                'distance_miles' => $estimate['distance_miles'],
                'estimated_price_cents' => $estimate['cents'],
                'currency' => $estimate['currency'],
                'additional_notes' => $data['additional_notes'] ?? null,
                'source' => 'app',
            ]);

            foreach ($vehicles as $vehicle) {
                $quoteRequest->vehicles()->create([
                    'vehicle_type_id' => $vehicle['vehicle_type_id'] ?? null,
                    'year' => $vehicle['year'] ?? null,
                    'make' => $vehicle['make'] ?? null,
                    'model' => $vehicle['model'] ?? null,
                    'color' => $vehicle['color'] ?? null,
                    'vin' => $vehicle['vin'] ?? null,
                    'is_operable' => $vehicle['is_operable'] ?? true,
                    'is_modified' => $vehicle['is_modified'] ?? false,
                ]);
            }

            // --- 2. the offer ----------------------------------------------
            $depositPercent = (int) (\App\Models\Setting::get('pricing', 'deposit_percent', 20) ?? 20);
            $depositCents = intdiv($estimate['cents'] * $depositPercent, 100);
            $validityDays = (int) (\App\Models\Setting::get('pricing', 'quote_validity_days', 7) ?? 7);

            $quote = Quote::create([
                'reference' => Reference::forQuote(),
                'quote_request_id' => $quoteRequest->id,
                // issued_by stays null: no human issued this one, and attributing
                // it to the customer would corrupt "who quoted this" in the panel.
                'issued_by' => null,
                'version' => 1,
                'total_price_cents' => $estimate['cents'],
                'deposit_cents' => $depositCents,
                'currency' => $estimate['currency'],
                'status' => 'accepted',
                'valid_until' => now()->addDays($validityDays),
                'internal_notes' => 'Auto-issued from the in-app instant booking flow at the estimator price.',
                'sent_at' => now(),
                'responded_at' => now(),
            ]);

            // --- 3. the shipment -------------------------------------------
            /*
             * Addresses and price are flat COPIES, not foreign keys (§4.3). If the
             * customer edits their saved address next month, this record must keep
             * saying where the car actually went.
             */
            $booking = Booking::create([
                'booking_number' => Reference::forBooking(),
                'quote_id' => $quote->id,
                'quote_request_id' => $quoteRequest->id,
                'user_id' => $user->id,
                'service_id' => $service->id,
                'status' => BookingStatus::PendingPayment,

                'pickup_contact_name' => $data['pickup']['contact_name'] ?? $user->name,
                'pickup_contact_phone' => $data['pickup']['contact_phone'] ?? $user->phone,
                'pickup_line1' => $data['pickup']['line1'] ?? null,
                'pickup_city' => $data['pickup']['city'],
                'pickup_state' => $data['pickup']['state'] ?? null,
                'pickup_postal_code' => $data['pickup']['postal_code'] ?? null,
                'pickup_country_code' => $data['pickup']['country_code'] ?? 'US',
                'pickup_lat' => $data['pickup']['lat'] ?? null,
                'pickup_lng' => $data['pickup']['lng'] ?? null,

                'dropoff_contact_name' => $data['dropoff']['contact_name'] ?? $user->name,
                'dropoff_contact_phone' => $data['dropoff']['contact_phone'] ?? $user->phone,
                'dropoff_line1' => $data['dropoff']['line1'] ?? null,
                'dropoff_city' => $data['dropoff']['city'],
                'dropoff_state' => $data['dropoff']['state'] ?? null,
                'dropoff_postal_code' => $data['dropoff']['postal_code'] ?? null,
                'dropoff_country_code' => $data['dropoff']['country_code'] ?? 'US',
                'dropoff_lat' => $data['dropoff']['lat'] ?? null,
                'dropoff_lng' => $data['dropoff']['lng'] ?? null,

                'scheduled_pickup_date' => $data['pickup_date_earliest'],
                'distance_miles' => $estimate['distance_miles'],
                'total_price_cents' => $estimate['cents'],
                'deposit_cents' => $depositCents,
                'amount_paid_cents' => 0,
                'currency' => $estimate['currency'],
                'special_instructions' => $data['additional_notes'] ?? null,
            ]);

            foreach ($vehicles as $vehicle) {
                $booking->vehicles()->create([
                    'vehicle_type_id' => $vehicle['vehicle_type_id'] ?? null,
                    'year' => $vehicle['year'] ?? null,
                    'make' => $vehicle['make'] ?? null,
                    'model' => $vehicle['model'] ?? null,
                    'color' => $vehicle['color'] ?? null,
                    'vin' => $vehicle['vin'] ?? null,
                    'is_operable' => $vehicle['is_operable'] ?? true,
                ]);
            }

            /*
             * §4.8: a booking's history starts at its first event, not at its
             * first status change. Without this the timeline screen renders empty
             * for every new shipment and the audit trail has no origin row.
             */
            $booking->recordEvent('booked', [
                'to_status' => BookingStatus::PendingPayment->value,
                'description' => sprintf(
                    'Booked in the app against %s at the automated estimate (%s %s). Awaiting payment.',
                    $service->name,
                    $estimate['currency'],
                    number_format($estimate['cents'] / 100, 2),
                ),
                'is_customer_visible' => true,
            ]);

            return $booking;
        });
    }
}
