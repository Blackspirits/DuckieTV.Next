<form data-section="calendar" class="buttons">
    @php
        $startSunday = (bool) settings()->get('calendar.startSunday', true);
        $displayMode = (string) settings()->get('calendar.mode', 'date');
        $showSpecials = (bool) settings()->get('calendar.show-specials', true);
        $showDownloaded = (bool) settings()->get('calendar.show-downloaded', true);
        $showEpisodeNumbers = (bool) settings()->get('calendar.show-episode-numbers', false);
    @endphp

    <h2>
        <span title="{{ $startSunday ? __('SETTINGS/CALENDAR/start-sun/tooltip') : __('SETTINGS/CALENDAR/start-mon/tooltip') }}">
            <i class="glyphicon glyphicon-indent-{{ $startSunday ? 'left alert-info' : 'right alert-success' }}"></i>
        </span>
        {{ __('SETTINGS/CALENDAR/week/hdr') }}
    </h2>
    <p>{{ __('SETTINGS/CALENDAR/start/desc') }}</p>
    <p><strong>{{ __('COMMON/current-setting/hdr') }}</strong>
        {{ $startSunday ? __('SETTINGS/CALENDAR/start-sun/tooltip') : __('SETTINGS/CALENDAR/start-mon/tooltip') }}
    </p>
    <input type="checkbox" name="calendar.startSunday" id="input_calendar_startSunday" {{ $startSunday ? 'checked' : '' }} style="display:none"
        onchange="Settings.save('calendar').then(function(data) { if (data && data.success) { window.location.reload(); } })">
    <a href="#" onclick="document.getElementById('input_calendar_startSunday').click(); return false;" class="btn btn-{{ $startSunday ? 'success' : 'info' }}">
        <i class="glyphicon glyphicon-indent-{{ $startSunday ? 'right' : 'left' }}"></i>
        {{ $startSunday ? __('SETTINGS/CALENDAR/start-mon/btn') : __('SETTINGS/CALENDAR/start-sun/btn') }}
    </a>

    <hr class="setting-divider">

    <h2>
        <span title="{{ $displayMode === 'date' ? __('SETTINGS/CALENDAR/mode-month/tooltip') : __('SETTINGS/CALENDAR/mode-week/tooltip') }}">
            <i class="glyphicon glyphicon-{{ $displayMode === 'date' ? 'calendar alert-success' : 'th-list alert-info' }}"></i>
        </span>
        {{ __('SETTINGS/CALENDAR/mode/hdr') }}
    </h2>
    <p>{{ __('SETTINGS/CALENDAR/mode/desc') }}</p>
    <p><strong>{{ __('COMMON/current-setting/hdr') }}</strong>
        {{ $displayMode === 'date' ? __('SETTINGS/CALENDAR/mode-month/tooltip') : __('SETTINGS/CALENDAR/mode-week/tooltip') }}
    </p>
    <input type="hidden" name="calendar.mode" id="input_calendar_mode" value="{{ $displayMode }}">
    <a href="#" onclick="const input = document.getElementById('input_calendar_mode'); input.value = input.value === 'date' ? 'week' : 'date'; Settings.save('calendar').then(function(data) { if (data && data.success) { window.location.reload(); } }); return false;" class="btn btn-{{ $displayMode === 'date' ? 'info' : 'success' }}">
        <i class="glyphicon glyphicon-{{ $displayMode === 'date' ? 'th-list' : 'calendar' }}"></i>
        {{ $displayMode === 'date' ? __('SETTINGS/CALENDAR/mode-week/btn') : __('SETTINGS/CALENDAR/mode-month/btn') }}
    </a>

    <hr class="setting-divider">

    <h2>
        <span title="{{ $showSpecials ? __('SETTINGS/CALENDAR/specials-show/tooltip') : __('SETTINGS/CALENDAR/specials-hide/tooltip') }}">
            <i class="glyphicon glyphicon-{{ $showSpecials ? 'ok alert-success' : 'remove alert-danger' }}"></i>
        </span>
        {{ __('SETTINGS/CALENDAR/specials/hdr') }}
    </h2>
    <p>{{ __('SETTINGS/CALENDAR/specials/desc') }}</p>
    <p><strong>{{ __('COMMON/current-setting/hdr') }}</strong>
        {{ $showSpecials ? __('SETTINGS/CALENDAR/specials-show/tooltip') : __('SETTINGS/CALENDAR/specials-hide/tooltip') }}
    </p>
    <input type="checkbox" name="calendar.show-specials" id="input_calendar_show_specials" {{ $showSpecials ? 'checked' : '' }} style="display:none"
        onchange="Settings.save('calendar').then(function(data) { if (data && data.success) { window.location.reload(); } })">
    <a href="#" onclick="document.getElementById('input_calendar_show_specials').click(); return false;" class="btn btn-{{ $showSpecials ? 'danger' : 'success' }}">
        <i class="glyphicon glyphicon-{{ $showSpecials ? 'remove' : 'ok' }}"></i>
        {{ $showSpecials ? __('SETTINGS/CALENDAR/specials-hide/btn') : __('SETTINGS/CALENDAR/specials-show/btn') }}
    </a>

    <hr class="setting-divider">

    <h2>
        <span title="{{ $showDownloaded ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}">
            <i class="glyphicon glyphicon-{{ $showDownloaded ? 'ok alert-success' : 'remove alert-danger' }}"></i>
        </span>
        {{ __('SETTINGS/CALENDAR/downloaded/hdr') }}
    </h2>
    <p>{{ __('SETTINGS/CALENDAR/downloaded/desc') }}</p>
    <p><strong>{{ __('COMMON/current-setting/hdr') }}</strong>
        {{ $showDownloaded ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}
    </p>
    <input type="checkbox" name="calendar.show-downloaded" id="input_calendar_show_downloaded" {{ $showDownloaded ? 'checked' : '' }} style="display:none"
        onchange="Settings.save('calendar').then(function(data) { if (data && data.success) { if (window.Calendar) { window.Calendar.refresh(); } if (window.SidePanel) { window.SidePanel.expand('/settings/calendar'); } } })">
    <a href="#" onclick="document.getElementById('input_calendar_show_downloaded').click(); return false;" class="btn btn-{{ $showDownloaded ? 'danger' : 'success' }}">
        <i class="glyphicon glyphicon-{{ $showDownloaded ? 'remove' : 'ok' }}"></i>
        {{ $showDownloaded ? __('COMMON/disable/btn') : __('COMMON/enable/btn') }}
    </a>

    <hr class="setting-divider">

    <h2>
        <span title="{{ $showEpisodeNumbers ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}">
            <i class="glyphicon glyphicon-{{ $showEpisodeNumbers ? 'ok alert-success' : 'remove alert-danger' }}"></i>
        </span>
        {{ __('SETTINGS/CALENDAR/show-episode-numbers/hdr') }}
    </h2>
    <p>{{ __('SETTINGS/CALENDAR/show-episode-numbers/desc') }}</p>
    <p><strong>{{ __('COMMON/current-setting/hdr') }}</strong>
        {{ $showEpisodeNumbers ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}
    </p>
    <input type="checkbox" name="calendar.show-episode-numbers" id="input_calendar_show_episode_numbers" {{ $showEpisodeNumbers ? 'checked' : '' }} style="display:none"
        onchange="Settings.save('calendar').then(function(data) { if (data && data.success) { if (window.Calendar) { window.Calendar.refresh(); } if (window.SidePanel) { window.SidePanel.expand('/settings/calendar'); } } })">
    <a href="#" onclick="document.getElementById('input_calendar_show_episode_numbers').click(); return false;" class="btn btn-{{ $showEpisodeNumbers ? 'danger' : 'success' }}">
        <i class="glyphicon glyphicon-{{ $showEpisodeNumbers ? 'remove' : 'ok' }}"></i>
        {{ $showEpisodeNumbers ? __('COMMON/disable/btn') : __('COMMON/enable/btn') }}
    </a>
</form>
