{{--
    A single library's inventory (GitHub issue #87) — title/subtitle
    (author/artist/director)/EAN/price/location, plus an item-count and
    total-value summary line. The stated use case in #87 is exactly this: a
    printable/archivable inventory list, e.g. as proof for an insurance
    claim.

    GitHub issue #113: $metaLine (mediaTypeLabel/item-count pluralization/
    total value) is fully precomputed by libraryInventoryPdf() rather than
    assembled here — pluralization needs a real _one/_other lookup
    (Translator::plural()), not the plain PHP ternary this used to be.
--}}
@extends('pdf.layout')

@section('content')
    <p class="meta">{{ $metaLine }}</p>

    @if(count($rows) === 0)
        <p class="hint">{{ $emptyLibraryText }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>{{ $colTitle }}</th>
                    <th>{{ $subtitleLabel }}</th>
                    <th>{{ $colEan }}</th>
                    <th>{{ $colPrice }}</th>
                    <th>{{ $locationHeader }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['title'] }}</td>
                        <td>{{ $row['subtitle'] }}</td>
                        <td>{{ $row['ean'] }}</td>
                        <td>{{ $row['price'] }}</td>
                        <td>{{ $row['location'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
