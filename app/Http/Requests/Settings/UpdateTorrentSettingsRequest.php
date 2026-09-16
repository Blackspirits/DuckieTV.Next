<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTorrentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'torrenting.enabled' => 'sometimes|boolean',
            'torrenting.client' => 'sometimes|required|string',
            'torrenting.streaming' => 'sometimes|boolean',
            'torrenting.directory' => 'sometimes|boolean',
        ];

        $service = app(\App\Services\TorrentClientService::class);
        foreach ($service->getAvailableClients() as $name) {
            $client = $service->getClient($name);
            if ($client) {
                $rules = array_merge($rules, $client->getValidationRules());
            }
        }

        return $rules;
    }
}
