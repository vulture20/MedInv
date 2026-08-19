{{-- Data quality / recent additions / capture source (GitHub issue #87) — all three are a flat list of items plus one extra column, so they share this one view rather than three near-identical copies. --}}
@extends('pdf.layout')

@section('content')
    @include('pdf.partials.items-table', ['rows' => $rows, 'extraHeader' => $extraHeader, 'mediaTypeLabels' => $mediaTypeLabels])
@endsection
