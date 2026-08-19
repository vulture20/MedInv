{{--
    Shared PDF layout (GitHub issue #87) — every pdf.* view extends this.
    Plain <style> block rather than the inline-style approach
    resources/views/mail/*.blade.php uses: those are HTML email, constrained
    by mail clients' patchy CSS support; dompdf (the renderer here, see
    PdfExportService's docblock) is a normal HTML+CSS engine, so a <head>
    stylesheet works exactly like it would in a browser. "DejaVu Sans" is
    dompdf's own bundled default font family, chosen explicitly (rather than
    left unset) for its broad Unicode coverage — library/item names are free
    text in whatever script a user chose, this app being translated into a
    dozen languages (briefing 10./11.4); as of GitHub issue #113, the
    surrounding UI copy in this very layout is too, via $generatedAtText
    (PdfExportService::render()).
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 16px 0 4px; }
        .meta { color: #666; font-size: 9px; margin: 0 0 14px; }
        .hint { color: #888; font-style: italic; }
        .badge { color: #666; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { text-align: left; padding: 3px 6px; border-bottom: 1px solid #ddd; vertical-align: top; }
        th { background: #f0f0f0; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">MedInv &mdash; {{ $generatedAtText }}</p>
    @yield('content')
</body>
</html>
