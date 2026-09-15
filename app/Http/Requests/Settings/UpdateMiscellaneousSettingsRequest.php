<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMiscellaneousSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'episode.watched-downloaded.pairing' => 'boolean',
        ];
    }
}
