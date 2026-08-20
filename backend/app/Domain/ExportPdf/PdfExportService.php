<?php

namespace App\Domain\ExportPdf;

use App\Domain\Languages\Translator;
use App\Domain\Reports\ReportsService;
use App\Domain\Search\SearchFilters;
use App\Domain\Search\SearchService;
use App\Models\Library;
use App\Models\SystemSetting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use NumberFormatter;

/**
 * PDF export (GitHub issue #87) for the "Auswertungen" (ReportsService's
 * item-level reports), for a single library's own inventory
 * (LibraryController::exportPdf()), and for the search mask's current
 * result set (SearchController::exportPdf(), GitHub issue #121 — a #73
 * comment's addendum, deliberately not folded into #73 itself since it's
 * a separate, clearly scoped feature). Deliberately *not* the older
 * aggregate overview (StatisticsPage.tsx/StatisticsService::overviewFor(),
 * see #87's own text for why).
 *
 * Renders via barryvdh/laravel-dompdf, a pure-PHP HTML->PDF renderer with
 * no external binary dependency (unlike a wkhtmltopdf-based approach would
 * need) — a real constraint for this project's single, minimal Docker
 * image (docker/Dockerfile has no such binary installed, and adding one
 * would mean a second install/version-pinning burden for every deployment,
 * not just this one server-rendered feature).
 *
 * Every PDF renders in the requesting user's `preferred_language`
 * (GitHub issue #113) via App\Domain\Languages\Translator, which looks
 * strings up against the exact same translations the frontend uses —
 * frontend/src/i18n/locales/{en,de}.json for the two bundled languages,
 * the `language_packs` table for every other one. This used to render
 * English-only, a deliberate v1 scoping decision rather than an oversight
 * (see #87's already-broad scope); #113's investigation found every string
 * a PDF needs already had a translation waiting, so this is a plumbing
 * change, not a translation-writing one — see Translator's own docblock.
 */
class PdfExportService
{
    /** Mirrors ReportKey from frontend/src/pages/reports/reportTypes.ts — the full set of report keys GET /reports/{key}/export/pdf accepts. */
    public const REPORT_KEYS = [
        'duplicates', 'data-quality', 'recent-additions', 'top-lists', 'capture-source', 'sharing', 'user-activity',
    ];

    public function __construct(
        private readonly ReportsService $reportsService,
        private readonly Translator $translator,
        private readonly SearchService $searchService,
    ) {}

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
        $lang = $this->languageFor($user);
        $mediaTypeLabels = $this->mediaTypeLabels($lang);

        $groups = array_map(fn (array $group) => [
            ...$group,
            'media_type_label' => $mediaTypeLabels[$group['media_type']] ?? $group['media_type'],
            'items' => $this->withExtra($group['items'], fn (array $row) => $this->formatPrice($row['price'], $row['currency'], $lang)),
        ], $this->reportsService->duplicatesFor($user));

