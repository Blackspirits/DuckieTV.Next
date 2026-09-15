<?php

use App\Http\Middleware\SetLocale;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

function localeTranslationService(array $locales): TranslationService
{
    $translationService = Mockery::mock(TranslationService::class)->makePartial();
    $translationService->shouldReceive('getAvailableLocales')->andReturn($locales);

    return $translationService;
}

it('sets the locale if valid', function () {
    $translationService = localeTranslationService([
        'en_US' => 'English',
        'nl_NL' => 'Dutch',
    ]);

    settings('application.locale', 'nl_nl');

    $middleware = new SetLocale($translationService);
    $request = Request::create('/', 'GET');

    $middleware->handle($request, function ($req) {
        expect(App::getLocale())->toBe('nl_NL');

        return response('OK');
    });
});

it('falls back to en_US if en is requested', function () {
    $translationService = localeTranslationService(['en_US' => 'English']);

    settings('application.locale', 'en');

    $middleware = new SetLocale($translationService);
    $request = Request::create('/', 'GET');

    $middleware->handle($request, function ($req) {
        expect(App::getLocale())->toBe('en_US');

        return response('OK');
    });
});

it('normalizes locale strings', function () {
    $translationService = localeTranslationService(['en_US' => 'English']);

    settings('application.locale', 'en-US');

    $middleware = new SetLocale($translationService);
    $request = Request::create('/', 'GET');

    $middleware->handle($request, function ($req) {
        expect(App::getLocale())->toBe('en_US');

        return response('OK');
    });
});

it('uses configured fallback before first available locale if requested locale is invalid', function () {
    Config::set('app.fallback_locale', 'en_US');

    $translationService = localeTranslationService([
        'de_DE' => 'German',
        'en_US' => 'English',
    ]);

    settings('application.locale', 'invalid_LOCALE');

    $middleware = new SetLocale($translationService);
    $request = Request::create('/', 'GET');

    $middleware->handle($request, function ($req) {
        expect(App::getLocale())->toBe('en_US');

        return response('OK');
    });
});

it('does nothing if no setting and no valid fallback', function () {
    Config::set('app.locale', 'default');
    Config::set('app.fallback_locale', 'fallback');
    App::setLocale('default');

    $translationService = localeTranslationService([]);

    $middleware = new SetLocale($translationService);
    $request = Request::create('/', 'GET');

    $middleware->handle($request, function ($req) {
        expect(App::getLocale())->toBe('default');

        return response('OK');
    });
});
