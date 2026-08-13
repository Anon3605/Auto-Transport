@extends('layouts.admin')

@section('page_title', 'Services')

@section('breadcrumb')
    <li aria-current="page">Services</li>
@endsection

@section('content')
    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                What the company sells, and the pricing inputs the instant estimator uses.
                Services are edited, not created or deleted here — the seeder owns the set,
                and a slug is a public URL that should not be minted casually.
            </p>
        </div>
    </div>

    <section class="card card--flush">
        @if (count($services) === 0)
            @include('admin.partials.empty-state', [
                'icon' => '◧',
                'title' => 'No services',
                'text' => 'Run the catalog seeder to populate the service list.',
            ])
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Service</th>
                            <th scope="col">Category</th>
                            <th scope="col" class="num">Base</th>
                            <th scope="col" class="num">Per mile</th>
                            <th scope="col" class="num">Minimum</th>
                            <th scope="col">Transit</th>
                            <th scope="col">Rating</th>
                            <th scope="col">Visibility</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($services as $service)
                            <tr>
                                <td>
                                    <a class="table__primary" href="{{ route('admin.services.edit', $service) }}">
                                        {{ $service->name }}
                                    </a>
                                    <span class="table__sub mono">/{{ $service->slug }}</span>
                                </td>

                                <td>{{ $service->category?->name ?? '—' }}</td>

                                <td class="num money">{{ number_format($service->base_price_cents / 100, 2) }}</td>

                                {{-- Per-mile is stored in cents like everything else, so a
                                     sub-cent rate would need a different unit; none do today. --}}
                                <td class="num money">{{ number_format($service->price_per_mile_cents / 100, 2) }}</td>

                                <td class="num money">{{ number_format($service->min_price_cents / 100, 2) }}</td>

                                <td class="nowrap">
                                    @if ($service->transit_days_min || $service->transit_days_max)
                                        {{ $service->transit_days_min ?? '?' }}&ndash;{{ $service->transit_days_max ?? '?' }} days
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>

                                <td>
                                    @if ($service->rating_count > 0)
                                        @include('admin.partials.stars', [
                                            'value' => $service->rating_avg,
                                            'count' => $service->rating_count,
                                            'size' => 'sm',
                                            'showValue' => false,
                                        ])
                                    @else
                                        <span class="muted">No reviews</span>
                                    @endif
                                </td>

                                <td>
                                    @if ($service->is_active)
                                        <span class="badge badge--success">Active</span>
                                    @else
                                        <span class="badge badge--neutral">Hidden</span>
                                    @endif
                                    @if ($service->is_featured)
                                        <span class="badge badge--primary">Featured</span>
                                    @endif
                                </td>

                                <td class="nowrap">
                                    <a class="btn btn--secondary btn--sm" href="{{ route('admin.services.edit', $service) }}">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('admin.partials.pagination', ['paginator' => $services])
        @endif
    </section>

    <p class="form-hint">
        Rating averages are maintained from approved reviews and cannot be edited here.
        If one looks wrong, run <code class="mono">php artisan reviews:rebuild-aggregates</code>,
        which recomputes from source.
    </p>
@endsection
