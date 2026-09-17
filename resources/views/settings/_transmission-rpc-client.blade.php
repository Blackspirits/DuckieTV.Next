@php
    $authEnabled = (bool) settings()->get($prefix.'.use_auth', false);
@endphp

<div class="header">
    <h2><i class="glyphicon glyphicon-magnet"></i> {{ $clientName }} {{ __('COMMON/integration/hdr') }}</h2>
</div>

<div class="body">
    <div class="alert alert-info" style="text-align: center">
        <img src="{{ asset('img/torrentclients/'.$icon) }}" style="width:200px; margin: 0 auto; display: block;">
        @if(!empty($wikiUrl))
            <p><a href="{{ $wikiUrl }}" target="_blank" rel="noopener noreferrer">{{ __('COMMON/set-up-instructions/lbl') }}</a></p>
        @endif
    </div>

    <div id="connection-status">
        <p class="status-connected" style="display:none;">
            <strong>{{ __('COMMON/status/hdr') }}:</strong>
            <span>{{ __('COMMON/connected/lbl') }}</span> {{ $clientName }} API @ <span class="server-info"></span>
        </p>
        <p class="status-not-connected">
            <strong>{{ __('COMMON/status/hdr') }}:</strong>
            <span>{{ __('COMMON/not-connected/lbl') }}</span>
        </p>
        <p class="status-error" style="display:none;">
            <strong>{{ __('COMMON/error/hdr') }}:</strong>
            <span class="error-message"></span>
        </p>
    </div>

    <form method="POST" action="{{ route('settings.update', 'torrent') }}" data-section="torrent" onsubmit="Settings.test('torrent'); return false;">
        @csrf

        <div class="form-group">
            <label>{{ __('COMMON/address/lbl') }}</label>
            <input type="url" name="{{ $prefix }}.server" class="form-control" value="{{ \App\Rules\ValidTorrentClientServer::displayValue(settings()->get($prefix.'.server')) }}" required>
        </div>

        <div class="form-group">
            <label>{{ __('COMMON/port/lbl') }}</label>
            <input type="number" name="{{ $prefix }}.port" class="form-control" value="{{ settings()->get($prefix.'.port') }}" min="1" max="65535" required>
        </div>

        <div class="form-group">
            <label>{{ __('COMMON/path/lbl') }}</label>
            <input type="text" name="{{ $prefix }}.path" class="form-control" value="{{ settings()->get($prefix.'.path') }}">
        </div>

        <div class="checkbox">
            <label>
                <input
                    type="checkbox"
                    name="{{ $prefix }}.use_auth"
                    id="{{ $prefix }}_use_auth"
                    {{ $authEnabled ? 'checked' : '' }}
                    onchange="document.getElementById('{{ $prefix }}_auth_fields').style.display = this.checked ? 'block' : 'none'"
                >
                {{ __('COMMON/authentication/lbl') }}
            </label>
        </div>

        <div id="{{ $prefix }}_auth_fields" style="display: {{ $authEnabled ? 'block' : 'none' }}">
            <div class="form-group">
                <label>{{ __('COMMON/username/lbl') }}</label>
                <input type="text" name="{{ $prefix }}.username" class="form-control" value="{{ settings()->get($prefix.'.username') }}">
            </div>

            <div class="form-group">
                <label>{{ __('COMMON/password/lbl') }}</label>
                <input type="password" name="{{ $prefix }}.password" class="form-control" value="" autocomplete="new-password" placeholder="Leave blank to keep the saved password">
            </div>
        </div>

        <button type="submit" class="btn btn-primary">{{ __('COMMON/test-save/btn') }}</button>
    </form>
</div>
