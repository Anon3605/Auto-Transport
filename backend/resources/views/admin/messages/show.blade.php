@extends('layouts.admin')

@section('page_title', $message->subject ?: 'Message')

@section('breadcrumb')
    <li><a href="{{ route('admin.messages.index') }}">Messages</a></li>
    <li aria-current="page">{{ Str::limit($message->subject ?: $message->name, 40) }}</li>
@endsection

@section('content')
    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                @include('admin.partials.status-badge', ['status' => $message->status, 'size' => 'lg'])
                <span>Received {{ $message->created_at?->format('j M Y H:i') }}</span>
            </p>
        </div>
    </div>

    <div class="split">
        <div class="main-col stack">
            <section class="card">
                <div class="card__head">
                    <h2 class="card__title">{{ $message->subject ?: 'No subject' }}</h2>
                </div>
                <div class="card__body">
                    <blockquote class="quote-block">{{ $message->message }}</blockquote>
                </div>
            </section>

            @if ($message->reply_body)
                <section class="card">
                    <div class="card__head"><h2 class="card__title">Reply on record</h2></div>
                    <div class="card__body">
                        <blockquote class="quote-block quote-block--reply">{{ $message->reply_body }}</blockquote>
                        <p class="tiny muted">
                            {{ $message->repliedBy?->name ?? 'Unknown' }},
                            {{ $message->replied_at?->format('j M Y H:i') }}
                        </p>
                    </div>
                </section>
            @endif

            @can('manage_contact_messages')
                <section class="card">
                    <div class="card__head">
                        <h2 class="card__title">{{ $message->reply_body ? 'Revise reply' : 'Reply' }}</h2>
                    </div>
                    <div class="card__body">
                        <form method="POST" action="{{ route('admin.messages.reply', $message) }}" class="stack-sm">
                            @csrf
                            <div class="form-field">
                                <label class="form-label" for="reply_body">
                                    Message <span class="form-required" aria-hidden="true">*</span>
                                </label>
                                <textarea class="textarea @error('reply_body') input--error @enderror"
                                          name="reply_body" id="reply_body" rows="6"
                                          minlength="3" maxlength="5000" required>{{ old('reply_body', $message->reply_body) }}</textarea>
                                @error('reply_body')
                                    <p class="form-error">{{ $message }}</p>
                                @enderror

                                {{--
                                    Stated plainly because the opposite assumption is dangerous:
                                    saving here writes the audit trail, it does not send an email.
                                    Delivery is a Mailable on a queue, and a half-wired send from a
                                    controller fails silently on a host with no mailer configured.
                                --}}
                                <p class="form-hint">
                                    Saved to the record for the customer's file. This does <strong>not</strong>
                                    send an email — delivery is not wired up. Send from your mail client and
                                    keep the text here so the next agent sees what was said.
                                </p>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary">Save reply</button>
                                <a class="btn btn--ghost" href="{{ route('admin.messages.index') }}">Back to inbox</a>
                            </div>
                        </form>
                    </div>
                </section>
            @endcan
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2 class="card__title">Sender</h2></div>
                <div class="card__body">
                    <dl class="kv">
                        <dt>Name</dt>
                        <dd>{{ $message->name }}</dd>
                        <dt>Email</dt>
                        <dd class="truncate"><a href="mailto:{{ $message->email }}">{{ $message->email }}</a></dd>
                        <dt>Phone</dt>
                        <dd>
                            @if ($message->phone)
                                <a href="tel:{{ $message->phone }}">{{ $message->phone }}</a>
                            @else — @endif
                        </dd>
                        <dt>Account</dt>
                        <dd>
                            @if ($message->user)
                                @can('view_users')
                                    <a href="{{ route('admin.users.show', $message->user) }}">{{ $message->user->name }}</a>
                                @else
                                    {{ $message->user->name }}
                                @endcan
                            @else
                                <span class="chip">Not signed in</span>
                            @endif
                        </dd>
                        <dt>Assigned to</dt>
                        <dd>{{ $message->assignedTo?->name ?? 'Nobody' }}</dd>
                    </dl>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2 class="card__title">Provenance</h2></div>
                <div class="card__body">
                    <dl class="kv">
                        <dt>Referrer</dt>
                        <dd class="truncate tiny">{{ $message->referrer ?: '—' }}</dd>
                        @if ($message->spam_score)
                            <dt>Spam score</dt>
                            <dd>{{ $message->spam_score }}</dd>
                        @endif
                        <dt>IP</dt>
                        <dd class="mono tiny">{{ $message->ip_address ?? '—' }}</dd>
                    </dl>
                    <p class="form-hint">
                        IP and user agent are kept for abuse forensics. They are personal data —
                        prune them on a retention schedule rather than accumulating them.
                    </p>
                </div>
            </section>
        </div>
    </div>
@endsection
