@extends('layouts.app')

@section('title', __('Advanced Search'))

@section('content')
<div class="section-heading">
    {{ __('Advanced Search') }}
</div>

<div class="row-container">
    <div class="row">
        <div class="col-xs-12">
            <form class="form-horizontal" method="GET" action="{{ route('improvedsearch.advanced') }}">

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Search Terms') }}</label>
                    <div class="col-sm-6">
                        <input type="text" class="form-control" name="q" value="{{ $query ?? '' }}" placeholder="{{ __('Enter search terms...') }}">
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Quick Date Filter') }}</label>
                    <div class="col-sm-6">
                        <select class="form-control" name="last">
                            <option value="">{{ __('-- Select --') }}</option>
                            <option value="today" {{ ($filters['last'] ?? '') == 'today' ? 'selected' : '' }}>{{ __('Today') }}</option>
                            <option value="yesterday" {{ ($filters['last'] ?? '') == 'yesterday' ? 'selected' : '' }}>{{ __('Yesterday') }}</option>
                            <option value="week" {{ ($filters['last'] ?? '') == 'week' ? 'selected' : '' }}>{{ __('Last Week') }}</option>
                            <option value="month" {{ ($filters['last'] ?? '') == 'month' ? 'selected' : '' }}>{{ __('Last Month') }}</option>
                            <option value="year" {{ ($filters['last'] ?? '') == 'year' ? 'selected' : '' }}>{{ __('Last Year') }}</option>
                            <option value="monday" {{ ($filters['last'] ?? '') == 'monday' ? 'selected' : '' }}>{{ __('Last Monday') }}</option>
                            <option value="tuesday" {{ ($filters['last'] ?? '') == 'tuesday' ? 'selected' : '' }}>{{ __('Last Tuesday') }}</option>
                            <option value="wednesday" {{ ($filters['last'] ?? '') == 'wednesday' ? 'selected' : '' }}>{{ __('Last Wednesday') }}</option>
                            <option value="thursday" {{ ($filters['last'] ?? '') == 'thursday' ? 'selected' : '' }}>{{ __('Last Thursday') }}</option>
                            <option value="friday" {{ ($filters['last'] ?? '') == 'friday' ? 'selected' : '' }}>{{ __('Last Friday') }}</option>
                        </select>
                        <p class="form-help">{{ __('Filter to a specific time period') }}</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('After Date') }}</label>
                    <div class="col-sm-3">
                        <input type="date" class="form-control" name="after" value="{{ $filters['after'] ?? '' }}">
                    </div>
                    <label class="col-sm-1 control-label">{{ __('Before') }}</label>
                    <div class="col-sm-3">
                        <input type="date" class="form-control" name="before" value="{{ $filters['before'] ?? '' }}">
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Status') }}</label>
                    <div class="col-sm-6">
                        <select class="form-control" name="status">
                            <option value="">{{ __('-- Any --') }}</option>
                            <option value="open" {{ ($filters['status'] ?? '') == 'open' ? 'selected' : '' }}>{{ __('Open/Active') }}</option>
                            <option value="pending" {{ ($filters['status'] ?? '') == 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                            <option value="closed" {{ ($filters['status'] ?? '') == 'closed' ? 'selected' : '' }}>{{ __('Closed') }}</option>
                            <option value="spam" {{ ($filters['status'] ?? '') == 'spam' ? 'selected' : '' }}>{{ __('Spam') }}</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('From Email') }}</label>
                    <div class="col-sm-6">
                        <input type="text" class="form-control" name="from" value="{{ $filters['from'] ?? '' }}" placeholder="{{ __('customer@example.com') }}">
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Assigned To') }}</label>
                    <div class="col-sm-6">
                        <select class="form-control" name="assigned">
                            <option value="">{{ __('-- Any --') }}</option>
                            <option value="me" {{ ($filters['assigned'] ?? '') == 'me' ? 'selected' : '' }}>{{ __('Me') }}</option>
                            <option value="unassigned" {{ ($filters['assigned'] ?? '') == 'unassigned' ? 'selected' : '' }}>{{ __('Unassigned') }}</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}" {{ ($filters['assigned'] ?? '') == $user->id ? 'selected' : '' }}>
                                    {{ $user->getFullName() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Has Attachment') }}</label>
                    <div class="col-sm-6">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="has_attachment" value="1" {{ isset($filters['has_attachment']) ? 'checked' : '' }}>
                                {{ __('Only show conversations with attachments') }}
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-group margin-top">
                    <div class="col-sm-6 col-sm-offset-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="glyphicon glyphicon-search"></i> {{ __('Search') }}
                        </button>
                        <a href="{{ route('improvedsearch.advanced') }}" class="btn btn-default">
                            {{ __('Clear Filters') }}
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

@if(!empty($fullQuery))
<div class="section-heading margin-top-40">
    {{ __('Search Query') }}
</div>
<div class="row-container">
    <div class="row">
        <div class="col-xs-12">
            <code style="font-size: 14px; padding: 10px; display: block; background: #f5f5f5; border-radius: 4px;">{{ $fullQuery }}</code>
            <p class="text-muted margin-top">{{ __('Tip: You can type this query directly in the main search box.') }}</p>
        </div>
    </div>
</div>
@endif

@if($results !== null)
<div class="section-heading margin-top-40">
    {{ __('Results') }}
    @if($results instanceof \Illuminate\Pagination\LengthAwarePaginator)
        ({{ $results->total() }})
    @endif
</div>

<div class="row-container">
    <div class="row">
        <div class="col-xs-12">
            @if($results instanceof \Illuminate\Pagination\LengthAwarePaginator && $results->count() > 0)
                <table class="table table-striped table-conversations">
                    <thead>
                        <tr>
                            <th style="width: 60px;">#</th>
                            <th>{{ __('Subject') }}</th>
                            <th>{{ __('Customer') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Updated') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($results as $conversation)
                            <tr>
                                <td>
                                    <a href="{{ $conversation->url() }}">{{ $conversation->number }}</a>
                                </td>
                                <td>
                                    <a href="{{ $conversation->url() }}">{{ \Str::limit($conversation->subject, 60) }}</a>
                                </td>
                                <td>
                                    {{ $conversation->customer_email }}
                                </td>
                                <td>
                                    @if($conversation->status == \App\Conversation::STATUS_ACTIVE)
                                        <span class="label label-success">{{ __('Active') }}</span>
                                    @elseif($conversation->status == \App\Conversation::STATUS_PENDING)
                                        <span class="label label-warning">{{ __('Pending') }}</span>
                                    @elseif($conversation->status == \App\Conversation::STATUS_CLOSED)
                                        <span class="label label-default">{{ __('Closed') }}</span>
                                    @elseif($conversation->status == \App\Conversation::STATUS_SPAM)
                                        <span class="label label-danger">{{ __('Spam') }}</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $conversation->updated_at->diffForHumans() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="text-center">
                    {{ $results->appends(request()->query())->links() }}
                </div>
            @elseif($results === '' || ($results instanceof \Illuminate\Pagination\LengthAwarePaginator && $results->count() == 0))
                <div class="alert alert-info">
                    {{ __('No conversations found matching your search criteria.') }}
                </div>
            @endif
        </div>
    </div>
</div>
@endif

<div class="section-heading margin-top-40">
    {{ __('Search Operators Help') }}
</div>

<div class="row-container">
    <div class="row">
        <div class="col-xs-12">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>{{ __('Operator') }}</th>
                        <th>{{ __('Description') }}</th>
                        <th>{{ __('Example') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>last:</code></td>
                        <td>{{ __('Filter to a specific time period') }}</td>
                        <td><code>last:today</code>, <code>last:week</code>, <code>last:friday</code></td>
                    </tr>
                    <tr>
                        <td><code>after:</code></td>
                        <td>{{ __('Show conversations after a date') }}</td>
                        <td><code>after:2024-01-01</code>, <code>after:7days</code>, <code>after:lastmonth</code></td>
                    </tr>
                    <tr>
                        <td><code>before:</code></td>
                        <td>{{ __('Show conversations before a date') }}</td>
                        <td><code>before:2024-12-31</code>, <code>before:yesterday</code></td>
                    </tr>
                    <tr>
                        <td><code>status:</code></td>
                        <td>{{ __('Filter by conversation status') }}</td>
                        <td><code>status:open</code>, <code>status:closed</code>, <code>status:pending</code></td>
                    </tr>
                    <tr>
                        <td><code>from:</code></td>
                        <td>{{ __('Filter by sender email') }}</td>
                        <td><code>from:john@example.com</code></td>
                    </tr>
                    <tr>
                        <td><code>assigned:</code></td>
                        <td>{{ __('Filter by assignee') }}</td>
                        <td><code>assigned:me</code>, <code>assigned:unassigned</code></td>
                    </tr>
                    <tr>
                        <td><code>has:</code></td>
                        <td>{{ __('Filter by attachment') }}</td>
                        <td><code>has:attachment</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

@endsection
