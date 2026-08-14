<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LanguagePack;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-managed additional UI language packs beyond the bundled German/
 * English (briefing 11.4/17., GitHub issue #12 — #15 is the frontend admin
 * UI + runtime loading). Plain CRUD with no business rules beyond the
 * reserved-code check in store(), so — like
 * MetadataController::updatePlugin() — this skips a dedicated Domain
 * service.
 *
 * index()/show() are registered fully outside the auth:sanctum group in
 * routes/api.php (not just outside level:admin, the way GET
 * /metadata/plugins used to sit before GitHub issue #37) — translations
 * must be loadable on the login screen itself, before anyone is
 * authenticated. create()/update()/destroy() are the actual enforcement
 * this issue asks for, registered under the existing level:admin group.
 */
class LanguagePackController extends Controller
{
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
     */
    public function store(Request $request)
    {
        $request->merge(['code' => strtolower((string) $request->input('code'))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', 'unique:language_packs,code', Rule::notIn(['de', 'en'])],
            'name' => ['required', 'string', 'max:255'],
            // Deliberately no nested 'translations.*' rule alongside this
            // top-level 'array' one — combining the two makes Laravel treat
            // `translations` as "structured" and silently drop every key
            // from validate()'s output except whatever 'translations.*'
            // itself names, the same validation pitfall already documented
            // on MetadataController::import()'s `attributes` field.
            'translations' => ['required', 'array', 'min:1'],
        ]);

        return response()->json(LanguagePack::query()->create($data), 201);
    }

    /** `code` is immutable once created (like MetadataPlugin's `provider_key`) — not accepted here. */
    public function update(Request $request, LanguagePack $languagePack)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'translations' => ['sometimes', 'array', 'min:1'],
        ]);

        $languagePack->update($data);

        return $languagePack;
    }

    public function destroy(LanguagePack $languagePack)
    {
        $languagePack->delete();

        return response()->noContent();
    }
}
