@php
    $showRatings = (bool) settings()->get('download.ratings', true);
    $firstUnwatchedSeason = (bool) settings()->get('series.not-watched-eps-btn', false);
    $seriesGridEnabled = (bool) settings()->get('library.seriesgrid', true);
    $backgroundOpacity = (float) settings()->get('background-rotator.opacity', 0.4);
    $mixedCaseEnabled = ! (bool) settings()->get('font.bebas.enabled', true);
    $kcAlways = (bool) settings()->get('kc.always', false);
@endphp

<div class="buttons">
    <form data-section="display">
        {{-- Download Ratings --}}
        <h2>
            <span title="{{ $showRatings ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}">
                <i class="glyphicon {{ $showRatings ? 'glyphicon-ok' : 'glyphicon-remove' }}"></i>
            </span>
            {{ __('SETTINGS/DISPLAY/download-ratings/hdr') }}
        </h2>
        <p>{{ __('SETTINGS/DISPLAY/download-ratings/desc') }}</p>
        <p><strong>{{ __('COMMON/current-setting/hdr') }}</strong> {{ $showRatings ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}</p>

        <input type="checkbox" name="download.ratings" id="input_download_ratings" {{ $showRatings ? 'checked' : '' }} style="display:none" onchange="Settings.save('display')">
        <a href="#" onclick="document.getElementById('input_download_ratings').click(); return false;" class="btn btn-{{ $showRatings ? 'danger' : 'success' }}">
            <i class="glyphicon glyphicon-{{ $showRatings ? 'remove' : 'ok' }}"></i>
            {{ $showRatings ? __('COMMON/disable/btn') : __('COMMON/enable/btn') }}
        </a>

        <hr class="setting-divider">

        {{-- Sidepanel Episodes Button Mode --}}
        <h2>
            <span title="{{ $firstUnwatchedSeason ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}">
                <i class="glyphicon {{ $firstUnwatchedSeason ? 'glyphicon-ok' : 'glyphicon-remove' }}"></i>
            </span>
            {{ __('SETTINGS/DISPLAY/notWatchedEpsBtn/hdr') }}
        </h2>
        <p>{{ __('SETTINGS/DISPLAY/notWatchedEpsBtn/desc') }}</p>
        <p><strong>{{ __('COMMON/current-setting/hdr') }}</strong> {{ $firstUnwatchedSeason ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}</p>

        <input type="checkbox" name="series.not-watched-eps-btn" id="input_series_not_watched_eps_btn" {{ $firstUnwatchedSeason ? 'checked' : '' }} style="display:none" onchange="Settings.save('display')">
        <a href="#" onclick="document.getElementById('input_series_not_watched_eps_btn').click(); return false;" class="btn btn-{{ $firstUnwatchedSeason ? 'danger' : 'success' }}">
            <i class="glyphicon glyphicon-{{ $firstUnwatchedSeason ? 'remove' : 'ok' }}"></i>
            {{ $firstUnwatchedSeason ? __('COMMON/disable/btn') : __('COMMON/enable/btn') }}
        </a>

        <hr class="setting-divider">

        {{-- Historical Series Grid / Poster Transitions --}}
        <h2>
            <span title="{{ $seriesGridEnabled ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}">
                <i class="glyphicon {{ $seriesGridEnabled ? 'glyphicon-ok' : 'glyphicon-remove' }}"></i>
            </span>
            {{ __('SETTINGS/DISPLAY/transitions/hdr') }}
        </h2>
        <p>{{ __('SETTINGS/DISPLAY/transitions/desc') }}</p>
        <p><strong>{{ __('COMMON/current-setting/hdr') }}</strong> {{ $seriesGridEnabled ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}</p>

        <input type="checkbox" name="library.seriesgrid" id="input_library_seriesgrid" {{ $seriesGridEnabled ? 'checked' : '' }} style="display:none" onchange="Settings.save('display').then(() => window.location.reload())">
        <a href="#" onclick="document.getElementById('input_library_seriesgrid').click(); return false;" class="btn btn-{{ $seriesGridEnabled ? 'danger' : 'success' }}">
            <i class="glyphicon glyphicon-{{ $seriesGridEnabled ? 'remove' : 'ok' }}"></i>
            {{ $seriesGridEnabled ? __('COMMON/disable/btn') : __('COMMON/enable/btn') }}
        </a>

        <hr class="setting-divider">

        {{-- Background Opacity --}}
        <h2>{{ __('SETTINGS/DISPLAY/background-opacity/hdr') }}</h2>
        <ul class="list-unstyled btns">
            <li>
                <p>{{ __('SETTINGS/DISPLAY/background-opacity/desc') }}</p>
                <span id="display-background-opacity-value">{{ number_format($backgroundOpacity * 100, 0) }}%</span>
                <input
                    type="range"
                    name="background-rotator.opacity"
                    value="{{ $backgroundOpacity }}"
                    min="0"
                    max="1"
                    step="0.05"
                    oninput="document.getElementById('display-background-opacity-value').textContent = Math.round(this.value * 100) + '%'; const bg = document.querySelector('background-rotator .background-image-container'); if (bg) bg.style.opacity = this.value;"
                    onchange="Settings.save('display')"
                />
                <strong style="float:left">0%</strong>
                <strong style="float:right">100%</strong>
            </li>
        </ul>
        <br>

        <hr class="setting-divider">

        {{-- Mixed case Font. Historical UI is the inverse of font.bebas.enabled. --}}
        <h2>
            <span title="{{ $mixedCaseEnabled ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}">
                <i class="glyphicon {{ $mixedCaseEnabled ? 'glyphicon-ok' : 'glyphicon-remove' }}"></i>
            </span>
            {{ __('SETTINGS/DISPLAY/mixedcase/hdr') }}
        </h2>
        <p>{{ __('SETTINGS/DISPLAY/mixedcase/desc') }}</p>
        <p><strong>{{ __('COMMON/current-setting/hdr') }}</strong> {{ $mixedCaseEnabled ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}</p>

        <input type="checkbox" name="font.bebas.enabled" id="input_font_bebas_enabled" {{ $mixedCaseEnabled ? '' : 'checked' }} style="display:none" onchange="Settings.save('display').then(() => window.location.reload())">
        <a href="#" onclick="document.getElementById('input_font_bebas_enabled').click(); return false;" class="btn btn-{{ $mixedCaseEnabled ? 'info' : 'success' }}">
            <i class="glyphicon glyphicon-{{ $mixedCaseEnabled ? 'remove' : 'ok' }}"></i>
            {{ $mixedCaseEnabled ? __('COMMON/disable/btn') : __('COMMON/enable/btn') }}
        </a>

        <hr class="setting-divider">

        {{-- Permanent Cheatmode --}}
        <h2>
            <span title="{{ $kcAlways ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}">
                <i class="glyphicon {{ $kcAlways ? 'glyphicon-ok' : 'glyphicon-remove' }}"></i>
            </span>
            {{ __('SETTINGS/DISPLAY/cheatmode/hdr') }}
        </h2>
        <p>{{ __('SETTINGS/DISPLAY/cheatmode/desc') }}</p>
        <p><strong>{{ __('COMMON/current-setting/hdr') }}</strong> {{ $kcAlways ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}</p>

        <input type="checkbox" name="kc.always" id="input_kc_always" {{ $kcAlways ? 'checked' : '' }} style="display:none" onchange="Settings.save('display').then(() => window.location.reload())">
        <a href="#" onclick="document.getElementById('input_kc_always').click(); return false;" class="btn btn-{{ $kcAlways ? 'info' : 'success' }}">
            <i class="glyphicon glyphicon-{{ $kcAlways ? 'remove' : 'ok' }}"></i>
            {{ $kcAlways ? __('COMMON/disable/btn') : __('COMMON/enable/btn') }}
        </a>
    </form>
</div>
