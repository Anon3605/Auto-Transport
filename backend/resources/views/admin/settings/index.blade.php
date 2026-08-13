@extends('layouts.admin')

@section('page_title', 'Settings')

@section('breadcrumb')
    <li aria-current="page">Settings</li>
@endsection

@section('content')
    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                Runtime configuration. Keys are created by the seeder — a setting nothing
                reads is dead weight, so this page edits values rather than inventing rows.
            </p>
        </div>
    </div>

    @if ($settings->isEmpty())
        <section class="card">
            @include('admin.partials.empty-state', [
                'icon' => '⚙',
                'title' => 'No settings',
                'text' => 'Run the content seeder to populate the default configuration keys.',
            ])
        </section>
    @else
        <form method="POST" action="{{ route('admin.settings.update') }}" class="stack">
            @csrf
            @method('PUT')

            @foreach ($settings as $group => $rows)
                <section class="card">
                    <div class="card__head">
                        <h2 class="card__title">{{ Str::headline($group) }}</h2>
                    </div>
                    <div class="card__body">
                        <div class="form-grid">
                            @foreach ($rows as $setting)
                                @php
                                    // Keyed by primary key, not "group.key": a key may itself
                                    // contain a dot (map.center.lat), and a dot in a validation
                                    // attribute name means "nested array".
                                    $name = "settings[{$setting->id}]";
                                    $id = "setting-{$setting->id}";
                                    $label = $setting->label ?: $setting->key;
                                    $error = $errors->first("settings.{$setting->id}");
                                    $value = old("settings.{$setting->id}", $setting->value);
                                @endphp

                                <div class="form-field {{ in_array($setting->type, ['json', 'text'], true) ? 'form-field--full' : '' }}">
                                    <label class="form-label" for="{{ $id }}">
                                        {{ $label }}
                                        @if ($setting->is_public)
                                            {{-- Public keys are served unauthenticated at
                                                 /api/v1/settings/public -- never put a secret in one. --}}
                                            <span class="badge badge--info">Public</span>
                                        @endif
                                    </label>

                                    @if ($setting->type === 'bool')
                                        {{-- Hidden 0 immediately before the checkbox: an unchecked
                                             box sends nothing, and "absent" would be ambiguous
                                             against "explicitly false" in the request handler. --}}
                                        <input type="hidden" name="{{ $name }}" value="0">
                                        <label class="check">
                                            <input type="checkbox" name="{{ $name }}" id="{{ $id }}" value="1"
                                                   @checked(filter_var($value, FILTER_VALIDATE_BOOLEAN))>
                                            <span class="check__text">Enabled</span>
                                        </label>

                                    @elseif ($setting->type === 'json')
                                        <textarea class="textarea mono" name="{{ $name }}" id="{{ $id }}"
                                                  rows="6" maxlength="65000"
                                                  spellcheck="false">{{ $value }}</textarea>
                                        <p class="form-hint">Must be valid JSON.</p>

                                    @elseif ($setting->type === 'encrypted')
                                        {{-- The stored secret is never rendered back into the DOM.
                                             Blank means "leave it alone" -- writing '' would break
                                             an integration and leave no way to tell an emptied key
                                             from an unset one. --}}
                                        <input class="input" type="password" name="{{ $name }}" id="{{ $id }}"
                                               autocomplete="new-password" placeholder="••••••••">
                                        <p class="form-hint">
                                            Stored encrypted and never shown. Leave blank to keep the current value.
                                        </p>

                                    @elseif ($setting->type === 'int')
                                        <input class="input num" type="number" step="1" name="{{ $name }}"
                                               id="{{ $id }}" value="{{ $value }}">

                                    @else
                                        <input class="input" type="text" name="{{ $name }}" id="{{ $id }}"
                                               maxlength="65000" value="{{ $value }}">
                                    @endif

                                    @if ($error)
                                        <p class="form-error">{{ $error }}</p>
                                    @endif

                                    <p class="form-hint mono tiny">{{ $setting->group }}.{{ $setting->key }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endforeach

            <section class="card">
                <div class="card__body">
                    <div class="form-actions">
                        <button type="submit" class="btn btn--primary">Save settings</button>
                        <a class="btn btn--ghost" href="{{ route('admin.dashboard') }}">Cancel</a>
                    </div>
                </div>
            </section>
        </form>
    @endif
@endsection
