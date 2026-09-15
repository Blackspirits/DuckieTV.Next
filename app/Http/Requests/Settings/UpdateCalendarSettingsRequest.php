<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCalendarSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'calendar.startSunday' => 'boolean',
            'calendar.mode' => 'in:date,week',
            'calendar.show-specials' => 'boolean',
            'calendar.show-downloaded' => 'boolean',
            'calendar.show-episode-numbers' => 'boolean',
        ];
    }
}
