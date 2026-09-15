<div ng-controller="BackupCtrl">
    <h2>Backup</h2>
    <div class="buttons">
        <button type="button" class="btn btn-success" onclick="BackupRestore.downloadManualBackup()">
            <i class="glyphicon glyphicon-floppy-save"></i> <span>Create Backup</span>
        </button>
    </div>

    <hr class="setting-divider">

    <h2>{{ __('COMMON/autobackup/hdr') }}</h2>
    <p>{{ __('SETTINGS/BACKUP/autobackup/desc') }}</p>
    @php
        $autoBackupLabels = explode('|', __('AUTOBACKUPLIST'));
        $autoBackupPeriods = ['never', 'daily', 'weekly', 'monthly'];
    @endphp
    <form name="autoBackupForm">
        <label for="autoBackup">{{ __('COMMON/autobackup/hdr') }}:</label>
        <select name="autobackup.period" id="autoBackup" onchange="BackupRestore.saveAutoBackupPeriod(this.value)">
            @foreach($autoBackupPeriods as $index => $period)
                <option value="{{ $period }}" {{ settings('autobackup.period') === $period ? 'selected' : '' }}>
                    {{ $autoBackupLabels[$index] ?? ucfirst($period) }}
                </option>
            @endforeach
        </select>
    </form>
    &nbsp;
    <p>
        <span>{{ __('SETTINGS/BACKUP/autobackup-schedule/lbl') }}</span>
        <span id="autoBackupNextRun">—</span>
    </p>

    <hr class="setting-divider">

    <h2>Import Backup</h2>
    
    <div id="restore-status-message"></div>

    <form id="restoreForm">
        @csrf
        <div class="checkbox">
            <input type="checkbox" id="wipebeforeImport" name="wipe" value="1">
            <label for="wipebeforeImport">Wipe database before import</label>
        </div>

        <div style="position:relative">
            <div class="buttons">
                <a class="btn btn-success">
                    <i class="glyphicon glyphicon-floppy-open"></i> <span>Choose Backup to load</span>
                </a>
            </div>
            <input type="file" name="backup_file" id="backupInput"
                   onchange="BackupRestore.upload(this)"
                   style="position:absolute;opacity:0;width:100%;top:0;padding:0;height:100%;cursor:pointer" />
        </div>
    </form>

    <hr class="setting-divider">

    <h2>{{ __('COMMON/wipe/hdr') }}</h2>
    <p>{{ __('SETTINGS/BACKUP/wipe/desc') }}</p>
    <div class="buttons">
        <button type="button" class="btn btn-danger" onclick="BackupRestore.wipeDatabase()">
            <i class="glyphicon glyphicon-trash"></i> <span>{{ __('SETTINGS/BACKUP/wipe/btn') }}</span>
        </button>
    </div>

    <hr class="setting-divider">

    <h2>{{ __('SETTINGS/BACKUP/refresh/hdr') }}</h2>
    <p>{{ __('SETTINGS/BACKUP/refresh/desc') }}</p>
    <div class="buttons">
        <button id="refreshDatabaseButton" type="button" class="btn btn-danger" onclick="BackupRestore.refreshDatabase()">
            <i class="glyphicon glyphicon-refresh"></i> <span>{{ __('SETTINGS/BACKUP/refresh/btn') }}</span>
        </button>
    </div>
</div>

<!-- Restore Progress Modal -->
<div class="modal fade" id="restore-progress-modal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header dialog-header-wait">
                <h4 class="modal-title">Restoring Backup...</h4>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-xs-12">
                        <p><strong>Overall Progress</strong></p>
                        <div class="progress">
                          <div id="total-progress-bar" class="progress-bar progress-bar-success progress-bar-striped active" role="progressbar" style="width: 0%; min-width: 2em;">
                            0%
                          </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-xs-12">
                        <p><strong>Processing:</strong> <span id="current-show-name" style="color: #ccc;">Initializing...</span></p>
                        <div class="progress">
                          <div id="show-progress-bar" class="progress-bar progress-bar-info progress-bar-striped active" role="progressbar" style="width: 0%; min-width: 2em;">
                            0%
                          </div>
                        </div>
                    </div>
                </div>

                <hr style="border-top-color: #444;">
                <p><strong>Activity Log</strong></p>
                <div id="restore-log" style="height: 300px; overflow-y: auto; background: #000; color: #0f0; padding: 10px; font-family: monospace; font-size: 12px; border: 1px solid #444; white-space: pre-wrap;">Initializing...</div>
            </div>
            <!-- No footer/close button to prevent closing during restore -->
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="restore-confirm-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header dialog-header-confirm">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" onclick="BackupRestore.cancelRestore()"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">Confirm Restore</h4>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to restore this backup?</p>
                <p class="text-warning"><i class="glyphicon glyphicon-warning-sign"></i> Current settings and favorites might be overwritten.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" onclick="BackupRestore.cancelRestore()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="BackupRestore.proceedWithRestore()">Restore</button>
            </div>
        </div>
    </div>
</div>
