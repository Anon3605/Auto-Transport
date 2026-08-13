<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Catalog editing for the public services pages. Editing only: creating and
 * retiring services is a content decision with SEO consequences (a slug is a
 * URL), and the seeder owns the initial set.
 */
class ServiceController extends Controller
{
    private const PER_PAGE = 25;

    public function index(): View
    {
        return view('admin.services.index', [
            'services' => Service::query()
                ->with('category')
                ->withCount('approvedReviews')
                ->ordered()
                ->paginate(self::PER_PAGE),
        ]);
    }

    /** {service} resolves on the slug -- Service::getRouteKeyName() (no ulid column). */
    public function edit(Service $service): View
    {
        return view('admin.services.edit', [
            'service' => $service->load('category', 'seo'),
            'categories' => ServiceCategory::query()->ordered()->get(),
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        // validated() cannot carry rating_avg / rating_count -- they are not in
        // the rules and not in $fillable, so the aggregate stays the reviews
        // table's business (§4.7).
        $service->update($request->validated());

        activity()
            ->performedOn($service)
            ->causedBy($request->user())
            ->log("Service {$service->slug} updated");

        return redirect()
            ->route('admin.services.index')
            ->with('status', "{$service->name} was saved.");
    }
}
