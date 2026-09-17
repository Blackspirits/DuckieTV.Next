<?php

namespace App\Http\Requests\Settings;

use App\Services\TorrentClientService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTorrentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $clientService = app(TorrentClientService::class);

        $rules = [
            'torrenting.enabled' => ['sometimes', 'boolean'],
            'torrenting.client' => ['sometimes', 'required', 'string', Rule::in($clientService->getAvailableClients())],
            'torrenting.label' => ['sometimes', 'boolean'],
        ];

        foreach ($clientService->getAvailableClients() as $name) {
            $client = $clientService->getClient($name);
            if ($client) {
                $rules = array_merge($rules, $client->getValidationRules());
            }
        }

        return $rules;
    }
}
