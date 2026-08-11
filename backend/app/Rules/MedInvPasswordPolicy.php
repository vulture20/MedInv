<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Password policy from briefing 12.1: at least 10 characters, with at least
 * one uppercase letter, one lowercase letter, one digit and one special
 * character. Used for admin-created accounts and password resets alike.
 */
class MedInvPasswordPolicy implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) < 10) {
            $fail('The :attribute must be at least 10 characters long.');

            return;
        }

        if (! preg_match('/[A-Z]/', $value)) {
            $fail('The :attribute must contain at least one uppercase letter.');
        }

        if (! preg_match('/[a-z]/', $value)) {
            $fail('The :attribute must contain at least one lowercase letter.');
        }

        if (! preg_match('/[0-9]/', $value)) {
            $fail('The :attribute must contain at least one digit.');
        }

        if (! preg_match('/[^A-Za-z0-9]/', $value)) {
            $fail('The :attribute must contain at least one special character.');
        }
    }
}
