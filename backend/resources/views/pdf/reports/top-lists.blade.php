{{-- Top lists (GitHub issue #87) — one items-table per ranking, mirroring ReportDetailPage.tsx's <TopList> (GitHub issue #104: itself already just an ItemsTable with an extra ranking column). --}}
@extends('pdf.layout')

@section('content')
    @foreach($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        @include('pdf.partials.items-table', ['rows' => $section['rows'], 'extraHeader' => $section['extraHeader'], 'mediaTypeLabels' => $mediaTypeLabels])
    @endforeach
@endsection
