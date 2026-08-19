<?php

namespace App\Domain\ExportPdf;

use App\Domain\Reports\ReportsService;
use App\Models\Library;
use App\Models\SystemSetting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PDF export (GitHub issue #87) for the "Auswertungen" (ReportsService's
 * item-level reports) and for a single library's own inventory
 * (LibraryController::exportPdf()) — the two concrete deliverables #87
 * scoped in. Deliberately *not* the older aggregate overview
 * (StatisticsPage.tsx/StatisticsService::overviewFor(), see #87's own
 * text for why) and deliberately not search results either yet — #87's
 * "Zusatzidee" for that was folded into GitHub issue #73 instead (a
 * filter-less freetext search has little worth exporting; see #73's
 * addendum), not implemented here.
 *
 * Renders via barryvdh/laravel-dompdf, a pure-PHP HTML->PDF renderer with
 * no external binary dependency (unlike a wkhtmltopdf-based approach would
 * need) — a real constraint for this project's single, minimal Docker
 * image (docker/Dockerfile has no such binary installed, and adding one
 * would mean a second install/version-pinning burden for every deployment,
 * not just this one server-rendered feature).
 *
 * Every PDF renders in English only, regardless of the requesting user's
 * `preferred_language` — a deliberate v1 scoping decision, not an
 * oversight: this app's UI strings live in frontend/src/i18n's JSON files
 * (see CLAUDE.md), not Laravel's own lang/ translation system, so reusing
 * them server-side would need a genuinely new translation-lookup pathway
 * (e.g. reading the frontend's JSON at runtime) rather than reusing
 * existing infrastructure. Left as a known follow-up rather than solved
 * here, given #87's already-broad scope (seven report types plus the
 * library inventory).
 */
class PdfExportService
{
    /** Mirrors ReportKey from frontend/src/pages/reports/reportTypes.ts — the full set of report keys GET /reports/{key}/export/pdf accepts. */
    public const REPORT_KEYS = [
        'duplicates', 'data-quality', 'recent-additions', 'top-lists', 'capture-source', 'sharing', 'user-activity',
    ];

    /** English-only display labels (see this class's own docblock on why) — mirrors frontend/src/i18n/locales/en.json's libraries.mediaType.*. */
    private const MEDIA_TYPE_LABELS = [
        'book' => 'Book',
        'cd' => 'CD',
        'dvd_bluray' => 'DVD/Blu-ray',
    ];

    public function __construct(private readonly ReportsService $reportsService) {}

    public function reportPdf(User $user, string $key): PdfDocument
    {
        return match ($key) {
            'duplicates' => $this->duplicatesPdf($user),
            'data-quality' => $this->dataQualityPdf($user),
            'recent-additions' => $this->recentAdditionsPdf($user),
            'top-lists' => $this->topListsPdf($user),
            'capture-source' => $this->captureSourcePdf($user),
            'sharing' => $this->sharingPdf($user),
            'user-activity' => $this->userActivityPdf($user),
        };
    }

    private function duplicatesPdf(User $user): PdfDocument
    {
        $groups = array_map(fn (array $group) => [
            ...$group,
            'media_type_label' => self::MEDIA_TYPE_LABELS[$group['media_type']] ?? $group['media_type'],
            'items' => $this->withExtra($group['items'], fn (array $row) => $this->formatPrice($row['price'], $row['currency'])),
        ], $this->reportsService->duplicatesFor($user));

        return $this->render('pdf.reports.duplicates', [
            'title' => 'Duplicates across libraries',
            'groups' => $groups,
            'mediaTypeLabels' => self::MEDIA_TYPE_LABELS,
        ]);
    }

    private function dataQualityPdf(User $user): PdfDocument
    {
        $rows = $this->withExtra(
            $this->reportsService->dataQualityFor($user),
            fn (array $row) => implode(', ', array_map($this->humanizeField(...), $row['missing_fields']))
        );

        return $this->render('pdf.reports.items', [
            'title' => 'Data quality',
            'extraHeader' => 'Missing fields',
            'rows' => $rows,
            'mediaTypeLabels' => self::MEDIA_TYPE_LABELS,
        ]);
    }

