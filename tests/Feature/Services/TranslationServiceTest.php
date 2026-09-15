<?php

use App\Services\TranslationService;
use Illuminate\Support\Facades\File;

it('returns available locales', function () {
    // Mock the File facade to avoid reading actual files if possible,
    // but for Feature tests in this project it seems we rely on actual files or seeded data.
    // However, we can also just assert that the known files are returned.

    $service = new TranslationService;
    $locales = $service->getAvailableLocales();

    expect($locales)->toBeArray()
        ->and($locales)->toHaveKey('en_US')
        ->and($locales['en_US'])->toBe('American English') // Wait, the file said "American English" (checked previously)
        ->and($locales)->toHaveKey('nl_NL'); // Assuming nl_NL exists based on previous file list
});

it('gracefully handles missing lang directory', function () {
    // We can't easily remove the directory in a real test environment without side effects.
    // So we'll skip this or mock File facade.

    File::shouldReceive('exists')->andReturn(false);

    $service = new TranslationService;
    $locales = $service->getAvailableLocales();

    expect($locales)->toBe(['en_US' => 'English (US)']);
});

it('parses locale names correctly', function () {
    // We know en_US.json contains "LOCALE": "American English"
    $service = new TranslationService;
    $locales = $service->getAvailableLocales();

    expect($locales['en_US'])->toBe('American English');
});

it('resolves historical locale casing to the exact translation filename locale', function () {
    $service = new TranslationService;

    expect($service->resolveLocale('en_us'))->toBe('en_US')
        ->and($service->resolveLocale('pt_pt'))->toBe('pt_PT')
        ->and($service->resolveLocale('pt-PT'))->toBe('pt_PT')
        ->and($service->resolveLocale('zz_zz'))->toBeNull();
});
