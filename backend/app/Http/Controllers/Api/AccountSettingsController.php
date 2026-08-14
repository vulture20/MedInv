<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The logged-in user's own preferences (briefing 4.1: "benutzerdefinierte
 * Einstellungen", e.g. preferred template/language, editable from the user
 * menu). Distinct from UserController, which is admin-only account
 * management of *other* users.
 */
class AccountSettingsController extends Controller
{
    public function update(Request $request)
    {
        // GitHub issue #11: 'light'/'dark' (the two bundled templates,
        // frontend/src/index.css) plus any code with a `templates` row —
        // admin-added or one of the repo-shipped templates/*.json files
        // (BundledTemplateRegistry), it makes no difference here, since
        // both end up as the same kind of row. Computed fresh on every
        // request rather than cached, same reasoning as
        // AdminSettingsController::updateLocale()'s identical fix: this
        // setting is small and rarely changed, so the extra query per save
        // isn't worth optimizing away, and a since-deleted template can't
        // still be picked.
        $allowedTemplates = [...['light', 'dark'], ...Template::query()->pluck('code')->all()];

        $data = $request->validate([
            'preferred_language' => ['sometimes', 'string', 'max:10'],
            'preferred_template' => ['sometimes', Rule::in($allowedTemplates)],
        ]);

        $user = $request->user();
        $user->update($data);

        return $user;
    }
}
