<?php

namespace App\Support;

/**
 * Shared validation rules and plain-English messages so every form checks the same things the same way.
 */
class Rules
{
    public const PHONE = '/^\+?[0-9][0-9\s\-().]{6,18}[0-9]$/';

    public const KRA_PIN = '/^[AP][0-9]{9}[A-Z]$/i';

    /** A person's name: letters, spaces and . ' - only. */
    public const PERSON = "/^[\\pL\\pM][\\pL\\pM .'\\-]*$/u";

    /** Must contain at least one letter (company names may hold digits and symbols). */
    public const HAS_LETTER = '/\\pL/u';

    public static function phone(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:20', 'regex:'.self::PHONE];
    }

    public static function kraPin(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:11', 'regex:'.self::KRA_PIN];
    }

    public static function person(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'min:2', 'max:100', 'regex:'.self::PERSON];
    }

    public static function company(): array
    {
        return ['required', 'string', 'min:2', 'max:150', 'regex:'.self::HAS_LETTER];
    }

    public static function email(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'email:rfc', 'max:255', 'regex:/^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/'];
    }

    public static function money(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'];
    }

    public static function text(int $max = 255, bool $required = false, int $min = 0): array
    {
        $r = [$required ? 'required' : 'nullable', 'string', 'max:'.$max];
        if ($min) {
            $r[] = 'min:'.$min;
        }

        return $r;
    }

    public static function messages(): array
    {
        return [
            'required' => 'This field is required.',
            'required_if' => 'This field is required.',
            'required_with' => 'This field is required.',
            'required_unless' => 'This field is required.',
            'email' => 'Enter a valid email address, like name@company.com.',
            'unique' => 'This is already in use. Enter a different one.',
            'exists' => 'Choose one from the list.',
            'in' => 'Choose one from the list.',
            'integer' => 'Enter a whole number.',
            'numeric' => 'Enter a number, digits only.',
            'decimal' => 'Use at most two decimal places.',
            'date' => 'Enter a valid date.',
            'confirmed' => 'The two entries do not match.',
            'after' => 'This date must come later.',
            'after_or_equal' => 'This date is too early.',
            'before_or_equal' => 'This date cannot be in the future.',
            'min.string' => 'Too short: enter at least :min characters.',
            'max.string' => 'Too long: use at most :max characters.',
            'min.numeric' => 'The smallest allowed is :min.',
            'max.numeric' => 'The largest allowed is :max.',
            'min.array' => 'Add at least :min.',
            'file' => 'Choose a valid file.',
            'mimes' => 'Wrong file type. Allowed: :values.',
            'max.file' => 'File too large. The limit is :max KB.',
            'regex' => 'That does not look right. Check the format.',
            'phone.regex' => 'Enter a valid phone number, like 0712 345 678 or +254 712 345 678.',
            '*.phone.regex' => 'Enter a valid phone number, like 0712 345 678 or +254 712 345 678.',
        ];
    }
}
