<?php

namespace App\Services\TorrentClients;

use App\Rules\ValidTorrentClientServer;
use App\Services\SettingsService;

/**
 * BiglyBT Client Implementation.
 *
 * BiglyBT uses the Transmission RPC API, so we inherit all logic
 * from TransmissionClient and simply override the configuration mapping.
 *
 * @see BiglyBT.js in DuckieTV-angular.
 */
class BiglyBTClient extends TransmissionClient
{
    public function __construct(SettingsService $settings)
    {
        parent::__construct($settings);
        $this->name = 'BiglyBT';
        $this->id = 'biglybt';
    }

    public function getValidationRules(): array
    {
        return [
            'biglybt.server' => ['nullable', 'string', new ValidTorrentClientServer],
            'biglybt.port' => 'nullable|integer|min:1|max:65535',
            'biglybt.path' => 'nullable|string',
            'biglybt.use_auth' => 'boolean',
            'biglybt.username' => 'nullable|string',
            'biglybt.password' => 'nullable|string',
        ];
    }

    /**
     * Map configuration to BiglyBT specific settings.
     */
    protected function getConfigMappings(): array
    {
        return [
            'server' => 'biglybt.server',
            'port' => 'biglybt.port',
            'path' => 'biglybt.path',
            'username' => 'biglybt.username',
            'password' => 'biglybt.password',
            'use_auth' => 'biglybt.use_auth',
        ];
    }
}
