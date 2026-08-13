@extends('layouts.admin')

@section('page_title', 'Reviews')

@section('breadcrumb')
    <li aria-current="page">Reviews</li>
@endsection

@section('content')
    @php
        $current = (string) request('status', '');

        /*
         * Tabs are driven by ReviewStatus so a new case shows up here without an
         * edit. $counts is keyed by status value; a missing key means zero. The
         * All total is summed from the cases only, so a controller that also
         * ships an 'all' key does not get counted twice.
         */
        $perStatus = collect(\App\Enums\ReviewStatus::cases())
            ->mapWithKeys(fn ($case): array => [$case->value => (int) ($counts[$case->value] ?? 0)]);

        $tabs = collect([[
            'value' => '',
            'label' => 'All',
            'count' => (int) ($counts['all'] ?? $perStatus->sum()),
        ]])->concat(collect(\App\Enums\ReviewStatus::cases())->map(fn ($case): array => [
            'value' => $case->value,
            'label' => Str::headline($case->value),
            'count' => $perStatus[$case->value],
        ]));

        $canModerate = auth()->user()?->can('moderate_reviews') ?? false;
    @endphp

    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                Nothing reaches the public site or a rating average until it is approved here.
            </p>
        </div>
    </div>

    <section class="card card--flush">
        <nav class="tabs" aria-label="Filter reviews by status">
            @foreach ($tabs as $tab)
                <a class="tab"
                   href="{{ route('admin.reviews.index', $tab['value'] === '' ? [] : ['status' => $tab['value']]) }}"
                   @if ($current === $tab['value']) aria-current="page" @endif>
                    {{ $tab['label'] }}
                    <span class="tab__count">{{ number_format($tab['count']) }}</span>
                </a>
            @endforeach
        </nav>

        @if (count($reviews) === 0)
            @include('admin.partials.empty-state', [
                'icon' => '★',
                'title' => $current === 'pending' ? 'Queue is clear' : 'No reviews here',
                'text' => $current === 'pending'
                    ? 'Every review has been decided. New submissions land in this tab.'
                    : 'Nothing matches this filter yet.',
                'actionUrl' => $current !== '' ? route('admin.reviews.index') : null,
                'actionLabel' => 'Show all reviews',
            ])
        @else
            <ul class="review-list">
                @foreach ($reviews as $review)
                    <li class="review-item">
                        <div class="review-item__main">
                            <div class="review-item__head">
                                @include('admin.partials.stars', [
                                    'value' => $review->rating_overall,
                                    'size' => 'lg',
                                    'showValue' => true,
                                ])

                                <a class="review-item__title" href="{{ route('admin.reviews.show', $review) }}">
                                    {{ $review->title ?: 'Untitled review' }}
                                </a>

                                @include('admin.partials.status-badge', ['status' => $review->status])

                                @if ($review->is_featured)
                                    <span class="badge badge--primary">Featured</span>
                                @endif
                                @if (! $review->is_verified)
                                    <span class="badge badge--warning">Unverified</span>
                                @endif
                                @if ($review->admin_reply)
                                    <span class="chip">Replied</span>
                                @endif
                            </div>

                            @if ($review->body)
                                <p class="review-item__body clamp-3">{{ Str::limit($review->body, 320) }}</p>
                            @else
                                <p class="review-item__body muted">Rating only &mdash; no written feedback.</p>
                            @endif

                            <p class="review-item__foot">
                                <span class="strong">{{ $review->author_name }}</span>
                                @if ($review->service)
                                    <span>{{ $review->service->name }}</span>
                                @endif
                                @can('view_bookings')
                                    @if ($review->booking)
                                        <a class="mono" href="{{ route('admin.bookings.show', $review->booking) }}">
                                            {{ $review->booking->booking_number }}
                                        </a>
                                    @endif
                                @endcan
                                <span title="{{ $review->created_at }}">{{ $review->created_at?->diffForHumans() }}</span>
                                @if ($review->helpful_count > 0)
                                    <span>{{ number_format($review->helpful_count) }} found this helpful</span>
                                @endif
                            </p>
                        </div>

                        <div class="review-item__side">
                            @if ($canModerate)
                                <div class="moderation-actions">
                                    @if ($review->status !== \App\Enums\ReviewStatus::Approved)
                                        <form method="POST" action="{{ route('admin.reviews.approve', $review) }}">
                                            @csrf
                                            <button type="submit" class="btn btn--outline-success btn--sm">Approve</button>
                                        </form>
                                    @endif

                                    @if ($review->status !== \App\Enums\ReviewStatus::Rejected)
                                        {{-- A disclosure, not a modal: rejection needs a reason and
                                             this has to work with JavaScript off. --}}
                                        <details class="drawer drawer--inline">
                                            <summary>Reject</summary>
                                            <div class="drawer__body">
                                                <form method="POST" action="{{ route('admin.reviews.reject', $review) }}"
                                                      class="stack-sm">
                                                    @csrf
                                                    <div class="form-field">
                                                        <label for="reason-{{ $review->ulid }}">
                                                            Reason <span class="form-required" aria-hidden="true">*</span>
                                                        </label>
                                                        <textarea class="textarea" name="reason"
                                                                  id="reason-{{ $review->ulid }}"
                                                                  rows="3" maxlength="255" required
                                                                  placeholder="Kept on the record; not shown to the customer."></textarea>
                                                        <p class="form-hint">255 characters max.</p>
                                                    </div>
                                                    <div class="form-actions">
                                                        <button type="submit" class="btn btn--danger btn--sm">
                                                            Reject review
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </details>
                                    @endif

                                    <form method="POST" action="{{ route('admin.reviews.feature', $review) }}">
                                        @csrf
                                        <button type="submit" class="btn btn--ghost btn--sm">
                                            {{ $review->is_featured ? 'Unfeature' : 'Feature' }}
                                        </button>
                                    </form>
                                </div>
                            @endif

                            <a class="btn btn--secondary btn--sm" href="{{ route('admin.reviews.show', $review) }}">
                                Open
                            </a>
                        </div>
                    </li>
                @endforeach
            </ul>

            @include('admin.partials.pagination', ['paginator' => $reviews])
        @endif
    </section>
@endsection
