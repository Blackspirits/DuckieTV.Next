<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

class UpdateAutoDownloadSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $submittedPeriod = Arr::get(
            request()->all(),
            'autodownload.period',
            settings()->get('autodownload.period', 1)
        );
        $periodDays = is_numeric($submittedPeriod) ? (int) $submittedPeriod : 1;
        $maxDelayMinutes = max(1, min(21, $periodDays)) * 24 * 60;

        return [
            'torrenting.autodownload' => 'boolean',
            'autodownload.period' => 'integer|min:1|max:21',
            'autodownload.delay' => 'integer|min:0|max:'.$maxDelayMinutes,
        ];
    }
}
