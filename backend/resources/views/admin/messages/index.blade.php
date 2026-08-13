@extends('layouts.admin')

@section('page_title', 'Contact messages')

@section('breadcrumb')
    <li aria-current="page">Messages</li>
@endsection

@section('content')
    @php
        $current = (string) request('status', '');

        // $counts is keyed by status value and may also carry 'all'. Summing the
        // known statuses rather than trusting a total keeps the tabs honest if a
        // status is added without the controller being updated.
        $tabs = collect([[
            'value' => '',
            'label' => 'All',
            'count' => (int) ($counts['all'] ?? collect($statuses)->sum(fn ($s) => (int) ($counts[$s] ?? 0))),
        ]])->concat(collect($statuses)->map(fn ($status): array => [
            'value' => $status,
            'label' => Str::headline($status),
            'count' => (int) ($counts[$status] ?? 0),
        ]));
    @endphp

    <div class="page-head">
        <div class="page-head__text">
            <p class="page-head__sub">
                Enquiries from the website contact form. Opening one marks it read.
            </p>
        </div>
    </div>

    <section class="card card--flush">
        <nav class="tabs" aria-label="Filter messages by status">
            @foreach ($tabs as $tab)
                <a class="tab"
                   href="{{ route('admin.messages.index', $tab['value'] === '' ? [] : ['status' => $tab['value']]) }}"
                   @if ($current === $tab['value']) aria-current="page" @endif>
                    {{ $tab['label'] }}
                    <span class="tab__count">{{ number_format($tab['count']) }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('admin.messages.index') }}" class="filter-bar">
            @if ($current !== '')
                <input type="hidden" name="status" value="{{ $current }}">
            @endif

            <div class="form-field">
                <label class="form-label" for="filter-q">Search</label>
                <input class="input" type="search" name="q" id="filter-q"
                       value="{{ request('q') }}"
                       placeholder="Name, email, subject">
            </div>

            <div class="filter-bar__actions">
                <button type="submit" class="btn btn--primary">Search</button>
                @if (request('q'))
                    <a class="btn btn--ghost"
                       href="{{ route('admin.messages.index', $current === '' ? [] : ['status' => $current]) }}">Clear</a>
                @endif
            </div>
        </form>

        @if (count($messages) === 0)
            @include('admin.partials.empty-state', [
                'icon' => '✉',
                'title' => $current === 'new' ? 'Inbox is clear' : 'No messages here',
                'text' => $current === 'new'
                    ? 'Every enquiry has been opened. New ones arrive in this tab.'
                    : 'Nothing matches this filter.',
                'actionUrl' => ($current !== '' || request('q')) ? route('admin.messages.index') : null,
                'actionLabel' => 'Show all messages',
            ])
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">From</th>
                            <th scope="col">Subject</th>
                            <th scope="col">Status</th>
                            <th scope="col">Assigned</th>
                            <th scope="col">Received</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($messages as $message)
                            <tr>
                                <td>
                                    <a class="table__primary" href="{{ route('admin.messages.show', $message) }}">
                                        {{ $message->name }}
                                    </a>
                                    <span class="table__sub truncate">{{ $message->email }}</span>
                                </td>

                                <td>
                                    <span class="table__primary">{{ $message->subject ?: 'No subject' }}</span>
                                    <span class="table__sub clamp-2">{{ Str::limit($message->message, 120) }}</span>
                                </td>

                                <td>
                                    @include('admin.partials.status-badge', ['status' => $message->status])
                                    @if ($message->replied_at)
                                        <span class="table__sub">Replied {{ $message->replied_at->diffForHumans() }}</span>
                                    @endif
                                </td>

                                <td>{{ $message->assignedTo?->name ?? '—' }}</td>

                                <td class="nowrap" title="{{ $message->created_at }}">
                                    {{ $message->created_at?->diffForHumans() }}
                                </td>

                                <td class="nowrap">
                                    <a class="btn btn--secondary btn--sm" href="{{ route('admin.messages.show', $message) }}">
                                        Open
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('admin.partials.pagination', ['paginator' => $messages])
        @endif
    </section>
@endsection
