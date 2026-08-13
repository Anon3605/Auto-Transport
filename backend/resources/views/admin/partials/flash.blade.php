{{--
    Flash + validation region.

    The wrapper is always present and always live: an aria-live region that is
    injected together with its message is not reliably announced, so the empty
    container ships on every page and only its contents change. Icons are
    decorative -- each alert states its nature in words as well as colour.

    @param bool $showErrors  set false where the form renders every message inline
--}}
<div class="flash-region" role="status" aria-live="polite">
    @if (session('status'))
        <div class="alert alert--success">
            <span class="alert__icon" aria-hidden="true">&#10003;</span>
            <div class="alert__body">
                <span class="sr-only">Success: </span>{{ session('status') }}
            </div>
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert--danger">
            <span class="alert__icon" aria-hidden="true">&#9888;</span>
            <div class="alert__body">
                <span class="sr-only">Error: </span>{{ session('error') }}
            </div>
        </div>
    @endif

    @if (($showErrors ?? true) && $errors->any())
        <div class="alert alert--warning">
            <span class="alert__icon" aria-hidden="true">&#9888;</span>
            <div class="alert__body">
                <p class="alert__title">
                    Nothing was saved &mdash; {{ $errors->count() }}
                    {{ Str::plural('field', $errors->count()) }}
                    {{ $errors->count() === 1 ? 'needs' : 'need' }} attention.
                </p>
                <ul>
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
</div>
