<?php

namespace Tests\Feature\Controllers;

use App\Http\Requests\Settings\UpdateTorrentSearchSettingsRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TorrentSearchProviderValidationDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostic_provider_validation_paths(): void
    {
        $rules = app(UpdateTorrentSearchSettingsRequest::class)->rules();
        $payload = ['torrenting' => ['searchprovider' => '__missing__']];
        $validator = Validator::make($payload, $rules);

        fwrite(STDERR, 'DIAG direct_fails='.($validator->fails() ? 'true' : 'false').PHP_EOL);
        fwrite(STDERR, 'DIAG direct_errors='.json_encode($validator->errors()->toArray()).PHP_EOL);
        fwrite(STDERR, 'DIAG direct_validated='.json_encode($validator->safe()->all()).PHP_EOL);

        $response = $this->postJson(route('settings.update', 'torrent-search'), [
            'torrenting.searchprovider' => '__missing__',
        ]);

        fwrite(STDERR, 'DIAG http_status='.$response->getStatusCode().PHP_EOL);
        fwrite(STDERR, 'DIAG http_body='.$response->getContent().PHP_EOL);
        fwrite(STDERR, 'DIAG persisted='.json_encode(\App\Models\Setting::query()->find('torrenting.searchprovider')?->value).PHP_EOL);

        $this->assertTrue(true);
    }
}