    private function recentAdditionsPdf(User $user): PdfDocument
    {
        $rows = $this->withExtra(
            $this->reportsService->recentAdditionsFor($user),
            fn (array $row) => $row['created_at'] ? Carbon::parse($row['created_at'])->toDateString() : '—'
        );

        return $this->render('pdf.reports.items', [
            'title' => 'Recent additions',
            'extraHeader' => 'Added',
            'rows' => $rows,
            'mediaTypeLabels' => self::MEDIA_TYPE_LABELS,
        ]);
    }

    /**
     * One section per ranking, same 8 rankings ReportDetailPage.tsx renders
     * (GitHub issue #104) — `$type` picks which of the per-metric
     * formatters below applies to that ranking's `value` column, mirroring
     * the `formatValue` callback each of the frontend's <TopList> instances
     * passes.
     */
    private function topListsPdf(User $user): PdfDocument
    {
        $topLists = $this->reportsService->topListsFor($user);

        $specs = [
            'most_expensive' => ['title' => 'Most expensive', 'extraHeader' => 'Price', 'type' => 'price'],
            'cheapest' => ['title' => 'Cheapest', 'extraHeader' => 'Price', 'type' => 'price'],
            'most_pages' => ['title' => 'Most pages', 'extraHeader' => 'Pages', 'type' => 'plain'],
            'longest_cd_runtime' => ['title' => 'Longest CD runtime', 'extraHeader' => 'Runtime', 'type' => 'duration'],
            'shortest_cd_runtime' => ['title' => 'Shortest CD runtime', 'extraHeader' => 'Runtime', 'type' => 'duration'],
            'longest_dvd_runtime' => ['title' => 'Longest DVD/Blu-ray runtime', 'extraHeader' => 'Runtime (minutes)', 'type' => 'minutes'],
            'shortest_dvd_runtime' => ['title' => 'Shortest DVD/Blu-ray runtime', 'extraHeader' => 'Runtime (minutes)', 'type' => 'minutes'],
            'highest_disc_count' => ['title' => 'Highest disc count', 'extraHeader' => 'Discs', 'type' => 'plain'],
        ];

        $sections = [];
        foreach ($specs as $key => $spec) {
            $sections[] = [
                'title' => $spec['title'],
                'extraHeader' => $spec['extraHeader'],
                'rows' => $this->withExtra($topLists[$key], fn (array $row) => $this->formatTopListValue($spec['type'], $row['value'])),
            ];
        }

        return $this->render('pdf.reports.top-lists', [
            'title' => 'Top lists',
            'sections' => $sections,
            'mediaTypeLabels' => self::MEDIA_TYPE_LABELS,
        ]);
    }

    private function captureSourcePdf(User $user): PdfDocument
    {
        $data = $this->reportsService->captureSourceFor($user);
        $rows = $this->withExtra($data['items'], function (array $row) {
            $label = ucfirst(str_replace('_', ' ', $row['capture_method'] ?? 'unknown'));

            return $row['metadata_provider'] ? "{$label} ({$row['metadata_provider']})" : $label;
        });

        return $this->render('pdf.reports.items', [
            'title' => 'Capture method & metadata source',
            'extraHeader' => 'Source',
            'rows' => $rows,
            'mediaTypeLabels' => self::MEDIA_TYPE_LABELS,
        ]);
    }

    private function sharingPdf(User $user): PdfDocument
    {
        return $this->render('pdf.reports.sharing', [
            'title' => 'Sharing overview',
            'rows' => $this->reportsService->sharingFor($user),
            'mediaTypeLabels' => self::MEDIA_TYPE_LABELS,
        ]);
    }

