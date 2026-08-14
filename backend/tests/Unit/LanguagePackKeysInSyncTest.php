<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards against a class of drift caught (and fixed) twice while building
 * out languagepacks/*.json: a new UI string gets added to
 * frontend/src/i18n/locales/{de,en}.json, but the repo-shipped language
 * packs don't get the same key — so an admin who installs, say, French
 * silently sees English-fallback text for that one new string instead of a
 * real translation, with nothing anywhere flagging it.
 *
 * Pure static-file comparison, no Laravel app/database involved — a plain
 * PHPUnit\Framework\TestCase, like CidrMatcherTest/FuzzyTextMatcherTest,
 * not Tests\TestCase (deliberately: this class's data providers run during
 * PHPUnit's test *collection* phase, before any Laravel bootstrap exists —
 * an earlier version of this test called base_path() from a data provider
 * and silently collected zero test cases for it, since the framework
 * container isn't available that early. __DIR__-relative paths sidestep
 * that entirely.) Lives in the backend suite since that's the only test
 * runner this project has wired up at all (the frontend has none, see
 * frontend/package.json's "scripts").
 */
class LanguagePackKeysInSyncTest extends TestCase
{
    private const EN_LOCALE = __DIR__.'/../../../frontend/src/i18n/locales/en.json';

    private const DE_LOCALE = __DIR__.'/../../../frontend/src/i18n/locales/de.json';

    private const LANGUAGEPACKS_DIR = __DIR__.'/../../../languagepacks';

    public static function bundledLanguagePackFileProvider(): array
    {
        $files = glob(self::LANGUAGEPACKS_DIR.'/*.json') ?: [];
        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    public function test_the_two_bundled_locale_files_have_identical_key_sets(): void
    {
        $en = array_keys(self::flatten(self::readJson(self::EN_LOCALE)));
        $de = array_keys(self::flatten(self::readJson(self::DE_LOCALE)));

        $this->assertSame([], array_values(array_diff($en, $de)), 'en.json has keys missing from de.json.');
        $this->assertSame([], array_values(array_diff($de, $en)), 'de.json has keys missing from en.json.');
    }

    #[DataProvider('bundledLanguagePackFileProvider')]
    public function test_bundled_language_pack_has_exactly_the_keys_the_bundled_locales_have(string $file): void
    {
        $enKeys = array_keys(self::flatten(self::readJson(self::EN_LOCALE)));

        $pack = self::readJson($file);
        $this->assertArrayHasKey('translations', $pack, basename($file).' is missing a top-level "translations" key.');
        $packKeys = array_keys(self::flatten($pack['translations']));

        $missing = array_values(array_diff($enKeys, $packKeys));
        $extra = array_values(array_diff($packKeys, $enKeys));

        $this->assertSame([], $missing, basename($file).' is missing key(s) present in en.json: '.implode(', ', $missing));
        $this->assertSame([], $extra, basename($file)." has key(s) not present in en.json (stale or typo'd): ".implode(', ', $extra));
    }

    /**
     * Catches a narrower drift than the key-set check above: a translated
     * value that dropped, renamed, or mistyped a `{{placeholder}}` the
     * English original interpolates — i18next silently renders the literal
     * "{{name}}" text (or drops real data) instead of throwing, so nothing
     * else would ever surface this.
     */
    #[DataProvider('bundledLanguagePackFileProvider')]
    public function test_bundled_language_pack_preserves_every_interpolation_placeholder(string $file): void
    {
        $en = self::flatten(self::readJson(self::EN_LOCALE));
        $pack = self::flatten(self::readJson($file)['translations'] ?? []);

        foreach ($en as $key => $value) {
            if (! is_string($value) || ! is_string($pack[$key] ?? null)) {
                continue; // a missing/wrong-typed key is already reported by the key-set test above
            }

            $expected = self::placeholders($value);
            $actual = self::placeholders($pack[$key]);
            sort($expected);
            sort($actual);

            $this->assertSame(
                $expected,
                $actual,
                basename($file)." key \"{$key}\" has mismatched {{placeholders}}: expected [".implode(', ', $expected).'], got ['.implode(', ', $actual).'].',
            );
        }
    }

    private static function readJson(string $path): array
    {
        $data = json_decode(file_get_contents($path), true);
        if (! is_array($data)) {
            throw new \RuntimeException("Failed to parse as a JSON object: {$path}");
        }

        return $data;
    }

    /** Recursively flattens a nested translations array into dot.path => value pairs. */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $result += self::flatten($value, $path);
            } else {
                $result[$path] = $value;
            }
        }

        return $result;
    }

    /** @return string[] */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $text, $matches);

        return $matches[1];
    }
}
