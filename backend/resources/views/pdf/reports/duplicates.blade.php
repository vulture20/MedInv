{{-- Duplicates across libraries (GitHub issue #87) — one items-table per EAN group, mirroring ReportDetailPage.tsx's per-group rendering. --}}
@extends('pdf.layout')

@section('content')
    @forelse($groups as $group)
        <h2>{{ $group['ean'] }} <span class="badge">({{ $group['media_type_label'] }})</span></h2>
        @include('pdf.partials.items-table', ['rows' => $group['items'], 'extraHeader' => $colPrice])
    @empty
        {{-- GitHub issue #113: reuses the same $noItemsText the items-table partial itself falls back to, rather than its own hardcoded string — matches ReportDetailPage.tsx's own reports.none reuse (GitHub issue #106). --}}
        <p class="hint">{{ $noItemsText }}</p>
    @endforelse
@endsection