        return $this->render('pdf.reports.duplicates', $lang, [
            'title' => $this->tr($lang, 'reports.duplicates.title'),
            'groups' => $groups,
        ]);
    }

    private function dataQualityPdf(User $user): PdfDocument
    {
        $lang = $this->languageFor($user);
        $rows = $this->withExtra(
            $this->reportsService->dataQualityFor($user),
            fn (array $row) => implode(', ', array_map(fn (string $field) => $this->fieldLabel($field, $lang), $row['missing_fields']))
        );

        return $this->render('pdf.reports.items', $lang, [
            'title' => $this->tr($lang, 'reports.dataQuality.title'),
            'extraHeader' => $this->tr($lang, 'reports.dataQuality.missingFields'),
            'rows' => $rows,
        ]);
    }

    private function recentAdditionsPdf(User $user): PdfDocument
    {
        $lang = $this->languageFor($user);
        $rows = $this->withExtra(
            $this->reportsService->recentAdditionsFor($user),
            fn (array $row) => $row['created_at'] ? Carbon::parse($row['created_at'])->toDateString() : '—'
        );

        return $this->render('pdf.reports.items', $lang, [
            'title' => $this->tr($lang, 'reports.recentAdditions.title'),
            'extraHeader' => $this->tr($lang, 'reports.recentAdditions.addedAt'),
            'rows' => $rows,
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
        $lang = $this->languageFor($user);
        $topLists = $this->reportsService->topListsFor($user);

        $priceHeader = $this->tr($lang, 'mediaItem.fields.price');
        $cdRuntimeHeader = $this->tr($lang, 'mediaItem.runtime');
        $dvdRuntimeHeader = $this->tr($lang, 'mediaItem.fields.runtime_minutes');

        $specs = [
            'most_expensive' => ['title' => $this->tr($lang, 'reports.topLists.mostExpensive'), 'extraHeader' => $priceHeader, 'type' => 'price'],
            'cheapest' => ['title' => $this->tr($lang, 'reports.topLists.cheapest'), 'extraHeader' => $priceHeader, 'type' => 'price'],
            'most_pages' => ['title' => $this->tr($lang, 'reports.topLists.mostPages'), 'extraHeader' => $this->tr($lang, 'mediaItem.fields.page_count'), 'type' => 'plain'],
            'longest_cd_runtime' => ['title' => $this->tr($lang, 'reports.topLists.longestCdRuntime'), 'extraHeader' => $cdRuntimeHeader, 'type' => 'duration'],
            'shortest_cd_runtime' => ['title' => $this->tr($lang, 'reports.topLists.shortestCdRuntime'), 'extraHeader' => $cdRuntimeHeader, 'type' => 'duration'],
            'longest_dvd_runtime' => ['title' => $this->tr($lang, 'reports.topLists.longestDvdRuntime'), 'extraHeader' => $dvdRuntimeHeader, 'type' => 'minutes'],
            'shortest_dvd_runtime' => ['title' => $this->tr($lang, 'reports.topLists.shortestDvdRuntime'), 'extraHeader' => $dvdRuntimeHeader, 'type' => 'minutes'],
            'highest_disc_count' => ['title' => $this->tr($lang, 'reports.topLists.highestDiscCount'), 'extraHeader' => $this->tr($lang, 'mediaItem.fields.disc_count'), 'type' => 'plain'],
        ];

        $sections = [];
        foreach ($specs as $key => $spec) {
            $sections[] = [
                'title' => $spec['title'],
                'extraHeader' => $spec['extraHeader'],
                'rows' => $this->withExtra($topLists[$key], fn (array $row) => $this->formatTopListValue($spec['type'], $row, $lang)),
            ];
        }

        return $this->render('pdf.reports.top-lists', $lang, [
            'title' => $this->tr($lang, 'reports.topLists.title'),
            'sections' => $sections,
        ]);
    }

    private function captureSourcePdf(User $user): PdfDocument
    {
        $lang = $this->languageFor($user);
        $data = $this->reportsService->captureSourceFor($user);
        $rows = $this->withExtra($data['items'], function (array $row) use ($lang) {
            $label = $this->captureMethodLabel($row['capture_method'] ?? 'unknown', $lang);

            if (! $row['metadata_provider']) {
                return $label;
            }

            // Comma-separated (see ReportsService::captureSourceFor()'s
            // docblock) — split and humanize each the same way
            // ReportDetailPage.tsx's formatProviderKey() does, rather than
            // showing a raw provider key like "book.google_books".
            $providers = collect(explode(',', $row['metadata_provider']))
                ->map(fn (string $provider) => $this->formatProviderKey($provider))
                ->implode(', ');

            return "{$label} ({$providers})";
        });

        return $this->render('pdf.reports.items', $lang, [
            // Reused as both the page title and the extra column's header —
            // ReportDetailPage.tsx does the same (GitHub issue #113).
            'title' => $this->tr($lang, 'reports.captureSource.title'),
            'extraHeader' => $this->tr($lang, 'reports.captureSource.title'),
            'rows' => $rows,
        ]);
    }

    private function sharingPdf(User $user): PdfDocument
    {
        $lang = $this->languageFor($user);
        $notSharedText = $this->tr($lang, 'reports.sharing.notShared');

        $rows = array_map(function (array $row) use ($lang, $notSharedText) {
            $sharesText = $row['is_shared']
                ? collect($row['shares'])->map(function (array $share) use ($lang) {
                    $who = $share['scope'] === 'user'
                        ? ($share['user_name'] ?? '?')
                        : $this->scopeLabel($share['scope'], $lang);
                    $accessLabel = $this->tr($lang, "reports.sharing.accessLevel.{$share['access_level']}");

                    return "{$who} ({$accessLabel})";
                })->implode(', ')
                : $notSharedText;

            return [...$row, 'shares_text' => $sharesText];
        }, $this->reportsService->sharingFor($user));

        return $this->render('pdf.reports.sharing', $lang, [
            'title' => $this->tr($lang, 'reports.sharing.title'),
            'rows' => $rows,
            // "Libraries" (plural), matching the column header
            // ReportDetailPage.tsx's sharing table actually uses — distinct
            // from render()'s own $colLibrary ("Library", singular),
            // used by the item-level report tables instead.
            'libraryHeader' => $this->tr($lang, 'libraries.title'),
            'sharedWithHeader' => $this->tr($lang, 'reports.sharing.sharedWith'),
        ]);
    }

    private function userActivityPdf(User $user): PdfDocument
    {
        $lang = $this->languageFor($user);
        $unknownUserText = $this->tr($lang, 'reports.userActivity.unknownUser');

        $rows = array_map(fn (array $row) => [
            ...$row,
            'user_name' => $row['user_name'] ?? $unknownUserText,
        ], $this->reportsService->userActivityFor($user));

        return $this->render('pdf.reports.user-activity', $lang, [
            'title' => $this->tr($lang, 'reports.userActivity.title'),
            'rows' => $rows,
            'userHeader' => $this->tr($lang, 'reports.userActivity.user'),
            'itemCountHeader' => $this->tr($lang, 'reports.itemCount'),
            'lastCapturedHeader' => $this->tr($lang, 'reports.userActivity.lastCaptured'),
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
    public function libraryInventoryPdf(Library $library, User $user): PdfDocument
    {
        $lang = $this->languageFor($user);

        $subtitleField = match ($library->media_type) {
            'book' => 'authors',
            'cd' => 'artist',
            'dvd_bluray' => 'director',
        };
        $subtitleLabel = $this->tr($lang, "mediaItem.fields.$subtitleField");

        $items = $library->mediaItems()->orderBy('title')->get();

        $rows = $items->map(fn (Model $item) => [
            'title' => $item->title,
            'subtitle' => $item->{$subtitleField},
            'ean' => $item->ean,
            'price' => $this->formatPrice($item->price, $item->currency, $lang),
            'location' => $item->location,
        ])->all();

        // Same currency_mismatch rule StatisticsService::overviewFor() already
        // applies to its own per-library total_value (GitHub issue #62/#105),
        // expressed against an already-loaded Collection here instead of a
        // second query — this library's item total is exactly the same kind
        // of aggregate sum, so it gets the same "only label it with a
        // currency when every item actually agrees" treatment (GitHub issue
        // #107) rather than always showing a bare, unit-less number.
        $defaultCurrency = SystemSetting::get('statistics.default_currency');
        $currencyMismatch = $defaultCurrency !== null
            && $items->contains(fn (Model $item) => $item->currency !== null && $item->currency !== $defaultCurrency);

        $totalValueText = $this->tr($lang, 'statistics.totalValue').': '
            .$this->formatPrice($items->sum('price'), $currencyMismatch ? null : $defaultCurrency, $lang);

        $metaLine = implode(' — ', [
            $this->mediaTypeLabels($lang)[$library->media_type] ?? $library->media_type,
            $this->trPlural($lang, 'libraries.itemsTitle', $items->count()),
            $totalValueText,
        ]);

        return $this->render('pdf.library', $lang, [
            'title' => $library->name,
            'metaLine' => $metaLine,
            'subtitleLabel' => $subtitleLabel,
            'locationHeader' => $this->tr($lang, 'mediaItem.fields.location'),
            'emptyLibraryText' => $this->tr($lang, 'libraries.noItems'),
            'rows' => $rows,
        ]);
    }

    /**
     * The search mask's current result set (GitHub issue #121), scoped
     * exactly the way SearchPage.tsx's own results are — this just hands
     * $filters straight to SearchService::search(), the same call GET
     * /search itself makes, so a saved PDF can never show more than the
     * requesting user could see on screen. A bare list of items with no
     * indication of what was actually searched for would be close to
     * meaningless once printed/archived, so filterSummaryLines() renders
     * every active criterion above the table — reusing the exact same
     * labels SearchFilterPanel.tsx shows for each one, not a fresh set of
     * wording invented for this one view.
     */
    public function searchResultsPdf(User $user, SearchFilters $filters): PdfDocument
    {
        $lang = $this->languageFor($user);
        $hits = $this->searchService->search($user, $filters);

        $rows = $hits->map(fn (Model $item) => [
            'title' => $item->title,
            'ean' => $item->ean,
            'library_name' => $item->library->name,
            'media_type' => $item->library->media_type,
            'extra' => $item->location,
        ])->all();

        return $this->render('pdf.search-results', $lang, [
            'title' => $filters->hasQuery()
                ? $this->tr($lang, 'search.resultsFor', ['query' => $filters->query])
                : $this->tr($lang, 'nav.search'),
            'filterSummaryTitle' => $this->tr($lang, 'search.filters.summaryTitle'),
            'filterLines' => $this->filterSummaryLines($filters, $lang),
            'noFiltersText' => $this->tr($lang, 'search.filters.noneActive'),
            'locationHeader' => $this->tr($lang, 'mediaItem.fields.location'),
            'rows' => $rows,
        ]);
    }

    /**
     * One line per active filter criterion, in the same top-to-bottom
     * order SearchFilterPanel.tsx presents them — an inactive filter
     * (empty array, both range bounds null) contributes nothing, so this
     * only ever lists what actually narrowed the result set.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function filterSummaryLines(SearchFilters $filters, string $lang): array
    {
        $lines = [];

        if ($filters->hasQuery()) {
            $value = $filters->query;
            if ($filters->fuzzy) {
                $value .= ' ('.$this->tr($lang, 'search.fuzzy').')';
            }
            $lines[] = ['label' => $this->tr($lang, 'search.filters.query'), 'value' => $value];
        }

        if ($filters->mediaTypes !== []) {
            $mediaTypeLabels = $this->mediaTypeLabels($lang);
            $lines[] = [
                'label' => $this->tr($lang, 'libraries.mediaTypeLabel'),
                'value' => implode(', ', array_map(fn (string $type) => $mediaTypeLabels[$type] ?? $type, $filters->mediaTypes)),
            ];
        }

        if ($filters->libraryIds !== []) {
            // Deliberately not re-checked against LibraryAccessService here:
            // $filters->libraryIds only ever narrows what SearchService::
            // search() above already scoped to $user's visible libraries —
            // an id for a library $user can't see simply matched nothing
            // there, so this can only ever name libraries whose items might
            // actually appear in $rows.
            $names = Library::query()->whereIn('id', $filters->libraryIds)->pluck('name');
            if ($names->isNotEmpty()) {
                $lines[] = ['label' => $this->tr($lang, 'libraries.title'), 'value' => $names->implode(', ')];
            }
        }

        if ($filters->field !== 'all') {
            $lines[] = ['label' => $this->tr($lang, 'search.filters.fieldLabel'), 'value' => $this->searchFieldLabel($filters->field, $lang)];
        }

        // GitHub issue #123's own reasoning, reused here: an attribute
        // filter only ever applies to a subset of media types, so its
        // label names which one(s) — same "Sprache (Buch)" vs.
        // "Sprache(n) (DVD/Blu-ray)" disambiguation SearchFilterPanel.tsx's
        // labelWithMediaTypes() already provides client-side.
        foreach ([
            ['field' => 'genre', 'values' => $filters->genre, 'types' => ['book']],
            ['field' => 'format', 'values' => $filters->format, 'types' => ['book']],
            ['field' => 'language', 'values' => $filters->language, 'types' => ['book']],
            ['field' => 'medium', 'values' => $filters->medium, 'types' => ['cd', 'dvd_bluray']],
            ['field' => 'languages', 'values' => $filters->languages, 'types' => ['dvd_bluray']],
        ] as $attribute) {
            if ($attribute['values'] === []) {
                continue;
            }
            $lines[] = [
                'label' => $this->attributeFilterLabel($attribute['field'], $attribute['types'], $lang),
                'value' => implode(', ', $attribute['values']),
            ];
        }

        foreach ([
            ['label' => $this->tr($lang, 'mediaItem.fields.price'), 'min' => $filters->priceMin, 'max' => $filters->priceMax],
            ['label' => $this->tr($lang, 'search.filters.year'), 'min' => $filters->yearMin, 'max' => $filters->yearMax],
            ['label' => $this->tr($lang, 'mediaItem.fields.page_count'), 'min' => $filters->pageCountMin, 'max' => $filters->pageCountMax],
            ['label' => $this->tr($lang, 'mediaItem.fields.disc_count'), 'min' => $filters->discCountMin, 'max' => $filters->discCountMax],
            ['label' => $this->tr($lang, 'mediaItem.runtime'), 'min' => $filters->runtimeMin, 'max' => $filters->runtimeMax],
        ] as $range) {
            $value = $this->formatRange($range['min'], $range['max']);
            if ($value !== null) {
                $lines[] = ['label' => $range['label'], 'value' => $value];
            }
        }

        return $lines;
    }

    /** "Sprache (Buch)" — same reasoning/labels SearchFilterPanel.tsx's labelWithMediaTypes() already uses (GitHub issue #123). */
    private function attributeFilterLabel(string $field, array $mediaTypes, string $lang): string
    {
        $mediaTypeLabels = $this->mediaTypeLabels($lang);
        $types = implode(', ', array_map(fn (string $type) => $mediaTypeLabels[$type] ?? $type, $mediaTypes));

        return $this->tr($lang, "mediaItem.fields.$field")." ({$types})";
    }

    /** Mirrors SearchFilterPanel.tsx's inline `field === '...'` <option> labels for the `field` param's value (not its own 'fieldLabel', which names the *control*, not the currently chosen scope). */
    private function searchFieldLabel(string $field, string $lang): string
    {
        return match ($field) {
            'title' => $this->tr($lang, 'mediaItem.fields.title'),
            'creator' => $this->tr($lang, 'search.filters.field.creator'),
            'description' => $this->tr($lang, 'mediaItem.fields.description'),
            'identifier' => $this->tr($lang, 'search.filters.field.identifier'),
            'location' => $this->tr($lang, 'mediaItem.fields.location'),
            'tracks' => $this->tr($lang, 'mediaItem.tracklist'),
            default => $this->tr($lang, 'search.filters.field.all'),
        };
    }

    /** "10 – 50" (both bounds), "≥ 10" / "≤ 50" (only one), or null (neither — the caller skips the line entirely). Values are already plain numbers a user typed into a filter input, not currency, so no locale-aware NumberFormatter pass — unlike formatPrice() above, this isn't displaying a media item's own price. */
    private function formatRange(int|float|null $min, int|float|null $max): ?string
    {
        return match (true) {
            $min !== null && $max !== null => $min.' – '.$max,
            $min !== null => '≥ '.$min,
            $max !== null => '≤ '.$max,
            default => null,
        };
    }

    /**
     * `preferred_language` is a non-nullable `string` column with a DB-level
     * default of 'de' (migration 2026_08_11_154053) — but that default is
     * only ever applied by the database itself on INSERT, not backfilled
     * onto the in-memory Eloquent instance that triggered it, so a
     * freshly-created User (e.g. most factory-built ones in this app's own
     * test suite, which never set this column explicitly) has `null` here
     * in PHP despite a real 'de' already sitting in the row. Falls back to
     * the same 'de' the column itself defaults to, rather than requiring
     * every call site above to null-coalesce it individually.
     */
    private function languageFor(User $user): string
    {
        return $user->preferred_language ?: 'de';
    }

    /** @param array<int, array> $rows @return array<int, array> each row with an 'extra' string column added, computed by $formatter */
    private function withExtra(array $rows, Closure $formatter): array
    {
        return array_map(fn (array $row) => [...$row, 'extra' => $formatter($row)], $rows);
    }

    /** @return array<string, string> mirrors frontend/src/i18n/locales/en.json's libraries.mediaType.* — recomputed per language rather than a fixed const now that it's translated (GitHub issue #113). */
    private function mediaTypeLabels(string $lang): array
    {
        return [
            'book' => $this->tr($lang, 'libraries.mediaType.book'),
            'cd' => $this->tr($lang, 'libraries.mediaType.cd'),
            'dvd_bluray' => $this->tr($lang, 'libraries.mediaType.dvd_bluray'),
        ];
    }

    /**
     * PHP equivalent of mediaItemFields.ts's formatPrice() (GitHub issue
     * #107) — an actual currency symbol via PHP's intl extension
     * (NumberFormatter, already installed in docker/Dockerfile's runtime
     * image alongside the other extensions this app depends on) rather than
     * a spelled-out ISO code, matching what the frontend now shows for the
     * exact same values. `$lang` picks the formatting locale (GitHub issue
     * #113 — previously always hardcoded to 'en'), the same value the
     * frontend passes as `Intl.NumberFormat`'s locale; ICU falls back to a
     * root/neutral format rather than throwing on a language code it
     * doesn't specifically recognize, so this needs no extra validation.
     * formatCurrency() itself returns false rather than throwing on a
     * currency string it can't make sense of (unlike the frontend's
     * Intl.NumberFormat, which throws on construction) — both a media
     * item's own `currency` and the admin-configured default are free-text
     * fields with no whitelist, so this can genuinely happen.
     */
    private function formatPrice(mixed $price, ?string $currency, string $lang): string
    {
        if ($price === null) {
            return '—';
        }

        if ($currency) {
            $formatted = (new NumberFormatter($lang, NumberFormatter::CURRENCY))->formatCurrency((float) $price, $currency);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return (string) $price;
    }

    /** Mirrors mediaItemFields.ts's formatDuration() (M:SS, or H:MM:SS past an hour) — used for a CD's runtime_seconds top lists. Language-agnostic (digits/colons only), unlike formatTopListValue()'s other branches. */
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

    /**
     * @param  array{value: mixed, currency?: ?string}  $row
     *
     * GitHub issue #107: takes the whole row, not just `$row['value']` —
     * the 'price' type needs $row['currency'] too, to format via
     * formatPrice() the same way every other price display now does,
     * instead of always passing null and silently discarding a known
     * currency (exactly what #107 reported).
     */
    private function formatTopListValue(string $type, array $row, string $lang): string
    {
        return match ($type) {
            'price' => $this->formatPrice($row['value'], $row['currency'] ?? null, $lang),
            'duration' => $this->formatDuration((int) $row['value']),
            'minutes' => $this->trPlural($lang, 'reports.topLists.minutes', (int) $row['value']),
            'plain' => (string) $row['value'],
        };
    }

    /**
     * "cover_path" -> "mediaItem.fields.cover_path"'s translated label, the
     * same key ReportDetailPage.tsx's own data-quality rendering looks up
     * (GitHub issue #113) — falls back to humanizeField()'s raw
     * "Cover Path"-style guess only if that key genuinely doesn't exist
     * (defensive: data-quality's missing_fields is a fixed, known whitelist
     * today, but this avoids a blank/undertranslated cell if it ever
     * isn't).
     */
    private function fieldLabel(string $field, string $lang): string
    {
        $key = "mediaItem.fields.$field";
        $label = $this->tr($lang, $key);

        return $label === $key ? $this->humanizeField($field) : $label;
    }

    /** "cover_path" -> "Cover Path" — see fieldLabel()'s docblock for why this still exists as its fallback. */
    private function humanizeField(string $field): string
    {
        return ucwords(str_replace('_', ' ', $field));
    }

    /** reports.captureSource.method.<method>, with the same defensive ucfirst() fallback fieldLabel() uses in case `capture_method` is ever set to something outside {scan, manual, unknown}. */
    private function captureMethodLabel(string $method, string $lang): string
    {
        $key = "reports.captureSource.method.$method";
        $label = $this->tr($lang, $key);

        return $label === $key ? ucfirst(str_replace('_', ' ', $method)) : $label;
    }

    /** reports.sharing.scope.<scope>, same defensive fallback as captureMethodLabel()/fieldLabel(). */
    private function scopeLabel(string $scope, string $lang): string
    {
        $key = "reports.sharing.scope.$scope";
        $label = $this->tr($lang, $key);

        return $label === $key ? ucfirst(str_replace('_', ' ', $scope)) : $label;
    }

    /** PHP port of ReportDetailPage.tsx's/MetadataMergeReview.tsx's formatProviderKey() — "book.google_books" -> "Google Books". Provider keys aren't translated strings (they're stable identifiers, not UI copy), so this is humanization, not a Translator lookup. */
    private function formatProviderKey(string $key): string
    {
        $withoutMediaType = str_contains($key, '.') ? substr($key, strpos($key, '.') + 1) : $key;

        return implode(' ', array_map(ucfirst(...), explode('_', $withoutMediaType)));
    }

    private function tr(string $lang, string $key, array $replace = []): string
    {
        return $this->translator->get($lang, $key, $replace);
    }

    private function trPlural(string $lang, string $key, int $count, array $replace = []): string
    {
        return $this->translator->plural($lang, $key, $count, $replace);
    }

    /**
     * Every PDF gets the same generated-at footnote, in the admin-configured
     * display timezone (SystemSetting::localNow(), not always UTC — same
     * reasoning ExportImportController's own filename timestamp already
     * follows) — plus (GitHub issue #113) the handful of column
     * headers/labels shared across nearly every view, computed once here
     * rather than repeated at each call site above.
     */
    private function render(string $view, string $lang, array $data): PdfDocument
    {
        $generatedAt = SystemSetting::localNow()->format('Y-m-d H:i');

        return Pdf::loadView($view, [
            ...$data,
            'mediaTypeLabels' => $this->mediaTypeLabels($lang),
            'colTitle' => $this->tr($lang, 'mediaItem.fields.title'),
            'colEan' => $this->tr($lang, 'mediaItem.fields.ean'),
            'colLibrary' => $this->tr($lang, 'reports.library'),
            'colPrice' => $this->tr($lang, 'mediaItem.fields.price'),
            'noItemsText' => $this->tr($lang, 'reports.none'),
            'generatedAtText' => $this->tr($lang, 'pdfExport.generatedAt', ['date' => $generatedAt]),
        ]);
    }
}
