@php
    $selectedLanguages = settings()->get('subtitles.languages', ['eng']);
    $selectedLanguages = is_array($selectedLanguages) ? array_values($selectedLanguages) : ['eng'];

    $selectedNames = array_values(array_filter(array_map(
        static fn (string $code): ?string => $subtitleLanguages[$code] ?? null,
        $selectedLanguages
    )));
@endphp

<div
    class="buttons languages"
    id="subtitle-settings"
    data-selected-languages='@json($selectedLanguages)'
>
    <h2>{{ __('COMMON/subtitles/hdr') }}</h2>

    <p>{{ __('SETTINGS/SUBTITLES/desc') }}</p>

    <hr>

    <p>
        <strong
            id="subtitle-selected-label"
            @if($selectedLanguages === []) style="display:none" @endif
        >{{ __('SETTINGS/SUBTITLES/selected/lbl') }}</strong>
        <br>
        <span id="subtitle-selected-languages">{{ implode(', ', $selectedNames) }}</span>
        <strong
            id="subtitle-selected-none"
            @if($selectedLanguages !== []) style="display:none" @endif
        >{{ __('SETTINGS/SUBTITLES/selected-none/lbl') }}</strong>
    </p>

    <p style="text-align:right">
        <a
            href="#"
            id="subtitle-clear-selection"
            onclick="clearSubtitleLanguages(); return false;"
            class="btn btn-xs btn-warning"
            style="display:{{ $selectedLanguages === [] ? 'none' : 'inline-block' }}; padding-right:15px;"
        >
            <i class="glyphicon glyphicon-trash" style="font-size:15px; line-height:23px; vertical-align:middle"></i>
            <span>{{ __('SETTINGS/SUBTITLES/clear-selection/lbl') }}</span>
        </a>
    </p>

    <hr>

    @foreach($subtitleLanguages as $code => $name)
        <a
            href="#"
            data-subtitle-code="{{ $code }}"
            data-subtitle-name="{{ $name }}"
            onclick="toggleSubtitleLanguage('{{ $code }}'); return false;"
            class="btn {{ in_array($code, $selectedLanguages, true) ? 'btn-success' : '' }}"
            style="margin:2px;"
        >
            <i class="flag flag-{{ $subtitleShortCodes[$code] ?? '' }}"></i>
            <span style="line-height:25px; position:relative; top:-5px;">{{ $name }}</span>
        </a>
    @endforeach
</div>
