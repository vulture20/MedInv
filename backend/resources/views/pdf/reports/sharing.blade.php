{{-- Sharing overview (GitHub issue #87) — rows are libraries, not media items (see ReportsService::sharingFor()'s docblock), so this doesn't reuse partials.items-table. --}}
@extends('pdf.layout')

@section('content')
    @if(count($rows) === 0)
        <p class="hint">No libraries to show.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Library</th>
                    <th>Shared with</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['library_name'] }} <span class="badge">({{ $mediaTypeLabels[$row['media_type']] ?? $row['media_type'] }})</span></td>
                        <td>
                            @if($row['is_shared'])
                                @foreach($row['shares'] as $share)
                                    {{ $share['scope'] === 'user' ? ($share['user_name'] ?? '?') : ucfirst(str_replace('_', ' ', $share['scope'])) }} ({{ $share['access_level'] }}){{ !$loop->last ? ', ' : '' }}
                                @endforeach
                            @else
                                <span class="hint">Not shared</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
