<div class="buttons">
    <form data-section="auto-download" onsubmit="return false;">
        <h2>
            <span title="{{ settings()->get('torrenting.autodownload', false) ? 'Enabled' : 'Disabled' }}">
                <i class="glyphicon {{ settings()->get('torrenting.autodownload', false) ? 'glyphicon-ok' : 'glyphicon-remove' }}"></i>
            </span>
            Auto-Download
        </h2>

        <p>Periodic auto-download runs every <strong>15 minutes</strong> while DuckieTV is active.</p>
        <label>
            <input type="checkbox" name="torrenting.autodownload" value="1" {{ settings()->get('torrenting.autodownload', false) ? 'checked' : '' }}>
            Enable periodic auto-download
        </label>

        <hr class="setting-divider">

        <h2>Lookback / Recovery Window</h2>
        <p>
            Recently aired episodes are reconsidered using this overlap window so missed checks after sleep,
            offline periods, or app restarts can catch up. This value does <strong>not</strong> control the 15-minute cadence.
        </p>
        <label>
            Lookback (days):
            <input
                type="number"
                name="autodownload.period"
                value="{{ settings()->get('autodownload.period', 1) }}"
                min="1"
                max="21"
                step="1"
                required
            >
        </label>
        <p><small>Default: 1 day. Allowed range: 1–21 days.</small></p>

        <hr class="setting-divider">

        <h2>Auto-Download Delay</h2>
        <p>Wait after the episode runtime before searching, to allow better releases to appear.</p>
        <label>
            Delay (minutes):
            <input
                type="number"
                name="autodownload.delay"
                value="{{ settings()->get('autodownload.delay', 15) }}"
                min="0"
                step="1"
                required
            >
        </label>
        <p><small>Default: 15 minutes. The effective delay is capped by the configured lookback window.</small></p>

        <hr class="setting-divider">

        <button type="button" class="btn btn-primary btn-save" onclick="Settings.save('auto-download')">
            <i class="glyphicon glyphicon-floppy-save"></i>&nbsp; Save
        </button>
    </form>
</div>
