<?php

namespace App\Support\Settings;

use Illuminate\Validation\Rules\Password;

/**
 * PasswordRules
 *
 * Builds the live password rule from config/annigems.php so every Form
 * Request (change, reset, staff create, SuperAdmin create-admin) enforces
 * the same minimum length and mixed-case flag. These rules are not
 * SuperAdmin-editable.
 */
class PasswordRules
{
    /**
     * Rule for a new or changed password.
     *
     * Letters and numbers are always required. Mixed case follows
     * config('annigems.password.require_mixed_case').
     */
    public static function rule(): Password
    {
        $min = max(6, (int) config('annigems.password.min_length', 8));
        $rule = Password::min($min)->letters()->numbers();

        if (config('annigems.password.require_mixed_case', true)) {
            $rule = $rule->mixedCase();
        }

        return $rule;
    }

    /**
     * Minimum length for help text on password screens.
     */
    public static function minLength(): int
    {
        return max(6, (int) config('annigems.password.min_length', 8));
    }

    /**
     * Operator-facing sentence that matches the live rule.
     */
    public static function helpText(): string
    {
        $parts = ['at least '.self::minLength().' characters', 'a letter', 'a number'];

        if (config('annigems.password.require_mixed_case', true)) {
            $parts[] = 'both upper and lower case';
        }

        return 'Password must be '.implode(', ', $parts).'.';
    }
}
