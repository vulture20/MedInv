<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        // TODO: 'light'/'dark' are the two ship-with templates (briefing 10./11.4);
        // once installable templates exist, validate against the registered set
        // instead of this hardcoded list.
        $data = $request->validate([
            'preferred_language' => ['sometimes', 'string', 'max:10'],
            'preferred_template' => ['sometimes', Rule::in(['light', 'dark'])],
        ]);

        $user = $request->user();
        $user->update($data);

        return $user;
    }
}
