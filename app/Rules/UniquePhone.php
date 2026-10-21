<?php

namespace App\Rules;

use App\Services\PhoneIdentity;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniquePhone implements ValidationRule
{
    public function __construct(private ?int $exceptId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumber::canonical($value) === null) {
            $fail('Введите номер телефона из 7–15 цифр. Можно использовать +, пробелы, скобки и дефисы.');
        } elseif (app(PhoneIdentity::class)->matches($value, $this->exceptId)->isNotEmpty()) {
            $fail('Этот номер телефона уже используется.');
        }
    }
}
