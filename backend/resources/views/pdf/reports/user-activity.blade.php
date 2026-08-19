{{-- Activity by user (GitHub issue #87) — rows are users, not media items (see ReportsService::userActivityFor()'s docblock), so this doesn't reuse partials.items-table. --}}
@extends('pdf.layout')

@section('content')
    @if(count($rows) === 0)
        <p class="hint">No activity to show.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Item count</th>
                    <th>Last captured</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['user_name'] ?? 'Unknown (captured before this feature existed)' }}</td>
                        <td>{{ $row['item_count'] }}</td>
                        <td>{{ $row['last_captured_at'] ? \Illuminate\Support\Carbon::parse($row['last_captured_at'])->toDateString() : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
