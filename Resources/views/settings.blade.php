@extends('layouts.app')

@section('title', __('Improved Search Settings'))

@section('content')
<div class="section-heading">
    {{ __('Improved Search Settings') }}
</div>

<div class="row-container">
    <div class="row">
        <div class="col-xs-12">
            <form class="form-horizontal margin-top" method="POST" action="{{ route('improvedsearch.settings.save') }}">
                @csrf

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Enable Full-Text Search') }}</label>
                    <div class="col-sm-6">
                        <div class="controls">
                            <div class="onoffswitch-wrap">
                                <div class="onoffswitch">
                                    <input type="checkbox" name="enable_fulltext" value="1" id="enable_fulltext" class="onoffswitch-checkbox" @if(config('improvedsearch.enable_fulltext')) checked @endif>
                                    <label class="onoffswitch-label" for="enable_fulltext"></label>
                                </div>
                            </div>
                        </div>
                        <p class="form-help">
                            {{ __('Enable MySQL full-text search for faster and more accurate results.') }}
                        </p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Enable Suggestions') }}</label>
                    <div class="col-sm-6">
                        <div class="controls">
                            <div class="onoffswitch-wrap">
                                <div class="onoffswitch">
                                    <input type="checkbox" name="enable_suggestions" value="1" id="enable_suggestions" class="onoffswitch-checkbox" @if(config('improvedsearch.enable_suggestions')) checked @endif>
                                    <label class="onoffswitch-label" for="enable_suggestions"></label>
                                </div>
                            </div>
                        </div>
                        <p class="form-help">
                            {{ __('Show search suggestions based on search history.') }}
                        </p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Track Search History') }}</label>
                    <div class="col-sm-6">
                        <div class="controls">
                            <div class="onoffswitch-wrap">
                                <div class="onoffswitch">
                                    <input type="checkbox" name="track_history" value="1" id="track_history" class="onoffswitch-checkbox" @if(config('improvedsearch.track_history')) checked @endif>
                                    <label class="onoffswitch-label" for="track_history"></label>
                                </div>
                            </div>
                        </div>
                        <p class="form-help">
                            {{ __('Track search queries for suggestions and analytics.') }}
                        </p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Cache Duration (minutes)') }}</label>
                    <div class="col-sm-6">
                        <input type="number" class="form-control" name="cache_duration" value="{{ config('improvedsearch.cache_duration', 5) }}" min="0" max="60">
                        <p class="form-help">
                            {{ __('How long to cache search results. Set to 0 to disable caching.') }}
                        </p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Results Per Page') }}</label>
                    <div class="col-sm-6">
                        <input type="number" class="form-control" name="results_per_page" value="{{ config('improvedsearch.results_per_page', 50) }}" min="10" max="200">
                    </div>
                </div>

                <div class="form-group margin-top">
                    <div class="col-sm-6 col-sm-offset-2">
                        <button type="submit" class="btn btn-primary">
                            {{ __('Save') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="section-heading margin-top-40">
    {{ __('Search Index') }}
</div>

<div class="row-container">
    <div class="row">
        <div class="col-xs-12">
            <p>{{ __('Indexed conversations:') }} <strong id="indexed-count">{{ $indexedCount ?? 0 }}</strong></p>
            <p>{{ __('Total conversations:') }} <strong>{{ $totalCount ?? 0 }}</strong></p>

            <button type="button" class="btn btn-default" id="rebuild-index-btn">
                <i class="glyphicon glyphicon-refresh"></i> {{ __('Rebuild Search Index') }}
            </button>

            <div id="rebuild-progress" class="margin-top hidden">
                <div class="progress">
                    <div class="progress-bar" role="progressbar" style="width: 0%">0%</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="section-heading margin-top-40">
    {{ __('Search Statistics') }}
</div>

<div class="row-container">
    <div class="row">
        <div class="col-xs-12">
            <div id="search-stats">
                <p>{{ __('Loading statistics...') }}</p>
            </div>
        </div>
    </div>
</div>

@endsection

@section('javascript')
<script>
$(document).ready(function() {
    // Load statistics
    $.get('{{ route("improvedsearch.statistics") }}', function(data) {
        var html = '<table class="table table-striped">';
        html += '<tr><td>{{ __("Total Searches") }}</td><td>' + data.total_searches + '</td></tr>';
        html += '<tr><td>{{ __("Unique Queries") }}</td><td>' + data.unique_queries + '</td></tr>';
        html += '<tr><td>{{ __("Searches Today") }}</td><td>' + data.searches_today + '</td></tr>';
        html += '<tr><td>{{ __("Indexed Conversations") }}</td><td>' + data.indexed_conversations + '</td></tr>';
        html += '</table>';

        if (data.top_queries && data.top_queries.length > 0) {
            html += '<h4>{{ __("Top Queries") }}</h4><ul>';
            data.top_queries.forEach(function(q) {
                html += '<li>' + q.query + ' (' + q.count + ')</li>';
            });
            html += '</ul>';
        }

        $('#search-stats').html(html);
        $('#indexed-count').text(data.indexed_conversations);
    });

    // Rebuild index
    $('#rebuild-index-btn').click(function() {
        var btn = $(this);
        btn.prop('disabled', true);
        $('#rebuild-progress').removeClass('hidden');

        $.post('{{ route("improvedsearch.rebuild-index") }}', {
            _token: '{{ csrf_token() }}'
        }, function(data) {
            if (data.success) {
                $('.progress-bar').css('width', '100%').text('100%');
                alert(data.message);
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        }).fail(function() {
            alert('Failed to rebuild index.');
        }).always(function() {
            btn.prop('disabled', false);
        });
    });
});
</script>
@endsection
