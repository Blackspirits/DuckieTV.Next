<form data-section="miscellaneous" class="buttons">
    @php
        $watchedDownloadedPaired = (bool) settings()->get('episode.watched-downloaded.pairing', true);
    @endphp

    <h2>
        <span title="{{ $watchedDownloadedPaired ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}">
            <i class="glyphicon {{ $watchedDownloadedPaired ? 'glyphicon-ok alert-success' : 'glyphicon-remove alert-info' }}"></i>
        </span>
        {{ __('SETTINGS/MISCELLANEOUS/watchedDownloadedPaired/hdr') }}
    </h2>

    <p>{{ __('SETTINGS/MISCELLANEOUS/watchedDownloadedPaired/desc') }}</p>

    <p>
        <strong>{{ __('COMMON/current-setting/hdr') }}</strong>
        {{ $watchedDownloadedPaired ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}
    </p>

    <input
        type="checkbox"
        name="episode.watched-downloaded.pairing"
        id="input_episode_watched_downloaded_pairing"
        {{ $watchedDownloadedPaired ? 'checked' : '' }}
        style="display:none"
        onchange="Settings.save('miscellaneous').then(function(data) { if (data && data.success) { if (window.SidePanel) { window.SidePanel.expand('/settings/miscellaneous'); } else { window.location.reload(); } } })"
    >

    <a
        href="#"
        onclick="document.getElementById('input_episode_watched_downloaded_pairing').click(); return false;"
        class="btn btn-{{ $watchedDownloadedPaired ? 'info' : 'success' }}"
    >
        <i class="glyphicon glyphicon-{{ $watchedDownloadedPaired ? 'remove' : 'ok' }}"></i>
        {{ $watchedDownloadedPaired ? __('COMMON/disable/btn') : __('COMMON/enable/btn') }}
    </a>
</form>
