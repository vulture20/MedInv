<?php

namespace App\Http\Controllers\Api;

use App\Domain\Templates\BundledTemplateRegistry;
use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Admin-managed additional UI templates beyond the bundled light/dark
 * (briefing 10./11.4, GitHub issue #11). index()/store()/update()/destroy()
 * are plain CRUD with no business rules beyond the reserved-code check in
 * store() and the required-color-key check shared by store()/update(); the
 * other exception is bundled()/installBundled(), which delegate to
 * BundledTemplateRegistry (repo-shipped templates/*.json files, pre-
 * installed on fresh boot via DatabaseSeeder — this pair is what lets an
 * admin (re)install one on demand afterwards instead). Deliberate
 * structural mirror of LanguagePackController — see that class's docblock
 * and the templates table migration for the reasoning this one shares.
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
     * no row for at all and isn't meant to override. `colors` must contain
     * every key in Template::REQUIRED_COLOR_KEYS — unlike a language
     * pack's `translations` (allowed to be partial, i18next falls back
     * gracefully per missing key), a template missing a color just leaves
     * that one UI element unstyled rather than degrading gracefully, so
     * this is validated up front instead of silently shipping a
     * half-broken template.
     */
    public function store(Request $request)
    {
        $request->merge(['code' => strtolower((string) $request->input('code'))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:templates,code', Rule::notIn(['light', 'dark'])],
            'name' => ['required', 'string', 'max:255'],
            // Deliberately no nested 'colors.*' rule alongside this
            // top-level 'array' one — same validation pitfall documented on
            // MetadataController::import()'s `attributes` field and
            // LanguagePackController::store()'s `translations`; the
            // required-key check below is a plain closure instead, for the
            // same reason.
            'colors' => ['required', 'array', self::requiredColorKeysRule()],
        ]);

        $template = Template::query()->create($data);

        Log::info('Template created', ['actor_id' => $request->user()->id, 'code' => $template->code, 'name' => $template->name]);

        return response()->json($template, 201);
    }

    /** `code` is immutable once created (like LanguagePack's `code` / MetadataPlugin's `provider_key`) — not accepted here. */
    public function update(Request $request, Template $template)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'colors' => ['sometimes', 'array', self::requiredColorKeysRule()],
        ]);

        $template->update($data);

        Log::info('Template updated', [
            'actor_id' => $request->user()->id,
            'code' => $template->code,
            'name' => $data['name'] ?? null,
            'colors_changed' => array_key_exists('colors', $data),
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
     * overwrites name/colors from the shipped file.
     */
    public function installBundled(Request $request, string $code)
    {
        $template = $this->bundledRegistry->install($code);
        abort_if($template === null, 404);

        Log::info('Bundled template installed', ['actor_id' => $request->user()->id, 'code' => $template->code, 'name' => $template->name]);

        return response()->json($template, 201);
    }

    private static function requiredColorKeysRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $missing = array_diff(Template::REQUIRED_COLOR_KEYS, array_keys(is_array($value) ? $value : []));
            if ($missing !== []) {
                $fail('The colors field is missing required key(s): '.implode(', ', $missing).'.');
            }
        };
    }
}
