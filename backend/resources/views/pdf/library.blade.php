{{-- A single library's inventory (GitHub issue #87) — title/subtitle (author/artist/director)/EAN/price/location, plus an item-count and total-value summary line. The stated use case in #87 is exactly this: a printable/archivable inventory list, e.g. as proof for an insurance claim. --}}
@extends('pdf.layout')

@section('content')
    <p class="meta">{{ $mediaTypeLabel }} &mdash; {{ $itemCount }} {{ $itemCount === 1 ? 'item' : 'items' }} &mdash; total value {{ $totalValueLabel }}</p>

    @if(count($rows) === 0)
        <p class="hint">This library doesn't contain any items yet.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>{{ $subtitleLabel }}</th>
                    <th>EAN</th>
                    <th>Price</th>
                    <th>Location</th>
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
