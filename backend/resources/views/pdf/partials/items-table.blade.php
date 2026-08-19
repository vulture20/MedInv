{{--
    Shared row-of-media-items table (GitHub issue #87) — mirrors
    ReportDetailPage.tsx's <ItemsTable> (GitHub issue #102): title/EAN/
    library, plus one report-specific "extra" column (already formatted
    into each row's 'extra' key by PdfExportService, since a PHP Closure
    would work as a Blade variable too but is a more roundabout way to get
    a plain string per row than just computing it up front). Used directly
    by pdf.reports.items (data-quality/recent-additions/capture-source) and
    once per group/section by pdf.reports.duplicates/top-lists.

    $rows: array<int, array{title:string, ean:string, library_name:string, media_type:string, extra?:string}>
    $extraHeader: string|null — omit the extra column entirely when null (mirrors ItemsTable's optional extraHeader prop).
    $mediaTypeLabels, $colTitle, $colEan, $colLibrary, $noItemsText: all injected by PdfExportService::render() (GitHub issue #113), shared across every view using this partial rather than passed at each @include site.
--}}
@if(count($rows) === 0)
    <p class="hint">{{ $noItemsText }}</p>
@else
    <table>
        <thead>
            <tr>
                <th>{{ $colTitle }}</th>
                <th>{{ $colEan }}</th>
                <th>{{ $colLibrary }}</th>
                @if($extraHeader ?? null)
                    <th>{{ $extraHeader }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td>{{ $row['title'] }}</td>
                    <td>{{ $row['ean'] }}</td>
                    <td>{{ $row['library_name'] }} <span class="badge">({{ $mediaTypeLabels[$row['media_type']] ?? $row['media_type'] }})</span></td>
                    @if($extraHeader ?? null)
                        <td>{{ $row['extra'] ?? '' }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
