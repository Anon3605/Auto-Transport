@extends('layouts.admin')

@section('page_title', 'Edit '.$service->name)

@section('breadcrumb')
    <li><a href="{{ route('admin.services.index') }}">Services</a></li>
    <li aria-current="page">{{ $service->name }}</li>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.services.update', $service) }}" class="stack">
        @csrf
        @method('PUT')

        <div class="split">
            <div class="main-col stack">
                <section class="card">
                    <div class="card__head"><h2 class="card__title">Details</h2></div>
                    <div class="card__body">
                        <div class="form-grid">
                            <div class="form-field form-field--full">
                                <label class="form-label" for="name">
                                    Name <span class="form-required" aria-hidden="true">*</span>
                                </label>
                                <input class="input" type="text" name="name" id="name" required
                                       minlength="2" maxlength="160"
                                       value="{{ old('name', $service->name) }}">
                                @error('name')<p class="form-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="form-field form-field--full">
                                <label class="form-label" for="slug">
                                    Slug <span class="form-required" aria-hidden="true">*</span>
                                </label>
                                <div class="input-affix">
                                    <span class="input-affix__tag">/services/</span>
                                    <input class="input mono" type="text" name="slug" id="slug" required
                                           maxlength="180" value="{{ old('slug', $service->slug) }}">
                                </div>
                                @error('slug')<p class="form-error">{{ $message }}</p>@enderror
                                {{-- A slug is a live URL. Changing it breaks inbound links and
                                     any ranking the page has accumulated. --}}
                                <p class="form-hint">
                                    This is a public URL. Changing it breaks existing links and loses
                                    accumulated search ranking — set up a redirect if you must.
                                </p>
                            </div>

                            <div class="form-field">
                                <label class="form-label" for="service_category_id">Category</label>
                                <select class="select" name="service_category_id" id="service_category_id">
                                    <option value="">Uncategorised</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}"
                                            @selected((int) old('service_category_id', $service->service_category_id) === $category->id)>
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('service_category_id')<p class="form-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="form-field">
                                <label class="form-label" for="icon">Icon</label>
                                <input class="input mono" type="text" name="icon" id="icon" maxlength="64"
                                       value="{{ old('icon', $service->icon) }}">
                                @error('icon')<p class="form-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="form-field form-field--full">
                                <label class="form-label" for="short_description">Short description</label>
                                <textarea class="textarea" name="short_description" id="short_description"
                                          rows="2" maxlength="320">{{ old('short_description', $service->short_description) }}</textarea>
                                @error('short_description')<p class="form-error">{{ $message }}</p>@enderror
                                <p class="form-hint">Used on cards and as the meta-description fallback.</p>
                            </div>

                            <div class="form-field form-field--full">
                                <label class="form-label" for="description">Full description</label>
                                <textarea class="textarea" name="description" id="description"
                                          rows="8" maxlength="65000">{{ old('description', $service->description) }}</textarea>
                                @error('description')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card__head">
                        <h2 class="card__title">Pricing</h2>
                        <p class="card__actions small muted">Feeds the instant estimator</p>
                    </div>
                    <div class="card__body">
                        {{--
                            Amounts are entered in minor units because that is exactly how they
                            are stored (§4.4). A dollars field would mean a float round-trip on
                            every save, and 0.1 has no exact binary representation -- the drift
                            shows up later as a reconciliation mismatch nobody can explain.
                        --}}
                        <p class="form-hint">
                            All amounts are in <strong>cents</strong>, matching how they are stored.
                            Entering dollars here would introduce a floating-point round-trip on every
                            save; the dollar equivalent is shown beneath each field.
                        </p>

                        <div class="form-grid">
                            @php
                                $priceFields = [
                                    'base_price_cents' => ['Base price', 'Flat starting price before distance.'],
                                    'price_per_mile_cents' => ['Per mile', 'Multiplied by the calculated distance.'],
                                    'min_price_cents' => ['Minimum', 'Floor — the estimate never comes out below this.'],
                                ];
                            @endphp

                            @foreach ($priceFields as $field => [$label, $hint])
                                <div class="form-field">
                                    <label class="form-label" for="{{ $field }}">
                                        {{ $label }} <span class="form-required" aria-hidden="true">*</span>
                                    </label>
                                    <input class="input num" type="number" name="{{ $field }}" id="{{ $field }}"
                                           required min="0" step="1"
                                           value="{{ old($field, $service->{$field}) }}">
                                    @error($field)<p class="form-error">{{ $message }}</p>@enderror
                                    <p class="form-hint">
                                        {{ $hint }}
                                        Currently {{ number_format((int) $service->{$field} / 100, 2) }}
                                        {{ $service->currency }}.
                                    </p>
                                </div>
                            @endforeach

                            <div class="form-field">
                                <label class="form-label" for="currency">
                                    Currency <span class="form-required" aria-hidden="true">*</span>
                                </label>
                                <input class="input mono" type="text" name="currency" id="currency" required
                                       size="3" maxlength="3" pattern="[A-Za-z]{3}"
                                       value="{{ old('currency', $service->currency) }}">
                                @error('currency')<p class="form-error">{{ $message }}</p>@enderror
                                <p class="form-hint">ISO 4217, three letters.</p>
                            </div>

                            <div class="form-field">
                                <label class="form-label" for="transit_days_min">Transit days (min)</label>
                                <input class="input num" type="number" name="transit_days_min" id="transit_days_min"
                                       min="0" max="65535" step="1"
                                       value="{{ old('transit_days_min', $service->transit_days_min) }}">
                                @error('transit_days_min')<p class="form-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="form-field">
                                <label class="form-label" for="transit_days_max">Transit days (max)</label>
                                <input class="input num" type="number" name="transit_days_max" id="transit_days_max"
                                       min="0" max="65535" step="1"
                                       value="{{ old('transit_days_max', $service->transit_days_max) }}">
                                @error('transit_days_max')<p class="form-error">{{ $message }}</p>@enderror
                                <p class="form-hint">Must be at least the minimum.</p>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="stack">
                <section class="card">
                    <div class="card__head"><h2 class="card__title">Visibility</h2></div>
                    <div class="card__body">
                        {{-- Hidden 0 before each checkbox: an unchecked box sends nothing at
                             all, which the boolean() cast would read as false anyway, but the
                             explicit pair keeps the intent obvious and survives a rules change. --}}
                        <div class="switch-row">
                            <input type="hidden" name="is_active" value="0">
                            <label class="check">
                                <input type="checkbox" name="is_active" value="1"
                                       @checked(old('is_active', $service->is_active))>
                                <span class="check__text">
                                    Active
                                    <span class="check__hint">Listed on the site and offered in the app.</span>
                                </span>
                            </label>
                        </div>

                        <div class="switch-row">
                            <input type="hidden" name="is_featured" value="0">
                            <label class="check">
                                <input type="checkbox" name="is_featured" value="1"
                                       @checked(old('is_featured', $service->is_featured))>
                                <span class="check__text">
                                    Featured
                                    <span class="check__hint">Promoted on the homepage.</span>
                                </span>
                            </label>
                        </div>

                        <div class="form-field">
                            <label class="form-label" for="sort_order">
                                Sort order <span class="form-required" aria-hidden="true">*</span>
                            </label>
                            <input class="input num" type="number" name="sort_order" id="sort_order"
                                   required min="0" max="65535" step="1"
                                   value="{{ old('sort_order', $service->sort_order) }}">
                            @error('sort_order')<p class="form-error">{{ $message }}</p>@enderror
                            <p class="form-hint">Lower sorts first.</p>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card__head"><h2 class="card__title">Reviews</h2></div>
                    <div class="card__body">
                        @if ($service->rating_count > 0)
                            @include('admin.partials.stars', [
                                'value' => $service->rating_avg,
                                'count' => $service->rating_count,
                                'showValue' => true,
                            ])
                        @else
                            <p class="muted">No approved reviews yet.</p>
                        @endif
                        {{-- Not a form field on purpose: the aggregate belongs to the reviews
                             table, and a hand-edited average is a lie with a number on it. --}}
                        <p class="form-hint">
                            Maintained from approved reviews and deliberately not editable.
                        </p>
                    </div>
                </section>

                <section class="card">
                    <div class="card__body">
                        <div class="form-actions">
                            <button type="submit" class="btn btn--primary btn--block">Save service</button>
                            <a class="btn btn--ghost btn--block" href="{{ route('admin.services.index') }}">Cancel</a>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </form>
@endsection
