<div class="buttons" data-section="jackett-search">
    <h2><i class="glyphicon glyphicon-search"></i> Jackett / Torznab</h2>
    <p style="text-align:left;white-space:normal">
        Manage Torznab indexers used by torrent search. API keys are write-only and are never displayed after saving.
    </p>

    <hr class="setting-divider">

    <h3>Add Torznab indexer</h3>
    <form id="jackett-create-form" onsubmit="JackettSettings.create(this); return false;">
        <div class="form-group">
            <label>Name</label>
            <input type="text" name="name" class="form-control" maxlength="40" required>
        </div>
        <div class="form-group">
            <label>Torznab endpoint</label>
            <input type="url" name="torznab" class="form-control" maxlength="200"
                   placeholder="http://localhost:9117/api/v2.0/indexers/example/results/torznab/" required>
        </div>
        <div class="form-group">
            <label>API key</label>
            <input type="password" name="apiKey" class="form-control" maxlength="40"
                   autocomplete="new-password" required>
        </div>
        <div class="checkbox">
            <label>
                <input type="checkbox" name="enabled" checked> Enabled
            </label>
        </div>
        <button type="submit" class="btn btn-success">
            <i class="glyphicon glyphicon-plus"></i> Add indexer
        </button>
    </form>

    <hr class="setting-divider">

    <h3>Configured indexers</h3>

    @forelse($jackettIndexers as $jackett)
        @php
            $isTorznab = (int) $jackett->torznabEnabled === 1;
            $isDefault = (string) $defaultProvider === (string) $jackett->name;
        @endphp

        <div class="panel panel-default" id="jackett-indexer-{{ $jackett->id }}">
            <div class="panel-body">
                <h4>
                    {{ $jackett->name }}
                    @if((int) $jackett->enabled === 1)
                        <span class="label label-success">Enabled</span>
                    @else
                        <span class="label label-default">Disabled</span>
                    @endif
                    @if($isDefault)
                        <span class="label label-info">Default provider</span>
                    @endif
                </h4>

                @if(!$isTorznab)
                    <div class="alert alert-warning">
                        Historical Jackett Admin API configuration preserved. Management is unavailable until the Admin API runtime is restored.
                    </div>
                    @if($jackett->torznab)
                        <code>{{ $jackett->torznab }}</code>
                    @else
                        <code>Stored endpoint hidden because it is invalid or unsafe.</code>
                    @endif
                @else
                    @if(!$jackett->torznab)
                        <div class="alert alert-warning">
                            The stored endpoint is invalid or unsafe and has been hidden. Enter a valid Torznab endpoint to repair this indexer.
                        </div>
                    @endif
                    <div class="form-group">
                        <label>Torznab endpoint</label>
                        <input type="url" id="jackett-torznab-{{ $jackett->id }}" class="form-control"
                               maxlength="200" value="{{ $jackett->torznab }}" required>
                    </div>

                    <div class="form-group">
                        <label>API key</label>
                        <input type="password" id="jackett-api-key-{{ $jackett->id }}" class="form-control"
                               maxlength="40" autocomplete="new-password"
                               placeholder="Leave blank to keep the current API key">
                    </div>

                    <div class="checkbox">
                        <label>
                            <input type="checkbox" id="jackett-enabled-{{ $jackett->id }}"
                                   {{ (int) $jackett->enabled === 1 ? 'checked' : '' }}
                                   {{ $isDefault ? 'disabled' : '' }}>
                            Enabled
                        </label>
                    </div>

                    @if($isDefault)
                        <p class="text-info">
                            Choose another default search provider before disabling or deleting this indexer.
                        </p>
                    @endif

                    <button type="button" class="btn btn-primary"
                            onclick="JackettSettings.update({{ $jackett->id }})">
                        <i class="glyphicon glyphicon-floppy-save"></i> Save
                    </button>
                    <button type="button" class="btn btn-danger"
                            onclick="JackettSettings.remove({{ $jackett->id }})"
                            {{ $isDefault ? 'disabled' : '' }}>
                        <i class="glyphicon glyphicon-trash"></i> Delete
                    </button>
                @endif
            </div>
        </div>
    @empty
        <p>No Jackett / Torznab indexers configured.</p>
    @endforelse
</div>
