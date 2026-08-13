@extends('layouts.admin')

@section('page_title', 'Review by ' . $review->author_name)

@section('breadcrumb')
    <li><a href="{{ route('admin.reviews.index') }}">Reviews</a></li>
    <li aria-current="page">{{ Str::limit($review->title ?: 'Untitled review', 32) }}</li>
@endsection

@section('content')
    @php
        $subRatings = [
            'Communication' => $review->rating_communication,
            'Timeliness' => $review->rating_timeliness,
            'Condition' => $review->rating_condition,
            'Value' => $review->rating_value,
        ];
        $canModerate = auth()->user()?->can('moderate_reviews') ?? false;
    @endphp

    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                <span class="ref">{{ $review->ulid }}</span>
                submitted {{ $review->created_at?->format('j M Y H:i') }}
            </p>
        </div>
        <div class="page-head__actions">
            <a class="btn btn--ghost" href="{{ route('admin.reviews.index') }}">Back to queue</a>
        </div>
    </div>

    <div class="split">
        <div class="stack">
            <section class="card">
                <div class="card__head">
                    <h2 class="card__title">The review</h2>
                    <div class="card__actions">
                        @include('admin.partials.status-badge', ['status' => $review->status, 'size' => 'lg'])
                    </div>
                </div>
                <div class="card__body">
                    <div class="stack">
                        <div class="row">
                            @include('admin.partials.stars', [
                                'value' => $review->rating_overall,
                                'size' => 'lg',
                                'showValue' => true,
                            ])
                            @if ($review->is_featured)
                                <span class="badge badge--primary">Featured</span>
                            @endif
                            <span class="badge {{ $review->is_verified ? 'badge--success' : 'badge--warning' }}">
                                {{ $review->is_verified ? 'Verified purchase' : 'Unverified' }}
                            </span>
                        </div>

                        @if ($review->title)
                            <h3>{{ $review->title }}</h3>
                        @endif

                        @if ($review->body)
                            <blockquote class="quote-block">{{ $review->body }}</blockquote>
                        @else
                            <p class="muted small">Rating only &mdash; the customer left no written feedback.</p>
                        @endif

                        @if (collect($subRatings)->filter(fn ($v) => $v !== null)->isNotEmpty())
                            <div>
                                <div class="section-head">
                                    <h3>Category ratings</h3>
                                    <span class="section-head__rule" aria-hidden="true"></span>
                                    @if ($review->averageSubRating() !== null)
                                        <span class="chip">Avg {{ number_format($review->averageSubRating(), 2) }}</span>
                                    @endif
                                </div>
                                <div class="rating-grid">
                                    @foreach ($subRatings as $label => $score)
                                        @if ($score !== null)
                                            <div class="rating-grid__item">
                                                <span class="rating-grid__label">{{ $label }}</span>
                                                @include('admin.partials.stars', [
                                                    'value' => $score,
                                                    'size' => 'sm',
                                                    'showValue' => true,
                                                ])
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card__head">
                    <h2 class="card__title">Public reply</h2>
                    @if ($review->admin_replied_at)
                        <div class="card__actions">
                            <span class="chip">
                                {{ $review->adminRepliedBy?->name ?? 'Staff' }},
                                {{ $review->admin_replied_at->format('j M Y') }}
                            </span>
                        </div>
                    @endif
                </div>
                <div class="card__body">
                    <div class="stack">
                        @if ($review->admin_reply)
                            <blockquote class="quote-block quote-block--reply">{{ $review->admin_reply }}</blockquote>
                        @endif

                        @if ($canModerate)
                            <form method="POST" action="{{ route('admin.reviews.reply', $review) }}" class="stack-sm">
                                @csrf
                                <div class="form-field @error('admin_reply') has-error @enderror">
                                    <label for="admin_reply">
                                        {{ $review->admin_reply ? 'Revise the reply' : 'Write a reply' }}
                                    </label>
                                    <textarea class="textarea" name="admin_reply" id="admin_reply" rows="4"
                                              placeholder="Published alongside the review on the public site."
                                              @error('admin_reply') aria-invalid="true" aria-describedby="admin-reply-error" @enderror>{{ old('admin_reply', $review->admin_reply) }}</textarea>
                                    <p class="form-hint">Customers see this. Keep it short and specific.</p>
                                    @error('admin_reply')
                                        <p class="form-error" id="admin-reply-error">
                                            <span aria-hidden="true">&#9888;</span><span>{{ $message }}</span>
                                        </p>
                                    @enderror
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn--primary">
                                        {{ $review->admin_reply ? 'Update reply' : 'Publish reply' }}
                                    </button>
                                </div>
                            </form>
                        @elseif (! $review->admin_reply)
                            <p class="small muted">No reply yet.</p>
                        @endif
                    </div>
                </div>
            </section>
        </div>

        <div class="stack">
            @if ($canModerate)
                <section class="card">
                    <div class="card__head">
                        <h2 class="card__title">Decision</h2>
                    </div>
                    <div class="card__body">
                        <div class="stack-sm">
                            @if ($review->status !== \App\Enums\ReviewStatus::Approved)
                                <form method="POST" action="{{ route('admin.reviews.approve', $review) }}">
                                    @csrf
                                    <button type="submit" class="btn btn--success btn--block">Approve &amp; publish</button>
                                </form>
                            @else
                                <p class="small muted">
                                    Approved{{ $review->moderated_at ? ' ' . $review->moderated_at->diffForHumans() : '' }}
                                    @if ($review->moderatedBy)
                                        by {{ $review->moderatedBy->name }}
                                    @endif
                                    &mdash; it is counting toward public rating averages.
                                </p>
                            @endif

                            @if ($review->status !== \App\Enums\ReviewStatus::Rejected)
                                <details class="drawer">
                                    <summary>Reject this review</summary>
                                    <div class="drawer__body">
                                        <form method="POST" action="{{ route('admin.reviews.reject', $review) }}"
                                              class="stack-sm">
                                            @csrf
                                            <div class="form-field @error('reason') has-error @enderror">
                                                <label for="reason">
                                                    Reason <span class="form-required" aria-hidden="true">*</span>
                                                </label>
                                                <textarea class="textarea" name="reason" id="reason" rows="3"
                                                          maxlength="255" required
                                                          @error('reason') aria-invalid="true" aria-describedby="reason-error" @enderror>{{ old('reason') }}</textarea>
                                                <p class="form-hint">Internal record, 255 characters max.</p>
                                                @error('reason')
                                                    <p class="form-error" id="reason-error">
                                                        <span aria-hidden="true">&#9888;</span><span>{{ $message }}</span>
                                                    </p>
                                                @enderror
                                            </div>
                                            <button type="submit" class="btn btn--danger btn--block">Reject review</button>
                                        </form>
                                    </div>
                                </details>
                            @elseif ($review->rejection_reason)
                                <div class="alert alert--danger">
                                    <span class="alert__icon" aria-hidden="true">&#9888;</span>
                                    <div class="alert__body">
                                        <p class="alert__title">Rejected</p>
                                        {{ $review->rejection_reason }}
                                    </div>
                                </div>
                            @endif

                            <form method="POST" action="{{ route('admin.reviews.feature', $review) }}">
                                @csrf
                                <button type="submit" class="btn btn--secondary btn--block">
                                    {{ $review->is_featured ? 'Remove from featured' : 'Feature on the homepage' }}
                                </button>
                            </form>
                        </div>
                    </div>
                </section>
            @endif

            <section class="card">
                <div class="card__head">
                    <h2 class="card__title">Context</h2>
                </div>
                <div class="card__body">
                    <dl class="kv">
                        <dt>Reviewer</dt>
                        <dd>
                            {{ $review->author_name }}
                            @can('view_users')
                                @if ($review->user)
                                    <span class="table__sub">
                                        <a href="{{ route('admin.users.show', $review->user) }}">{{ $review->user->email }}</a>
                                    </span>
                                @endif
                            @endcan
                        </dd>

                        <dt>Booking</dt>
                        <dd>
                            @if ($review->booking)
                                @can('view_bookings')
                                    <a class="mono" href="{{ route('admin.bookings.show', $review->booking) }}">
                                        {{ $review->booking->booking_number }}
                                    </a>
                                @else
                                    <span class="mono">{{ $review->booking->booking_number }}</span>
                                @endcan
                                <span class="table__sub">
                                    Delivered {{ $review->booking->actual_delivery_at?->format('j M Y') ?? '—' }}
                                </span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </dd>

                        <dt>Service</dt>
                        <dd>{{ $review->service?->name ?? '—' }}</dd>

                        <dt>Carrier</dt>
                        <dd>{{ $review->carrier?->company_name ?? '—' }}</dd>

                        <dt>Driver</dt>
                        <dd>{{ $review->driver?->name ?? '—' }}</dd>

                        <dt>Helpful votes</dt>
                        <dd class="num">{{ number_format((int) $review->helpful_count) }}</dd>

                        <dt>Moderated</dt>
                        <dd>
                            @if ($review->moderated_at)
                                {{ $review->moderated_at->format('j M Y H:i') }}
                                <span class="table__sub">{{ $review->moderatedBy?->name ?? 'Unknown' }}</span>
                            @else
                                <span class="muted">Not yet</span>
                            @endif
                        </dd>

                        <dt>Submitted from</dt>
                        <dd><span class="mono">{{ $review->ip_address ?: '—' }}</span></dd>
                    </dl>
                </div>
            </section>
        </div>
    </div>
@endsection
