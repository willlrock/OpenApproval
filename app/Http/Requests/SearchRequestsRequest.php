<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authentication and role checks remain in route middleware.
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:200', 'not_regex:/\x00/']];
    }

    public function messages(): array
    {
        return ['search.string' => 'Введите текст для поиска.', 'search.max' => 'Поисковый запрос должен быть не длиннее 200 символов.',
            'search.not_regex' => 'В поисковом запросе есть недопустимый символ.'];
    }
}