    private function userActivityPdf(User $user): PdfDocument
    {
        return $this->render('pdf.reports.user-activity', [
            'title' => 'Activity by user',
            'rows' => $this->reportsService->userActivityFor($user),
        ]);
    }

    /**
     * A single library's item list (GitHub issue #87's second deliverable)
     * — title/subtitle (author/artist/director, same per-media-type field
     * LibraryDetailPage.tsx's table column uses)/EAN/price/location, plus
     * an item-count and total-value summary line. Unlike the reports
     * above, this isn't scoped through LibraryAccessService itself —
     * LibraryController::exportPdf() already does that canRead() check
     * before calling this, same as every other single-library read.
     */
    public function libraryInventoryPdf(Library $library): PdfDocument
    {
        $subtitleField = match ($library->media_type) {
            'book' => 'authors',
            'cd' => 'artist',
            'dvd_bluray' => 'director',
        };
        $subtitleLabel = match ($library->media_type) {
            'book' => 'Author(s)',
            'cd' => 'Artist',
            'dvd_bluray' => 'Director',
        };

        $items = $library->mediaItems()->orderBy('title')->get();

        $rows = $items->map(fn (Model $item) => [
            'title' => $item->title,
            'subtitle' => $item->{$subtitleField},
            'ean' => $item->ean,
            'price' => $this->formatPrice($item->price, $item->currency),
            'location' => $item->location,
        ])->all();

        return $this->render('pdf.library', [
            'title' => $library->name,
            'mediaTypeLabel' => self::MEDIA_TYPE_LABELS[$library->media_type] ?? $library->media_type,
            'subtitleLabel' => $subtitleLabel,
            'rows' => $rows,
            'itemCount' => $items->count(),
            'totalValueLabel' => $this->formatPrice($items->sum('price'), null),
        ]);
    }

    /** @param array<int, array> $rows @return array<int, array> each row with an 'extra' string column added, computed by $formatter */
    private function withExtra(array $rows, Closure $formatter): array
    {
        return array_map(fn (array $row) => [...$row, 'extra' => $formatter($row)], $rows);
    }

    /** Mirrors mediaItemFields.ts's ItemsTable/reports.none-adjacent price formatting: no currency shown at all when none is on record, rather than a misleading bare number. */
    private function formatPrice(mixed $price, ?string $currency): string
    {
        if ($price === null) {
            return '—';
        }

        return $currency ? "{$price} {$currency}" : (string) $price;
    }

    /** Mirrors mediaItemFields.ts's formatDuration() (M:SS, or H:MM:SS past an hour) — used for a CD's runtime_seconds top lists. */
    private function formatDuration(int $totalSeconds): string
    {
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;
        $paddedSeconds = str_pad((string) $seconds, 2, '0', STR_PAD_LEFT);

        return $hours > 0
            ? sprintf('%d:%s:%s', $hours, str_pad((string) $minutes, 2, '0', STR_PAD_LEFT), $paddedSeconds)
            : "{$minutes}:{$paddedSeconds}";
    }

    private function formatTopListValue(string $type, mixed $value): string
    {
        return match ($type) {
            'price' => $this->formatPrice($value, null),
            'duration' => $this->formatDuration((int) $value),
            'minutes' => "{$value} min",
            'plain' => (string) $value,
        };
    }

    /** "cover_path" -> "Cover Path" — data-quality's missing_fields are raw model column names; a human reading a PDF meant for archiving/insurance shouldn't see a snake_case identifier. */
    private function humanizeField(string $field): string
    {
        return ucwords(str_replace('_', ' ', $field));
    }

    /** Every PDF gets the same generated-at footnote, in the admin-configured display timezone (SystemSetting::localNow(), not always UTC — same reasoning ExportImportController's own filename timestamp already follows). */
    private function render(string $view, array $data): PdfDocument
    {
        return Pdf::loadView($view, [
            ...$data,
            'generatedAt' => SystemSetting::localNow()->format('Y-m-d H:i'),
        ]);
    }
}
