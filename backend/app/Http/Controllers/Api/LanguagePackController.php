<?php

namespace App\Http\Controllers\Api;

use App\Domain\Languages\BundledLanguagePackRegistry;
use App\Http\Controllers\Controller;
use App\Models\LanguagePack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin-managed additional UI language packs beyond the bundled German/
 * English (briefing 11.4/17., GitHub issue #12 — #15 is the frontend admin
 * UI + runtime loading). index()/store()/update()/destroy() are plain CRUD
 * with no business rules beyond the reserved-code check in store(); the
 * one exception is bundled()/installBundled(), which delegate to
 * BundledLanguagePackRegistry (repo-shipped languagepacks/*.json files,
 * pre-installed on fresh boot via DatabaseSeeder — this pair is what lets
 * an admin (re)install one on demand afterwards instead).
 *
 * index()/show() are registered fully outside the auth:sanctum group in
 * routes/api.php (not just outside level:admin, the way GET
 * /metadata/plugins used to sit before GitHub issue #37) — translations
 * must be loadable on the login screen itself, before anyone is
 * authenticated. Every other method here is the actual admin enforcement
 * this issue asks for, registered under the existing level:admin group.
 */
class LanguagePackController extends Controller
{
    public function __construct(private readonly BundledLanguagePackRegistry $bundledRegistry) {}

    public function index()
    {
        return LanguagePack::query()->orderBy('name')->get(['code', 'name']);
    }

    public function show(LanguagePack $languagePack)
    {
        return $languagePack;
    }

    /**
     * `de`/`en` are rejected case-insensitively — those codes belong to the
     * two bundled packs (frontend/src/i18n/locales/{de,en}.json), which
     * this table has no row for at all and isn't meant to override.
     *
     * GitHub issue #198: the reserved-code and already-taken-code checks
     * are manual (not the `Rule::notIn`/`unique` validation rules this
     * used before) so each gets its own translated error_code
     * (`code_reserved`/`code_taken`, adminErrors.ts) instead of Laravel's
     * raw, untranslated validation message leaking through describeError()'s
     * generic fallback — see UserController::store()'s own docblock for the
     * identical reasoning applied there for a duplicate email.
     */
    public function store(Request $request)
    {
        $request->merge(['code' => strtolower((string) $request->input('code'))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:255'],
            // Deliberately no nested 'translations.*' rule alongside this
            // top-level 'array' one — combining the two makes Laravel treat
            // `translations` as "structured" and silently drop every key
            // from validate()'s output except whatever 'translations.*'
            // itself names, the same validation pitfall already documented
            // on MetadataController::import()'s `attributes` field.
            'translations' => ['required', 'array', 'min:1'],
        ]);

        if (in_array($data['code'], ['de', 'en'], true)) {
            return $this->errorResponse($request, 'code_reserved', 'This code is reserved and cannot be used.');
        }
        if (LanguagePack::query()->where('code', $data['code'])->exists()) {
            return $this->errorResponse($request, 'code_taken', 'This code is already in use.');
        }

        $pack = LanguagePack::query()->create($data);

        // No full `translations` blob in the log — it's the bulk of the
        // payload and not something worth keeping a copy of here, just
        // enough to say what changed (name/key count) and by whom.
        Log::info('Language pack created', ['actor_id' => $request->user()->id, 'code' => $pack->code, 'name' => $pack->name]);

        return response()->json($pack, 201);
    }

    /** `code` is immutable once created (like MetadataPlugin's `provider_key`) — not accepted here. */
    public function update(Request $request, LanguagePack $languagePack)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'translations' => ['sometimes', 'array', 'min:1'],
        ]);

        $languagePack->update($data);

        // No full `translations` blob in the log, same reasoning as store()
        // above — just whether it changed, not its contents.
        Log::info('Language pack updated', [
            'actor_id' => $request->user()->id,
            'code' => $languagePack->code,
            'name' => $data['name'] ?? null,
            'translations_changed' => array_key_exists('translations', $data),
        ]);

        return $languagePack;
    }

    public function destroy(Request $request, LanguagePack $languagePack)
    {
        Log::info('Language pack deleted', ['actor_id' => $request->user()->id, 'code' => $languagePack->code, 'name' => $languagePack->name]);
        $languagePack->delete();

        return response()->noContent();
    }

    /**
     * Lists every languagepacks/*.json file the repo/image ships, each
     * flagged with whether it already has a database row — lets
     * LanguagesPage.tsx show "already installed" instead of offering a
     * pointless reinstall-with-no-visible-effect button.
     */
    public function bundled()
    {
        $installedCodes = LanguagePack::query()->pluck('code');

        return collect($this->bundledRegistry->available())
            ->map(fn (array $pack) => [...$pack, 'installed' => $installedCodes->contains($pack['code'])])
            ->values();
    }

    /**
     * Installs (or reinstalls) one bundled pack. Unlike DatabaseSeeder's
     * boot-time installMissing() (which never touches a pack an admin has
     * since edited), this is a deliberate admin action — see
     * BundledLanguagePackRegistry::install()'s docblock — so it always
     * overwrites name/translations from the shipped file.
     */
    public function installBundled(Request $request, string $code)
    {
        $pack = $this->bundledRegistry->install($code);
        abort_if($pack === null, 404);

        Log::info('Bundled language pack installed', ['actor_id' => $request->user()->id, 'code' => $pack->code, 'name' => $pack->name]);

        return response()->json($pack, 201);
    }
}
