{{-- Activity by user (GitHub issue #87) — rows are users, not media items (see ReportsService::userActivityFor()'s docblock), so this doesn't reuse partials.items-table. --}}
@extends('pdf.layout')

@section('content')
    @if(count($rows) === 0)
        <p class="hint">{{ $noItemsText }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>{{ $userHeader }}</th>
                    <th>{{ $itemCountHeader }}</th>
                    <th>{{ $lastCapturedHeader }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        {{-- GitHub issue #113: 'user_name' already resolved to $unknownUserText in userActivityPdf() when null. --}}
                        <td>{{ $row['user_name'] }}</td>
                        <td>{{ $row['item_count'] }}</td>
                        <td>{{ $row['last_captured_at'] ? \Illuminate\Support\Carbon::parse($row['last_captured_at'])->toDateString() : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
