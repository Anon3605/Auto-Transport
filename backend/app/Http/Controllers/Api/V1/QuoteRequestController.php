<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EstimateRequest;
use App\Http\Requests\Api\StoreQuoteRequestRequest;
use App\Http\Resources\QuoteRequestResource;
use App\Models\QuoteRequest;
use App\Models\Service;
use App\Services\QuoteEstimator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The lead pipeline's intake end: an instant §7 estimate, the guest-allowed
 * submission, and the customer's read-only view of what they sent.
 *
 * Nothing here issues a price the company is bound by. A binding offer is a
 * `quotes` row written by a human (§4.1); estimated_price is decoration with a
 * caveat attached.
 */
class QuoteRequestController extends Controller
{
    use AuthorizesRequests;

    private const PER_PAGE = 15;

    public function __construct(private readonly QuoteEstimator $estimator)
    {
    }

    /**
     * "My requests". Scoped by user_id, so a guest lead claimed later (§4.10)
     * appears here the moment the claim runs -- and one that was never claimed
     * appears for nobody.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QuoteRequest::class);

        $requests = QuoteRequest::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return QuoteRequestResource::collection($requests);
    }

    public function show(QuoteRequest $quoteRequest): QuoteRequestResource
    {
        $this->authorize('view', $quoteRequest);

        return new QuoteRequestResource($quoteRequest);
    }

    /**
     * The instant estimate. Nothing is persisted -- this is the number the quote
     * form shows while the customer is still typing, so it must stay a pure
     * function of the request plus the service's own pricing row.
     */
    public function estimate(EstimateRequest $request): JsonResponse
    {
        $service = $request->service();
        $vehicles = $request->vehicles();

        $estimate = $this->estimator->estimate(
            $service,
            $vehicles,
            $request->distanceMiles(),
            $request->lane(),
        );

        return response()->json([
            'data' => [
                'service' => [
                    'id' => (int) $service->id,
                    'name' => $service->name,
                    'slug' => $service->slug,
                ],
                'estimated_price' => [
                    'cents' => $estimate['cents'],
                    'currency' => $estimate['currency'],
                ],
                'distance_miles' => $estimate['distance_miles'],
                'vehicle_count' => count($vehicles),
                'transit_days_min' => $service->transit_days_min,
                'transit_days_max' => $service->transit_days_max,
                // §7: store it, show it, caveat it -- and never call it a quote.
                'is_binding' => false,
                'disclaimer' => 'This is an estimate, not a quote. Final pricing is confirmed by our team.',
            ],
        ]);
    }

    /**
     * Guest-allowed intake (§4.10). Rate limiting belongs on the route.
     *
     * The request object owns the nested-payload -> flat-column mapping; this
     * method owns everything the payload must NOT be trusted for: who is calling,
     * from where, on what, and what the lane is worth.
     */
    public function store(StoreQuoteRequestRequest $request): JsonResponse
    {
        $this->authorize('create', QuoteRequest::class);

        $attributes = $request->quoteRequestAttributes();
        $service = $attributes['service_id'] === null
            ? null
            : Service::query()->find($attributes['service_id']);

        $lane = $request->lane();
        $vehicleRows = $request->vehicleRows();

        $estimate = $service === null
            ? null
            : $this->estimator->estimate($service, $vehicleRows, null, $lane);

        $quoteRequest = DB::transaction(function () use ($attributes, $estimate, $lane, $request, $vehicleRows): QuoteRequest {
            $quoteRequest = new QuoteRequest($attributes);

            // Server-side attribution and abuse forensics. Read off the request,
            // never off the payload: a client that could set its own source or IP
            // would make the spam triage in the admin queue worthless.
            $quoteRequest->source = 'mobile';
            $quoteRequest->ip_address = $request->ip();
            $quoteRequest->user_agent = $this->clamp($request->userAgent(), 512);
            $quoteRequest->user_id = $request->user()?->id;

            if ($estimate !== null) {
                $quoteRequest->estimated_price_cents = $estimate['cents'];
                $quoteRequest->currency = $estimate['currency'];

                /**
                 * distance_miles is a CACHED ROUTING RESULT (§7), so it is only
                 * written when the mileage was actually derived from coordinates.
                 * QuoteEstimator's flat fallback is a guess; storing it would make
                 * a made-up lane length look like a computed one and would stop
                 * the real Distance Matrix call from ever filling the column in.
                 */
                if ($this->laneIsGeocoded($lane)) {
                    $quoteRequest->distance_miles = $estimate['distance_miles'];
                }
            }

            $quoteRequest->save();

            // One at a time through the relation: QuoteRequestVehicle's created
            // hook is what keeps quote_requests.vehicle_count honest, and a bulk
            // insert would silently skip it.
            foreach ($vehicleRows as $row) {
                $quoteRequest->vehicles()->create($row);
            }

            // That hook writes vehicle_count with a raw builder update, which
            // leaves this instance stale -- refresh before it is serialised.
            return $quoteRequest->refresh();
        });

        return (new QuoteRequestResource($quoteRequest))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** @param array<string, float|null> $lane */
    private function laneIsGeocoded(array $lane): bool
    {
        foreach (['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng'] as $key) {
            if (($lane[$key] ?? null) === null) {
                return false;
            }
        }

        return true;
    }

    private function clamp(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
