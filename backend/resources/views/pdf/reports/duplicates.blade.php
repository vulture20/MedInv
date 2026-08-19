{{-- Duplicates across libraries (GitHub issue #87) — one items-table per EAN group, mirroring ReportDetailPage.tsx's per-group rendering. --}}
@extends('pdf.layout')

@section('content')
    @forelse($groups as $group)
        <h2>{{ $group['ean'] }} <span class="badge">({{ $group['media_type_label'] }})</span></h2>
        @include('pdf.partials.items-table', ['rows' => $group['items'], 'extraHeader' => 'Price', 'mediaTypeLabels' => $mediaTypeLabels])
    @empty
        <p class="hint">No duplicates found.</p>
    @endforelse
@endsection
