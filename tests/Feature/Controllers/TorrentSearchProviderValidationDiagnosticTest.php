<?php

namespace Tests\Feature\Controllers;

use App\Http\Requests\Settings\UpdateTorrentSearchSettingsRequest;
use App\Services\TorrentSearchService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use ReflectionFunction;
use Tests\TestCase;

class TorrentSearchProviderValidationDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostic_provider_validation_paths(): void
    {
        $service = app(TorrentSearchService::class);
        $rules = app(UpdateTorrentSearchSettingsRequest::class)->rules();
        $providerRules = $rules['torrenting.searchprovider'];
        $providerClosure = collect($providerRules)->first(fn ($rule) => $rule instanceof Closure);
        $captured = $providerClosure instanceof Closure
            ? (new ReflectionFunction($providerClosure))->getStaticVariables()
            : [];

        fwrite(STDERR, 'DIAG service_providers='.json_encode(array_keys($service->getSearchEngines())).PHP_EOL);
        fwrite(STDERR, 'DIAG captured='.json_encode($captured).PHP_EOL);

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
