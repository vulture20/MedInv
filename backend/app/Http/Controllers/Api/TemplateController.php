<?php

namespace App\Http\Controllers\Api;

use App\Domain\Templates\BundledTemplateRegistry;
use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin-managed additional UI templates beyond the bundled light/dark
 * (briefing 10./11.4, GitHub issue #11). index()/store()/update()/destroy()
 * are plain CRUD with no business rules beyond the reserved-code check in
 * store(); the other exception is bundled()/installBundled(), which
 * delegate to BundledTemplateRegistry (repo-shipped templates/*.json
 * files, pre-installed on fresh boot via DatabaseSeeder — this pair is
 * what lets an admin (re)install one on demand afterwards instead).
 * Deliberate structural mirror of LanguagePackController — see that
 * class's docblock and the templates table migration for the reasoning
 * this one shares.
 *
 * A template's payload (`css`) is a raw CSS text blob, not a fixed set of
 * color values — an earlier version of this feature validated a required
 * 9-key `colors` object, but that was replaced (see the
 * 2026_08_15_100000_replace_template_colors_with_css migration) so admins
 * get real theming power ("complete CSS files", not just a handful of
 * color pickers). There is deliberately no schema validation of `css`'s
 * *content* beyond "non-empty string within a sane size limit" — unlike
 * the old colors map, an incomplete/unusual CSS file doesn't corrupt
 * anything, it just styles less (or differently) than intended, which is
 * the admin's call to make.
 *
 * index()/show() are registered fully outside the auth:sanctum group in
 * routes/api.php, same reasoning as GET /languages(/{languagePack}):
 * a visitor's chosen template must be renderable on the login screen
 * itself, before anyone is authenticated. Every other method here is the
 * actual admin enforcement this issue asks for, registered under the
 * existing level:admin group.
 */
class TemplateController extends Controller
{
    /**
     * 200,000 characters (~200 KB) — generous for even a large hand-written
     * theme (the bundled ones here are a few KB each) while still keeping a
     * malicious/accidental paste from ballooning the `templates` table or
     * the page's DOM once injected as a <style> element.
     */
    private const MAX_CSS_LENGTH = 200000;

    public function __construct(private readonly BundledTemplateRegistry $bundledRegistry) {}

    public function index()
    {
        return Template::query()->orderBy('name')->get(['code', 'name']);
    }

    public function show(Template $template)
    {
        return $template;
    }

    /**
     * `light`/`dark` are rejected case-insensitively — those codes belong
     * to the two bundled templates (frontend/src/index.css's static
     * `:root`/`:root[data-template='dark']` rules), which this table has
     * no row for at all and isn't meant to override.
     *
     * GitHub issue #198: the reserved-code and already-taken-code checks
     * are manual (not the `Rule::notIn`/`unique` validation rules this
     * used before) so each gets its own translated error_code
     * (`code_reserved`/`code_taken`, adminErrors.ts, shared with
     * LanguagePackController::store()'s identical pattern) instead of
     * Laravel's raw, untranslated validation message leaking through
     * describeError()'s generic fallback.
     */
    public function store(Request $request)
    {
        $request->merge(['code' => strtolower((string) $request->input('code'))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'css' => ['required', 'string', 'max:'.self::MAX_CSS_LENGTH],
        ]);

        if (in_array($data['code'], ['light', 'dark'], true)) {
            return $this->errorResponse($request, 'code_reserved', 'This code is reserved and cannot be used.');
        }
        if (Template::query()->where('code', $data['code'])->exists()) {
            return $this->errorResponse($request, 'code_taken', 'This code is already in use.');
        }

        $template = Template::query()->create($data);

        Log::info('Template created', ['actor_id' => $request->user()->id, 'code' => $template->code, 'name' => $template->name]);

        return response()->json($template, 201);
    }

    /** `code` is immutable once created (like LanguagePack's `code` / MetadataPlugin's `provider_key`) — not accepted here. */
    public function update(Request $request, Template $template)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'css' => ['sometimes', 'string', 'max:'.self::MAX_CSS_LENGTH],
        ]);

        $template->update($data);

        Log::info('Template updated', [
            'actor_id' => $request->user()->id,
            'code' => $template->code,
            'name' => $data['name'] ?? null,
            'css_changed' => array_key_exists('css', $data),
        ]);

        return $template;
    }

    public function destroy(Request $request, Template $template)
    {
        Log::info('Template deleted', ['actor_id' => $request->user()->id, 'code' => $template->code, 'name' => $template->name]);
        $template->delete();

        return response()->noContent();
    }

    /**
     * Lists every templates/*.json file the repo/image ships, each flagged
     * with whether it already has a database row — lets TemplatesPage.tsx
     * show "already installed" instead of offering a pointless reinstall-
     * with-no-visible-effect button.
     */
    public function bundled()
    {
        $installedCodes = Template::query()->pluck('code');

        return collect($this->bundledRegistry->available())
            ->map(fn (array $template) => [...$template, 'installed' => $installedCodes->contains($template['code'])])
            ->values();
    }

    /**
     * Installs (or reinstalls) one bundled template. Unlike DatabaseSeeder's
     * boot-time installMissing() (which never touches a template an admin
     * has since edited), this is a deliberate admin action — see
     * BundledTemplateRegistry::install()'s docblock — so it always
     * overwrites name/css from the shipped file.
     */
    public function installBundled(Request $request, string $code)
    {
        $template = $this->bundledRegistry->install($code);
        abort_if($template === null, 404);

        Log::info('Bundled template installed', ['actor_id' => $request->user()->id, 'code' => $template->code, 'name' => $template->name]);

        return response()->json($template, 201);
    }
}
