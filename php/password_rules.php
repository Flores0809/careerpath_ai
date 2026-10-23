<?php
/**
 * Shared password complexity rules.
 * At least 8 characters, one capital letter, one number, one special character.
 */
const PASSWORD_HINT = 'At least 8 characters with a capital letter, a number and a special character (e.g. @, #, !).';
const PASSWORD_PATTERN = '(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])(?!.*\s).{8,}';

/** Returns an error message, or null when the password is acceptable. */
function password_strength_error(string $pw): ?string
{
    if (strlen($pw) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (preg_match('/\s/', $pw)) {
        return 'Password must not contain spaces.';
    }
    if (!preg_match('/[A-Z]/', $pw)) {
        return 'Password must contain at least one capital letter.';
    }
    if (!preg_match('/[0-9]/', $pw)) {
        return 'Password must contain at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9\s]/', $pw)) {
        return 'Password must contain at least one special character (e.g. @, #, !).';
    }
    return null;
}
