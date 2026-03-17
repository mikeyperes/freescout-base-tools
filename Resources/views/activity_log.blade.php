@extends('layouts.app')

@section('title', 'Activity Log')

@section('content')
<div class="container" style="max-width:1200px;padding:20px;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
        <h2 style="margin:0;font-size:22px;color:#333;">
            <i class="glyphicon glyphicon-list-alt" style="margin-right:8px;color:#777;"></i>
            Activity Log
            <small style="font-size:13px;color:#999;margin-left:8px;">{{ number_format($total) }} entries{{ $total > $perPage ? ' (showing latest '.$perPage.')' : '' }}</small>
        </h2>
        <a href="{{ url('/hexaweb/activity-log') }}" class="btn btn-default btn-sm">
            <i class="glyphicon glyphicon-refresh"></i> Refresh
        </a>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ url('/hexaweb/activity-log') }}" style="margin-bottom:20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <select name="log" class="form-control" style="width:auto;min-width:180px;">
            <option value="all" {{ $logName === 'all' ? 'selected' : '' }}>All log types</option>
            @foreach($logNames as $name)
                <option value="{{ $name }}" {{ $logName === $name ? 'selected' : '' }}>{{ $name }}</option>
            @endforeach
        </select>
        <input type="text" name="q" class="form-control" style="width:250px;" placeholder="Search description or properties..." value="{{ $search }}">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        @if($search || $logName !== 'all')
            <a href="{{ url('/hexaweb/activity-log') }}" class="btn btn-default btn-sm">Clear</a>
        @endif
    </form>

    @if(count($logs) === 0)
        <div class="alert alert-info">No log entries found.</div>
    @else
    <div class="panel panel-default" style="border-radius:4px;overflow:hidden;">
        <table class="table table-hover" style="margin:0;font-size:13px;">
            <thead>
                <tr style="background:#f5f5f5;">
                    <th style="width:160px;color:#777;">Time (UTC)</th>
                    <th style="width:150px;color:#777;">Log</th>
                    <th style="width:180px;color:#777;">Action</th>
                    <th style="color:#777;">Details</th>
                    <th style="width:80px;color:#777;">Conv #</th>
                    <th style="width:50px;color:#777;">ID</th>
                </tr>
            </thead>
            <tbody>
                @foreach($logs as $entry)
                @php
                    $props = json_decode($entry->properties, true) ?? [];
                    $meta  = $descLabels[$entry->description] ?? ['color' => '#777', 'label' => $entry->description];
                    $isError = in_array($entry->description, ['watcher_toggle_failed', 'error_fetching_email'])
                               || $entry->log_name === 'fetch_errors';
                    $rowStyle = $isError ? 'background:#fff5f5;' : '';

                    // Build a readable detail string from properties
                    $details = [];
                    foreach($props as $k => $v) {
                        if(is_string($v) || is_numeric($v)) {
                            $details[] = '<span style="color:#999;">'.$k.':</span> '.e($v);
                        }
                    }
                    $detailStr = implode(' &nbsp;·&nbsp; ', $details);
                @endphp
                <tr style="{{ $rowStyle }}">
                    <td style="color:#999;white-space:nowrap;vertical-align:middle;">
                        {{ $entry->created_at ? date('M j, Y g:ia', strtotime($entry->created_at)) : '—' }}
                    </td>
                    <td style="vertical-align:middle;">
                        <span style="background:#eee;padding:2px 6px;border-radius:3px;font-size:11px;color:#666;">
                            {{ $entry->log_name }}
                        </span>
                    </td>
                    <td style="vertical-align:middle;">
                        <span style="color:{{ $meta['color'] }};font-weight:600;">{{ $meta['label'] }}</span>
                    </td>
                    <td style="vertical-align:middle;font-size:12px;color:#555;">
                        {!! $detailStr !!}
                    </td>
                    <td style="vertical-align:middle;text-align:center;">
                        @if($entry->subject_id)
                            <a href="{{ url('/conversation/'.$entry->subject_id) }}" style="font-size:12px;">
                                #{{ $props['conversation_number'] ?? $entry->subject_id }}
                            </a>
                        @else
                            <span style="color:#ccc;">—</span>
                        @endif
                    </td>
                    <td style="vertical-align:middle;color:#ccc;font-size:11px;">{{ $entry->id }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

</div>
@endsection
