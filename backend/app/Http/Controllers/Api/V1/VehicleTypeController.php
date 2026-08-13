<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleTypeResource;
use App\Models\VehicleType;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The vehicle picker on the quote form. Public, tiny, unpaginated -- see the
 * note in ServiceController::index.
 */
class VehicleTypeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return VehicleTypeResource::collection(
            VehicleType::query()->active()->ordered()->get()
        );
    }
}
