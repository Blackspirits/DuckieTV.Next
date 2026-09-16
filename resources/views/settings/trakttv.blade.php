<form data-section="trakttv" class="buttons" onsubmit="return false;">
    @php
        $period = (int) settings()->get('trakt-update.period', 12);
    @endphp

    <h2>{{ __('SETTINGS/TRAKTTV/update-period/hdr') }}</h2>

    <p>
        {{ __('SETTINGS/TRAKTTV/update-period/desc') }}<strong>{{ $period }}</strong>{{ __('SETTINGS/TRAKTTV/update-period/desc2') }}
        <br>
        {{ __('SETTINGS/TRAKTTV/update-period-default/lbl') }}
    </p>

    <label for="trakt-update-period">
        {{ __('SETTINGS/TRAKTTV/update-period/form') }}
        <input
            id="trakt-update-period"
            type="number"
            name="trakt-update.period"
            value="{{ $period }}"
            min="1"
            max="24"
            step="1"
            required
        >
    </label>

    <button type="button" class="btn btn-primary btn-save" onclick="Settings.save('trakttv')">
        <i class="glyphicon glyphicon-floppy-save"></i>&nbsp; {{ __('COMMON/save/btn') }}
    </button>
</form>
