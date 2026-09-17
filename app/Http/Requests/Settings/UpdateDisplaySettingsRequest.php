<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDisplaySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'download.ratings' => 'boolean',
            'series.not-watched-eps-btn' => 'boolean',
            'library.seriesgrid' => 'boolean',
            'background-rotator.opacity' => 'numeric|min:0|max:1',
            'font.bebas.enabled' => 'boolean',
            'kc.always' => 'boolean',
        ];
    }
}
