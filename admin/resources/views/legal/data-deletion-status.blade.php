@php
    $brand = tva_setting('content.brand_name', 'Serve AI');
    $email = tva_setting('content.contact_email', 'info@serveai.com.pk');

    $tone = match ($deletion->status) {
        \Msd\MetaChannels\Models\DataDeletionRequest::STATUS_COMPLETED => ['#065f46', '#d1fae5', 'Completed'],
        \Msd\MetaChannels\Models\DataDeletionRequest::STATUS_FAILED    => ['#991b1b', '#fee2e2', 'Needs attention'],
        default                                                        => ['#92400e', '#fef3c7', 'In progress'],
    };
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'Legal',
    'pageTitle'       => 'Deletion Request Status',
    'pageSubtitle'    => 'The current state of your data deletion request.',
    'seoTitle'        => 'Deletion Request Status — ' . $brand,
    'metaDescription' => 'Check the status of a data deletion request.',
    // A status page keyed to one person's request has no business in search
    // results, and the code in the URL should not be crawlable.
    'pageNoindex'     => true,
    'breadcrumbs'     => [['name' => 'Data Deletion', 'url' => '/data-deletion']],
])

@section('content')
<section class="article">
    <div class="wrap">
        <div class="prose">
            <p style="display:inline-block;margin:0 0 1.25rem;padding:.35rem .85rem;border-radius:999px;font-size:.8125rem;font-weight:600;color:{{ $tone[0] }};background:{{ $tone[1] }};">
                {{ $tone[2] }}
            </p>

            <p class="lead">{{ $deletion->summary() }}</p>

            <table>
                <tbody>
                    <tr>
                        <th style="text-align:left;">Confirmation code</th>
                        <td><code>{{ $deletion->confirmation_code }}</code></td>
                    </tr>
                    <tr>
                        <th style="text-align:left;">Requested</th>
                        <td>{{ $deletion->created_at->toDayDateTimeString() }} UTC</td>
                    </tr>
                    @if ($deletion->completed_at)
                    <tr>
                        <th style="text-align:left;">Completed</th>
                        <td>{{ $deletion->completed_at->toDayDateTimeString() }} UTC</td>
                    </tr>
                    @endif
                </tbody>
            </table>

            @if ($deletion->status === \Msd\MetaChannels\Models\DataDeletionRequest::STATUS_COMPLETED)
                <div class="note">This is permanent. The conversations and messages listed above no longer exist in our systems and cannot be recovered.</div>
            @elseif ($deletion->status === \Msd\MetaChannels\Models\DataDeletionRequest::STATUS_FAILED)
                {{-- Deliberately no exception text here: this page is public,
                     and a raw error can leak table names and file paths. --}}
                <div class="note">Something went wrong on our side. The request has not been lost — it is flagged for a person to complete manually. Email <a href="mailto:{{ $email }}">{{ $email }}</a> quoting the code above if you would like an update.</div>
            @else
                <div class="note">Nothing further is needed from you. Refresh this page in a few minutes to see the result.</div>
            @endif

            <p>Full details of what is deleted and what is kept are on the <a href="{{ url('/data-deletion') }}">Data Deletion</a> page.</p>
        </div>
    </div>
</section>
@endsection
