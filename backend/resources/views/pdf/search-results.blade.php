{{--
    Search results export (GitHub issue #121, a #73 comment's addendum). The
    filter criteria that produced this result set are listed plainly above
    the table (PdfExportService::filterSummaryLines()) — a bare list of
    items with no indication of what was actually searched for would be
    close to meaningless once printed or archived. Reuses the shared
    items-table partial (title/EAN/library, plus one extra column — location
    here, the same fourth column SearchPage.tsx's own results table shows).
--}}
@extends('pdf.layout')

@section('content')
    <h2>{{ $filterSummaryTitle }}</h2>
    @if(count($filterLines) === 0)
        <p class="hint">{{ $noFiltersText }}</p>
    @else
        <ul>
            @foreach($filterLines as $line)
                <li>{{ $line['label'] }}: {{ $line['value'] }}</li>
            @endforeach
        </ul>
    @endif

    @include('pdf.partials.items-table', ['rows' => $rows, 'extraHeader' => $locationHeader, 'mediaTypeLabels' => $mediaTypeLabels])
@endsection
