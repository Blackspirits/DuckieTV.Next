<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAutoDownloadSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'torrenting.autodownload' => 'boolean',
            'autodownload.period' => 'integer|min:1|max:21',
            'autodownload.delay' => 'integer|min:0',
            'autodownload.multiSE.enabled' => 'boolean',
            'autodownload.multiSE' => 'array',
        ];
    }
}
