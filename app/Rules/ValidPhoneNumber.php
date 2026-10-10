<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

// Validates a phone number is a genuinely valid number for its country (correct
// length/format per libphonenumber's per-country metadata), not just any string
// under a max length. Expects E.164 input (e.g. "+14155552671") — the format
// the frontend's shared PhoneInput component always emits.
class ValidPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        try {
            $parsed = PhoneNumberUtil::getInstance()->parse($value, null);

            if (!PhoneNumberUtil::getInstance()->isValidNumber($parsed)) {
                $fail('The :attribute is not a valid phone number.');
            }
        } catch (NumberParseException $e) {
            $fail('The :attribute is not a valid phone number.');
        }
    }
}
