@php
    $currentLocale = settings()->get('application.locale', 'en_us');
    $currentLocale = is_string($currentLocale)
        ? strtolower(str_replace('-', '_', $currentLocale))
        : 'en_us';

    $clientLocale = settings()->get('client.determinedlocale');
    $clientLocale = is_string($clientLocale) && $clientLocale !== ''
        ? strtolower(str_replace('-', '_', $clientLocale))
        : null;

    $availableLocales = [];
    foreach ($locales as $locale => $name) {
        $settingsLocale = strtolower(str_replace('-', '_', $locale));
        $availableLocales[$settingsLocale] = [
            'runtime' => $locale,
            'name' => $name,
        ];
    }
@endphp

<div class="buttons languages">
    <form data-section="language">
        <input
            type="hidden"
            name="application.locale"
            id="input_application_locale"
            value="{{ $currentLocale }}"
        >

        <h2>
            {{ __('COMMON/language/hdr') }}
            <span title="{{ $currentLocale }}">
                <i class="flag flag-{{ $currentLocale }}"></i>
            </span>
        </h2>

        <p>
            {{ __('SETTINGS/LANGUAGE/desc') }}
            {{ __('SETTINGS/LANGUAGE/desc2') }}
            <a
                href="https://github.com/SchizoDuckie/DuckieTV/wiki/Can-I-help-DuckieTV-with-Language-Translations%3F"
                style="border:0; display:inline; padding:0; margin:0; text-decoration:underline"
                target="_blank"
                rel="noopener noreferrer"
            >{{ __('SETTINGS/LANGUAGE/desc3') }}</a>
        </p>

        @if($clientLocale !== null && isset($availableLocales[$clientLocale]))
            <a
                href="#"
                onclick="setLanguageLocale('{{ $clientLocale }}'); return false;"
                class="btn {{ $currentLocale === $clientLocale ? 'btn-success' : '' }}"
            >
                <i class="flag flag-{{ $clientLocale }}"></i>
                <span style="position: relative; display:inline-block; top:-3px;">
                    {{ __('SETTINGS/DISPLAY/locale-default/lbl') }} ({{ $clientLocale }})
                </span>
            </a>
        @endif

        @foreach($availableLocales as $locale => $details)
            <a
                href="#"
                onclick="setLanguageLocale('{{ $locale }}'); return false;"
                class="btn {{ $currentLocale === $locale ? 'btn-success' : '' }}"
                style="margin: 2px;"
            >
                <i class="flag flag-{{ $locale }}"></i>
                <span style="display:inline-block; top:-5px; position:relative;">
                    {{ $details['name'] }}
                </span>
            </a>
        @endforeach
    </form>
</div>
