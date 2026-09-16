@php
    $torrentEnabled = (bool) settings()->get('torrenting.enabled', true);
    $currentClient = app(\App\Services\TorrentClientService::class)->getActiveClient()?->getName();
    $labelEnabled = (bool) settings()->get('torrenting.label', false);
    $labelSupported = (bool) ($supportedClients[$currentClient]['supports_labels'] ?? false);
@endphp

<div class="buttons" data-section="torrent">
    <h2>
        <span title="{{ $torrentEnabled ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}">
            <i class="glyphicon {{ $torrentEnabled ? 'glyphicon-ok alert-success' : 'glyphicon-remove alert-danger' }}"></i>
        </span>
        {{ __('SETTINGS/TORRENT/hdr') }}
    </h2>

    <p>{{ $torrentEnabled ? __('SETTINGS/TORRENT/functions-hide/desc') : __('SETTINGS/TORRENT/functions-show/desc') }}</p>
    <p>
        <strong>{{ __('COMMON/current-setting/hdr') }}</strong>
        {{ $torrentEnabled ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}
    </p>

    <a
        href="javascript:void(0)"
        onclick="toggleTorrentSetting('torrenting.enabled', {{ $torrentEnabled ? 'false' : 'true' }})"
        class="btn btn-{{ $torrentEnabled ? 'danger' : 'success' }}"
    >
        <i class="glyphicon {{ $torrentEnabled ? 'glyphicon-remove-sign' : 'glyphicon-ok-sign' }}"></i>
        {{ $torrentEnabled ? __('SETTINGS/TORRENT/functions-hide/btn') : __('SETTINGS/TORRENT/functions-show/btn') }}
    </a>

    <div style="{{ $torrentEnabled ? '' : 'display:none' }}">
        <hr class="setting-divider">

        <h2>{{ __('SETTINGS/TORRENT/choose-client/hdr') }}</h2>

        @foreach($supportedClients as $key => $client)
            <a
                href="javascript:void(0)"
                onclick="setTorrentClient('{{ $key }}')"
                data-client-key="{{ $key }}"
                class="choose-client btn {{ $currentClient == $key ? 'btn-success' : '' }}"
            >
                <i class="torrentlogo {{ $client['css_class'] }}"></i> {{ $client['name'] }}
            </a>
        @endforeach

        @if($labelSupported)
            <hr class="setting-divider">

            <h2>
                <span title="{{ $labelEnabled ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}">
                    <i class="glyphicon {{ $labelEnabled ? 'glyphicon-tag alert-success' : 'glyphicon-remove alert-info' }}"></i>
                </span>
                {{ __('SETTINGS/TORRENT/label/hdr') }}
            </h2>

            <p>{{ __('SETTINGS/TORRENT/label/desc') }}</p>
            <p>
                <strong>{{ __('COMMON/current-setting/hdr') }}</strong>
                {{ $labelEnabled ? __('COMMON/enabled/lbl') : __('COMMON/disabled/lbl') }}
            </p>
            <a
                href="javascript:void(0)"
                onclick="toggleTorrentSetting('torrenting.label', @json(!$labelEnabled))"
                class="btn btn-{{ $labelEnabled ? 'info' : 'success' }}"
            >
                <i class="glyphicon glyphicon-{{ $labelEnabled ? 'remove' : 'tag' }}"></i>&nbsp;
                {{ $labelEnabled ? __('COMMON/disable/btn') : __('COMMON/enable/btn') }}
            </a>
        @endif
    </div>
</div>
