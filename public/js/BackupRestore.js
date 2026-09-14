window.BackupRestore = {
    pollingInterval: null,
    refreshPollingInterval: null,
    autoBackupTimer: null,
    autoBackupDialog: null,
    autoBackupState: null,
    autoBackupObserver: null,
    lastLogCount: 0,
    selectedFile: null,
    progressModal: null,
    miniProgress: null,
    isMinimized: false,
    status: 'idle',
    i18n: {},
    posters: [],
    failedSeries: [],
    lastLogCount: 0,
    lastFailedCount: 0,

    init: function (i18n = {}) {
        this.i18n = i18n;
        console.log('BackupRestore: init');
        // Check if restore/refresh maintenance is already in progress.
        this.checkExistingRestore();
        this.checkExistingDatabaseRefresh();
        this.observeAutoBackupControls();
        this.initAutoBackup();
    },

    checkExistingRestore: function () {
        fetch('/settings/restore/progress')
            .then(response => response.json())
            .then(data => {
                if (['queued', 'running', 'extracting', 'cancelling'].includes(data.status)) {
                    console.log('BackupRestore: Ongoing restore detected');
                    this.status = data.status;
                    // Default to minimized view on page load if it's already running
                    this.showMiniProgress();
                    this.startPolling();
                }
            })
            .catch(err => console.error('Restore check error:', err));
    },

    upload: function (input) {
        if (!input.files.length) return;
        this.selectedFile = input.files[0];

        const wipe = document.getElementById('wipebeforeImport').checked;
        let msg = `<p>${this.i18n['BACKUPCTRLjs/restore/intro'] || 'Are you sure you want to restore this backup?'}</p>`;

        if (wipe) {
            msg += `<p><strong>${this.i18n['BACKUPCTRLjs/restore/wipe-warn'] || 'This will wipe your current database before restoring!'}</strong></p>`;
        } else {
            msg += `<p>${this.i18n['BACKUPCTRLjs/restore/merge-info'] || 'This will merge the backup with your current data.'}</p>`;
        }

        Modal.confirm(this.i18n['BACKUPCTRLjs/restore/confirm-hdr'] || 'Restore Backup', msg, () => {
            this.proceedWithRestore();
        }, () => {
            this.cancelRestore();
        });
    },

    proceedWithRestore: function () {
        if (!this.selectedFile) {
            alert(this.i18n['COMMON/error/hdr'] || 'No file selected.');
            return;
        }

        // Show Progress Modal
        this.showDetailedProgress();

        let formData = new FormData();
        formData.append('backup_file', this.selectedFile);
        formData.append('wipe', document.getElementById('wipebeforeImport').checked ? '1' : '0');

        const tokenMeta = document.querySelector('meta[name="csrf-token"]');

        fetch('/settings/restore', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': tokenMeta ? tokenMeta.getAttribute('content') : ''
            }
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.status = 'running';
                    this.startPolling();
                } else {
                    alert((this.i18n['BACKUPCTRLjs/progress/restore-failed'] || 'Restore Failed: ') + data.message);
                    if (this.progressModal) this.progressModal.hide();
                    this.clearInput();
                }
            })
            .catch(error => {
                alert((this.i18n['BACKUPCTRLjs/progress/restore-failed'] || 'Restore Failed: ') + error);
                if (this.progressModal) this.progressModal.hide();
                this.clearInput();
            });
    },

    showDetailedProgress: function () {
        this.isMinimized = false;
        if (this.miniProgress) {
            this.miniProgress.remove();
            this.miniProgress = null;
        }

        const template = document.getElementById('restore-progress-modal-template');
        const content = template.content.cloneNode(true);

        this.progressModal = Modal.wait(
            this.i18n['BACKUPCTRLjs/progress/hdr'] || 'Restoring Backup...',
            content,
            0,
            {
                minimizable: true,
                onMinimize: () => this.minimize()
            }
        );
        // Refresh UI with current state if available
        this.refreshProgress();

        // Wire Stop button
        const stopBtn = this.progressModal.el.querySelector('.btn-stop-restore');
        if (stopBtn) {
            stopBtn.addEventListener('click', () => {
                if (confirm('Are you sure you want to stop the restore? This will leave the current queue as is.')) {
                    this.requestCancellation();
                }
            });
        }
    },

    showMiniProgress: function () {
        this.isMinimized = true;
        if (this.progressModal) {
            this.progressModal.hide();
            this.progressModal = null;
        }

        const template = document.getElementById('restore-progress-mini-template');
        const content = template.content.cloneNode(true);

        document.body.appendChild(content);
        this.miniProgress = document.body.lastElementChild;

        this.miniProgress.addEventListener('click', () => this.maximize());
        // Refresh UI with current state if available
        this.refreshProgress();
    },

    minimize: function () {
        this.showMiniProgress();
    },

    maximize: function () {
        this.showDetailedProgress();
    },

    refreshProgress: function () {
        fetch('/settings/restore/progress')
            .then(response => response.json())
            .then(data => this.updateUI(data));
    },

    requestCancellation: function () {
        fetch('/settings/restore/cancel', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            }
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Restore cancellation requested');
                } else {
                    alert('Failed to cancel restore: ' + data.message);
                }
            })
            .catch(err => console.error('Error cancelling restore:', err));
    },

    cancelRestore: function () {
        this.clearInput();
        this.selectedFile = null;
    },

    wipeDatabase: function () {
        const title = this.i18n['COMMON/wipe/hdr'] || 'Wipe database and settings';
        const message = this.i18n['BACKUPCTRLjs/wipe/desc'] || 'Do you really want to remove all series and episodes from the DuckieTV database and clear all the settings?';

        Modal.confirm(title, `<p>${message}</p>`, () => {
            const tokenMeta = document.querySelector('meta[name="csrf-token"]');

            fetch('/settings/wipe', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': tokenMeta ? tokenMeta.getAttribute('content') : '',
                    'Accept': 'application/json'
                }
            })
                .then(async response => {
                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Database wipe failed.');
                    }

                    window.location.reload();
                })
                .catch(error => {
                    const prefix = this.i18n['COMMON/error/hdr'] || 'Error';
                    alert(`${prefix}: ${error.message}`);
                });
        });
    },

    initAutoBackup: function () {
        const tokenMeta = document.querySelector('meta[name="csrf-token"]');

        fetch('/settings/autobackup/state', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': tokenMeta ? tokenMeta.getAttribute('content') : '',
                'Accept': 'application/json'
            }
        })
            .then(response => response.json())
            .then(state => {
                this.scheduleAutoBackup(state);
            })
            .catch(error => console.error('Auto-backup state error:', error));
    },

    calculateNextAutoBackup: function (state) {
        if (!state || state.period === 'never' || !state.last_run) {
            return null;
        }

        const lastRun = new Date(Number(state.last_run));
        if (Number.isNaN(lastRun.getTime())) {
            return null;
        }

        switch (state.period) {
            case 'daily':
                return new Date(
                    lastRun.getFullYear(),
                    lastRun.getMonth(),
                    lastRun.getDate() + 1,
                    lastRun.getHours(),
                    lastRun.getMinutes(),
                    lastRun.getSeconds()
                ).getTime();
            case 'weekly':
                return new Date(
                    lastRun.getFullYear(),
                    lastRun.getMonth(),
                    lastRun.getDate() + 7,
                    lastRun.getHours(),
                    lastRun.getMinutes(),
                    lastRun.getSeconds()
                ).getTime();
            case 'monthly':
                return new Date(
                    lastRun.getFullYear(),
                    lastRun.getMonth() + 1,
                    lastRun.getDate(),
                    lastRun.getHours(),
                    lastRun.getMinutes(),
                    lastRun.getSeconds()
                ).getTime();
            default:
                return null;
        }
    },

    scheduleAutoBackup: function (state) {
        this.autoBackupState = state || null;

        if (this.autoBackupTimer) {
            clearTimeout(this.autoBackupTimer);
            this.autoBackupTimer = null;
        }

        this.syncAutoBackupControls();

        const nextRun = this.calculateNextAutoBackup(this.autoBackupState);

        if (nextRun === null) {
            return;
        }

        let delay = nextRun - Date.now();
        if (delay <= 0) {
            delay = 60000;
        }

        // Browser timers cannot safely represent every monthly interval.
        // Re-check daily until the exact historical local-time deadline is near.
        const maxTimerDelay = 24 * 60 * 60 * 1000;
        if (delay > maxTimerDelay) {
            this.autoBackupTimer = setTimeout(() => this.initAutoBackup(), maxTimerDelay);
            return;
        }

        this.autoBackupTimer = setTimeout(() => this.handleAutoBackupDue(), delay);
    },

    syncAutoBackupControls: function () {
        const state = this.autoBackupState;
        if (!state) return;

        const select = document.getElementById('autoBackup');
        if (select && state.period) {
            select.value = state.period;
        }

        const label = document.getElementById('nextAutoBackupDate');
        if (label) {
            const nextRun = this.calculateNextAutoBackup(state);
            label.textContent = nextRun === null ? '' : new Date(nextRun).toString();
        }
    },

    observeAutoBackupControls: function () {
        if (this.autoBackupObserver || !document.body) {
            return;
        }

        this.autoBackupObserver = new MutationObserver(mutations => {
            const relevantControlAdded = mutations.some(mutation =>
                Array.from(mutation.addedNodes || []).some(node => {
                    if (!(node instanceof Element)) {
                        return false;
                    }

                    return node.matches('#autoBackup, #nextAutoBackupDate')
                        || Boolean(node.querySelector('#autoBackup, #nextAutoBackupDate'));
                })
            );

            if (relevantControlAdded) {
                this.syncAutoBackupControls();
            }
        });

        this.autoBackupObserver.observe(document.body, {
            childList: true,
            subtree: true
        });
    },

    handleAutoBackupDue: function () {
        this.autoBackupTimer = null;

        const tokenMeta = document.querySelector('meta[name="csrf-token"]');

        fetch('/settings/autobackup/state', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': tokenMeta ? tokenMeta.getAttribute('content') : '',
                'Accept': 'application/json'
            }
        })
            .then(response => response.json())
            .then(state => {
                const nextRun = this.calculateNextAutoBackup(state);

                if (nextRun !== null && nextRun > Date.now()) {
                    this.scheduleAutoBackup(state);
                    return;
                }

                if (!state || state.period === 'never') {
                    this.scheduleAutoBackup(state);
                    return;
                }

                if (Number(state.favorites_count || 0) === 0) {
                    console.info('Auto-backup is not required because there are no favorites.');
                    return;
                }

                this.showAutoBackupDialog();
            })
            .catch(error => console.error('Auto-backup due check error:', error));
    },

    showAutoBackupDialog: function () {
        if (this.autoBackupDialog && this.autoBackupDialog.el) {
            return;
        }

        const content = document.createElement('p');
        content.textContent = this.i18n['COMMON/backup/desc'] || 'Create a backup of your series / episodes / watched list.';

        const footer = document.createDocumentFragment();
        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'btn btn-default';
        cancelButton.textContent = this.i18n['COMMON/cancel/btn'] || 'Cancel';

        const createButton = document.createElement('button');
        createButton.type = 'button';
        createButton.className = 'btn btn-success';
        createButton.textContent = this.i18n['COMMON/create/btn'] || 'Create Database Backup';

        footer.appendChild(cancelButton);
        footer.appendChild(createButton);

        const modal = new Modal({
            backdrop: true,
            keyboard: true,
            size: 'lg'
        });

        modal.show(
            this.i18n['COMMON/autobackup/hdr'] || 'Auto-Backup',
            content,
            footer,
            'dialog-header-confirm'
        );

        cancelButton.addEventListener('click', () => {
            modal.hide();
            this.autoBackupDialog = null;
        });

        createButton.addEventListener('click', () => {
            modal.hide();
            this.autoBackupDialog = null;
            this.createScheduledBackup();
        });

        this.autoBackupDialog = modal;
    },

    persistAutoBackupSettings: function (settings) {
        const tokenMeta = document.querySelector('meta[name="csrf-token"]');

        return fetch('/settings/backup', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': tokenMeta ? tokenMeta.getAttribute('content') : '',
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(settings)
        }).then(async response => {
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Failed to save auto-backup settings.');
            }

            return data;
        });
    },

    triggerBackupDownload: function () {
        const link = document.createElement('a');
        link.href = '/settings/backup/export';
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        link.remove();
    },

    createScheduledBackup: function () {
        this.persistAutoBackupSettings({
            'autobackup.lastrun': Date.now()
        })
            .then(() => {
                this.triggerBackupDownload();
                this.initAutoBackup();
            })
            .catch(error => {
                const prefix = this.i18n['COMMON/error/hdr'] || 'Error';
                alert(`${prefix}: ${error.message}`);
            });
    },

    updateAutoBackupPeriod: function (period) {
        this.persistAutoBackupSettings({
            'autobackup.period': period
        })
            .then(() => this.initAutoBackup())
            .catch(error => {
                const prefix = this.i18n['COMMON/error/hdr'] || 'Error';
                alert(`${prefix}: ${error.message}`);
            });
    },

    checkExistingDatabaseRefresh: function () {
        fetch('/settings/refresh/progress')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'queued' || data.status === 'running') {
                    this.startDatabaseRefreshPolling(data);
                }
            })
            .catch(err => console.error('Database refresh check error:', err));
    },

    refreshDatabase: function () {
        const button = document.getElementById('refreshDatabaseButton');
        if (button) button.disabled = true;

        const tokenMeta = document.querySelector('meta[name="csrf-token"]');

        fetch('/settings/refresh', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': tokenMeta ? tokenMeta.getAttribute('content') : '',
                'Accept': 'application/json'
            }
        })
            .then(async response => {
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Database refresh failed to start.');
                }

                this.startDatabaseRefreshPolling({
                    status: 'queued',
                    total: data.total || 0,
                    processed: 0,
                    current: null
                });
            })
            .catch(error => {
                if (button) button.disabled = false;
                const prefix = this.i18n['COMMON/error/hdr'] || 'Error';
                alert(`${prefix}: ${error.message}`);
            });
    },

    startDatabaseRefreshPolling: function (initialData = null) {
        if (this.refreshPollingInterval) {
            clearInterval(this.refreshPollingInterval);
        }

        const data = initialData || {};
        const total = Number(data.total || 0);

        if (window.QueryMonitor) {
            window.QueryMonitor.start(total, 'series');
            window.QueryMonitor.update(Number(data.processed || 0), total, data.current || 'series');
        }

        const button = document.getElementById('refreshDatabaseButton');
        if (button) button.disabled = true;

        this.refreshPollingInterval = setInterval(() => {
            fetch('/settings/refresh/progress')
                .then(response => response.json())
                .then(progress => {
                    const processed = Number(progress.processed || 0);
                    const progressTotal = Number(progress.total || 0);

                    if (window.QueryMonitor) {
                        window.QueryMonitor.update(
                            processed,
                            progressTotal,
                            progress.current || 'series'
                        );
                    }

                    if (progress.status === 'completed' || progress.status === 'failed') {
                        clearInterval(this.refreshPollingInterval);
                        this.refreshPollingInterval = null;

                        if (window.QueryMonitor) {
                            if (progress.status === 'completed') {
                                window.QueryMonitor.update(progressTotal, progressTotal, 'series');
                            }
                            window.QueryMonitor.finish();
                        }

                        if (button) button.disabled = false;

                        if (progress.status === 'failed') {
                            const prefix = this.i18n['COMMON/error/hdr'] || 'Error';
                            alert(`${prefix}: ${progress.message || 'Database refresh failed.'}`);
                        }
                    }
                })
                .catch(error => {
                    console.error('Database refresh polling error:', error);
                });
        }, 1000);
    },

    clearInput: function () {
        const input = document.getElementById('backupInput');
        if (input) input.value = '';
    },

    startPolling: function () {
        this.lastLogCount = 0;
        this.lastFailedCount = 0;
        this.posters = [];
        this.failedSeries = [];

        if (this.pollingInterval) clearInterval(this.pollingInterval);

        this.pollingInterval = setInterval(() => {
            fetch('/settings/restore/progress')
                .then(response => response.json())
                .then(data => {
                    this.updateUI(data);

                    if (data.status === 'completed' || data.status === 'failed' || data.status === 'cancelled') {
                        clearInterval(this.pollingInterval);
                        this.finishRestore(data);
                    }
                })
                .catch(err => console.error('Polling error:', err));
        }, 1000);
    },

    updateUI: function (data) {
        // Track unique posters
        if (data.poster && !this.posters.includes(data.poster)) {
            this.posters.push(data.poster);
            if (this.posters.length > 20) this.posters.shift();
        }

        // Track failed series
        if (data.failed_series && data.failed_series.length > this.failedSeries.length) {
            this.failedSeries = data.failed_series;
        }

        if (this.isMinimized) {
            this.updateMiniUI(data);
        } else {
            this.updateDetailedUI(data);
        }
    },

    updateDetailedUI: function (data) {
        if (!this.progressModal || !this.progressModal.el) return;

        const percent = data.percent || 0;
        const mainBar = this.progressModal.el.querySelector('.main-progress-bar');
        const statusText = this.progressModal.el.querySelector('.restore-status-text');
        const showProgressDiv = this.progressModal.el.querySelector('.restore-show-progress');
        const logContainer = this.progressModal.el.querySelector('.restore-logs');
        const thumbnailTrack = this.progressModal.el.querySelector('.restore-thumbnails-track');
        const failuresContainer = this.progressModal.el.querySelector('.restore-failures-container');
        const failuresList = this.progressModal.el.querySelector('.restore-failed-items');

        if (mainBar) mainBar.style.width = percent + '%';

        let statusMsg = `${this.i18n['COMMON/loading-please-wait/lbl'] || 'Processing...'} ${percent}%`;
        if (data.status === 'extracting') statusMsg = this.i18n['BACKUPCTRLjs/progress/extracting'] || 'Extracting backup file...';
        if (statusText) statusText.textContent = statusMsg;

        if (data.show && data.type === 'show_progress') {
            if (showProgressDiv) {
                showProgressDiv.style.display = 'block';
                const showTitle = showProgressDiv.querySelector('.restore-show-text');
                const showBar = showProgressDiv.querySelector('.show-progress-bar');

                if (showTitle) {
                    const prefix = this.i18n['COMMON/searching/lbl'] || 'Restoring';
                    const strong = document.createElement('strong');
                    strong.textContent = String(data.show);

                    showTitle.replaceChildren(document.createTextNode(`${prefix}: `), strong);

                    if (data.season) {
                        const seasonLabel = this.i18n['COMMON/season/lbl'] || 'Season';
                        showTitle.appendChild(document.createTextNode(` (${seasonLabel} ${data.season})`));
                    }
                }
                if (showBar) showBar.style.width = (data.percent || 0) + '%';
            }
        } else if (data.status === 'running') {
            // Keep current show if just global percent update
        } else {
            if (showProgressDiv) showProgressDiv.style.display = 'none';
        }

        // Update Thumbnails
        if (thumbnailTrack) {
            const currentImgCount = thumbnailTrack.querySelectorAll('img').length;
            if (this.posters.length > currentImgCount) {
                for (let i = currentImgCount; i < this.posters.length; i++) {
                    const img = document.createElement('img');
                    img.src = this.posters[i];
                    img.style.height = '100px';
                    img.style.border = '1px solid #444';
                    img.style.borderRadius = '3px';
                    img.style.boxShadow = '0 2px 5px rgba(0,0,0,0.5)';
                    thumbnailTrack.appendChild(img);
                }
                // Scroll to end
                const offset = Math.max(0, thumbnailTrack.scrollWidth - thumbnailTrack.parentElement.clientWidth);
                thumbnailTrack.style.left = `-${offset}px`;
            }
        }

        // Update Failures
        if (this.failedSeries.length > 0 && failuresContainer && failuresList) {
            failuresContainer.style.display = 'block';
            if (this.failedSeries.length > this.lastFailedCount) {
                for (let i = this.lastFailedCount; i < this.failedSeries.length; i++) {
                    const failure = this.failedSeries[i];
                    const li = document.createElement('li');
                    const time = document.createElement('strong');
                    time.textContent = String(failure.time ?? '');
                    li.appendChild(time);
                    li.appendChild(document.createTextNode(`: ${failure.id ?? ''} - ${failure.error ?? ''}`));
                    failuresList.appendChild(li);
                }
                this.lastFailedCount = this.failedSeries.length;
                failuresList.scrollTop = failuresList.scrollHeight;
            }
        }

        // Update Logs
        if (data.logs && data.logs.length > this.lastLogCount && logContainer) {
            for (let i = this.lastLogCount; i < data.logs.length; i++) {
                const logLine = document.createElement('div');
                logLine.textContent = data.logs[i];
                logContainer.appendChild(logLine);
            }
            this.lastLogCount = data.logs.length;
            logContainer.scrollTop = logContainer.scrollHeight;
        }
    },

    updateMiniUI: function (data) {
        if (!this.miniProgress) return;

        const percent = data.percent || 0;
        const mainBar = this.miniProgress.querySelector('.main-progress-bar');
        const statusText = this.miniProgress.querySelector('.mini-status-text');
        const percentLabel = this.miniProgress.querySelector('.percent-label');
        const thumbnailTrackMini = this.miniProgress.querySelector('.restore-thumbnails-track-mini');

        if (mainBar) mainBar.style.width = percent + '%';
        if (percentLabel) percentLabel.textContent = percent + '%';

        let statusMsg = this.i18n['COMMON/loading-please-wait/lbl'] || 'Processing...';
        if (data.show && data.type === 'show_progress') {
            statusMsg = `${this.i18n['COMMON/searching/lbl'] || 'Restoring'}: ${data.show}`;
        } else if (data.status === 'extracting') {
            statusMsg = this.i18n['BACKUPCTRLjs/progress/extracting'] || 'Extracting...';
        } else if (data.logs && data.logs.length > 0) {
            statusMsg = data.logs[data.logs.length - 1];
        }
        if (statusText) statusText.textContent = statusMsg;

        // Update Mini Thumbnails
        if (thumbnailTrackMini) {
            const currentImgCount = thumbnailTrackMini.querySelectorAll('img').length;
            if (this.posters.length > currentImgCount) {
                for (let i = currentImgCount; i < this.posters.length; i++) {
                    const img = document.createElement('img');
                    img.src = this.posters[i];
                    img.style.height = '40px';
                    img.style.borderRadius = '2px';
                    thumbnailTrackMini.appendChild(img);
                }
                thumbnailTrackMini.scrollLeft = thumbnailTrackMini.scrollWidth;
            }
        }
    },

    finishRestore: function (data) {
        this.status = data.status;

        // Ensure detailed view is visible for the final result
        if (this.isMinimized) {
            this.maximize();
        }

        if (data.status === 'completed') {
            const completeMsg = this.i18n['BACKUPCTRLjs/progress/restore-complete'] || 'Restore Complete! Reloading...';
            this.progressModal.updateProgress(completeMsg, 100);
            setTimeout(() => {
                window.location.reload();
            }, 3000);
        } else {
            const failedMsg = (this.i18n['BACKUPCTRLjs/progress/restore-failed'] || 'Restore Failed: ') + (data.message || '');
            this.progressModal.updateProgress(failedMsg, 100);
            setTimeout(() => {
                // Keep it open if there were failures so user can see them
                if (this.failedSeries.length === 0) {
                    this.progressModal.hide();
                    alert(failedMsg);
                }
            }, 500);
        }
    }
};
