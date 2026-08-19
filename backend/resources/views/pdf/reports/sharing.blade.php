{{--
    Sharing overview (GitHub issue #87) — rows are libraries, not media
    items (see ReportsService::sharingFor()'s docblock), so this doesn't
    reuse partials.items-table.

    GitHub issue #113: unlike every other view here, PdfExportService's
    sharingPdf() already precomputes each row's whole "who (access level)"
    text as `shares_text` — translating scope/access-level labels needs
    per-share logic (which of two possible label lookups, "not shared"
    fallback) that's more natural to express once in PHP than repeated in
    Blade, the same reasoning captureSourcePdf()/duplicatesPdf() already
    follow for their own per-row 'extra' column.
--}}
@extends('pdf.layout')

@section('content')
    @if(count($rows) === 0)
        <p class="hint">{{ $noItemsText }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>{{ $libraryHeader }}</th>
                    <th>{{ $sharedWithHeader }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['library_name'] }} <span class="badge">({{ $mediaTypeLabels[$row['media_type']] ?? $row['media_type'] }})</span></td>
                        <td>{{ $row['shares_text'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
