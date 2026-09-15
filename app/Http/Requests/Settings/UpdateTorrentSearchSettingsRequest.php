<?php

namespace App\Http\Requests\Settings;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTorrentSearchSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $searchEngines = array_keys(app(\App\Services\TorrentSearchService::class)->getSearchEngines());
        $qualities = settings()->get('torrenting.searchqualitylist', []);
        $qualities = is_array($qualities) ? array_values(array_filter($qualities, 'is_string')) : [];

        return [
            'torrenting.searchprovider' => [
                'sometimes',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($searchEngines): void {
                    if (! is_string($value) || ! in_array($value, $searchEngines, true)) {
                        $fail("The {$attribute} field is invalid.");
                    }
                },
            ],
            'torrenting.global_size_min' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'torrenting.global_size_max' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'torrenting.ignore_keywords' => ['sometimes', 'nullable', 'string'],
            'torrenting.require_keywords' => ['sometimes', 'nullable', 'string'],
            'torrenting.require_keywords_mode_or' => ['sometimes', 'boolean'],
            'torrenting.searchquality' => ['sometimes', 'nullable', 'string', Rule::in($qualities)],
            'torrenting.min_seeders' => ['sometimes', 'integer', 'min:0', 'max:3000'],
        ];
    }
}
