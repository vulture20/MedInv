<?php

namespace App\Domain\Libraries;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GitHub issue #64: a direct follow-up to #62/#58. #62 was implemented via
 * its "Variante 3" — a global `statistics.default_currency` setting plus a
 * `currency_mismatch` flag (StatisticsService::overviewFor()) that only
 * *warns* when a library holds items in more than one currency, without
 * fixing `total_value`'s plain `sum('price')` staying meaningless for a
 * genuinely mixed-currency library. #64 asks for one of #62's two
 * higher-effort alternatives (group totals per currency, or extend the
 * mismatch flag everywhere) to actually be implemented.
 *
 * This takes a third path, agreed as this issue's resolution: instead of
 * teaching every consumer of `price` (overview, snapshots, estimated
 * series, and any future one) to handle multiple currencies, a newly
 * captured item's price is converted into the configured default currency
 * once, at entry time, using a live exchange rate — so `currency` is the
 * default for every item captured from here on, `sum('price')` stays
 * correct without changes anywhere else, and `currency_mismatch` will
 * naturally stop firing for new captures. This does not touch items
 * already stored before this feature existed (still shown via the
 * existing mismatch warning, unchanged) — converting historical prices
 * retroactively against *today's* rate would silently rewrite what was
 * actually paid, a materially different feature this issue's "bei neuen
 * Medien-Einträgen" scope does not ask for.
 *
 * Deliberately wired into the two genuinely-new-capture entry points
 * (MediaItemController::store(), MetadataController::import()) rather than
 * into MediaItemService::create() itself, even though that would reach
 * fewer call sites — create() is also ExportImportService's backup/import
 * restore path, which must reproduce a backup's stored values exactly, not
 * re-convert already-historical, already-correct prices against whatever
 * the exchange rate happens to be on restore day.
 *
 * Uses Frankfurter.dev (frankfurter.dev — ECB reference rates, free,
 * unauthenticated, no API key/config field needed, unlike most metadata
 * providers), per this issue's own suggestion. `/v1/latest?base=...&symbols=...`'s
 * shape was confirmed live rather than assumed from memory/docs: a real
 * request returns `{"amount":1.0,"base":"USD","date":"...","rates":{"EUR":0.86...}}`,
 * and an unknown/invalid currency code returns a 404 with `{"message":"not
 * found"}` — both handled explicitly by rate() below.
 */
class CurrencyConversionService
{
    private const API_URL = 'https://api.frankfurter.dev/v1/latest';

    // Frankfurter.dev's rates are ECB reference rates, published once per
    // business day — caching for an hour avoids a real HTTP round-trip on
    // every single item captured in a session without ever serving a
    // meaningfully stale rate.
    private const CACHE_TTL_MINUTES = 60;

    /**
     * Returns $attributes unchanged unless a default currency is
     * configured (`statistics.default_currency`, nullable — null means the
     * feature is off entirely, same precedent as `currency_mismatch`
     * itself), the item actually carries both a price and a currency, and
     * that currency differs from the default. A failed or unusable rate
     * lookup (network error, unknown/malformed currency code, ...) also
     * leaves $attributes untouched rather than blocking capture — an
     * optional enrichment step failing must never be the reason a capture
     * itself fails, the same precedent CoverDownloadService's cover
     * fetching already sets.
     */
    public function convertToDefaultCurrency(array $attributes): array
    {
        $defaultCurrency = SystemSetting::get('statistics.default_currency');
        $price = $attributes['price'] ?? null;
        $currency = $attributes['currency'] ?? null;

        if ($defaultCurrency === null || $price === null || $currency === null) {
            return $attributes;
        }

        $defaultCurrency = strtoupper($defaultCurrency);
        $currency = strtoupper($currency);

        if ($currency === $defaultCurrency) {
            return $attributes;
        }

        $rate = $this->rate($currency, $defaultCurrency);

        if ($rate === null) {
            return $attributes;
        }

        return [
            ...$attributes,
            'price' => round($price * $rate, 2),
            'currency' => $defaultCurrency,
        ];
    }

    /** Null on any failure — logged, never thrown, per this class's docblock. */
    private function rate(string $from, string $to): ?float
    {
        return Cache::remember(
            "currency_rate:{$from}:{$to}",
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($from, $to) {
                try {
                    $response = Http::get(self::API_URL, ['base' => $from, 'symbols' => $to]);

                    if ($response->failed()) {
                        Log::warning('Currency conversion rate lookup failed.', [
                            'from' => $from, 'to' => $to, 'status' => $response->status(),
                        ]);

                        return null;
                    }

                    $rate = $response->json("rates.{$to}");

                    if (! is_numeric($rate)) {
                        Log::warning('Currency conversion rate lookup returned no usable rate.', [
                            'from' => $from, 'to' => $to, 'body' => $response->body(),
                        ]);

                        return null;
                    }

                    return (float) $rate;
                } catch (\Throwable $e) {
                    Log::warning('Currency conversion rate lookup threw.', [
                        'from' => $from, 'to' => $to, 'exception' => $e->getMessage(),
                    ]);

                    return null;
                }
            }
        );
    }
}
