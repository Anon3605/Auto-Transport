<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public service catalog. Anonymous, cacheable, and the first thing the app asks
 * for on a cold start.
 */
class ServiceController extends Controller
{
    /**
     * Unpaginated on purpose: the catalog is a handful of rows (§6 seeds seven),
     * the client caches the whole list under queryKeys.services, and a paginated
     * catalog would mean the app cannot render a service picker without a loop.
     * services_active_sort_idx covers exactly this query.
     */
    public function index(): AnonymousResourceCollection
    {
        return ServiceResource::collection(
            Service::query()->active()->ordered()->get()
        );
    }

    /**
     * Bound on slug (Service::getRouteKeyName), which is how endpoints.ts
     * addresses services. Soft-deleted rows are excluded by the binding; a
     * deactivated one is not, so it is turned away here -- a service pulled from
     * sale must not remain quotable through a bookmarked URL.
     */
    public function show(Service $service): ServiceResource
    {
        abort_unless($service->is_active, Response::HTTP_NOT_FOUND, 'Resource not found.');

        return new ServiceResource($service);
    }
}
