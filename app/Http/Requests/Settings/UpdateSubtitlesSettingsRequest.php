<?php

namespace App\Http\Requests\Settings;

use App\Services\SubtitlesService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubtitlesSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $supportedLanguages = array_keys(app(SubtitlesService::class)->getLanguages());

        return [
            'subtitles.languages' => ['present', 'array'],
            'subtitles.languages.*' => ['string', Rule::in($supportedLanguages)],
        ];
    }
}
