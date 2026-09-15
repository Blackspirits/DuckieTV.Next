<?php

namespace App\Http\Requests\Settings;

use App\Services\TranslationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLanguageSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $availableLocales = array_map(
            static fn (string $locale): string => strtolower(str_replace('-', '_', $locale)),
            array_keys(app(TranslationService::class)->getAvailableLocales())
        );

        return [
            'application.locale' => ['required', 'string', Rule::in($availableLocales)],
        ];
    }
}
