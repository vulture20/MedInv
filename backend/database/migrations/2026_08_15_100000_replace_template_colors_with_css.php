<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates were originally a fixed 9-key `colors` map
 * (Template::REQUIRED_COLOR_KEYS, one JSON column) applied as inline CSS
 * custom properties. A user asked for real theming power instead — "not
 * just color values, but complete CSS files" — so a template's payload
 * becomes a single `css` text blob instead: the literal content of a
 * `<style>` element, injected/cleared by ThemeContext.tsx whenever that
 * template is selected. This is a strictly more capable superset of the
 * old shape (any admin who only wants to redefine the same 9
 * `--color-*` custom properties still can — see templates/README.md's
 * recommended `:root { --color-bg: ...; }` pattern — but nothing stops
 * them from overriding fonts, spacing, or any other selector too).
 *
 * The `templates` table this alters was only added in the immediately
 * preceding commit (GitHub issue #11) and ships no bundled rows by
 * default (templates/ was empty), so there is no real production data to
 * migrate — this is a clean drop-and-add rather than a value conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('colors');
            $table->longText('css')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('css');
            $table->json('colors')->after('name');
        });
    }
};
