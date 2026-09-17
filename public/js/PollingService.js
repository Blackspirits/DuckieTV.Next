/**
 * Lightweight polling service for torrent client status.
 *
 * Keeps at most one request in flight, applies bounded exponential backoff
 * while disconnected/failing, and pauses new polling while the document is hidden.
 */
class PollingService {
    constructor(interval = 2000, translations = {}, requestTimeout = 10000, maxBackoff = 30000) {
        this.interval = interval;
        this.translations = translations;
        this.requestTimeout = requestTimeout;
        this.maxBackoff = maxBackoff;
        this.timer = null;
        this.running = false;
        this.inFlight = false;
        this.abortController = null;
        this.failureCount = 0;
        this.csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        this.visibilityHandler = () => this.handleVisibilityChange();
    }

    start() {
        if (this.running) {
            return;
        }

        this.running = true;
        document.addEventListener?.('visibilitychange', this.visibilityHandler);

        if (!document.hidden) {
            void this.poll();
        }

        console.log('PollingService started.');
    }

    stop() {
        this.running = false;

        if (this.timer !== null) {
            clearTimeout(this.timer);
            this.timer = null;
        }

        if (this.abortController !== null) {
            this.abortController.abort();
            this.abortController = null;
        }

        document.removeEventListener?.('visibilitychange', this.visibilityHandler);
        console.log('PollingService stopped.');
    }

    schedule(delay) {
        if (!this.running || document.hidden || this.timer !== null) {
            return;
        }

        this.timer = setTimeout(() => {
            this.timer = null;
            void this.poll();
        }, delay);
    }

    handleVisibilityChange() {
        if (document.hidden) {
            if (this.timer !== null) {
                clearTimeout(this.timer);
                this.timer = null;
            }
            return;
        }

        if (this.running && !this.inFlight) {
            this.failureCount = 0;
            void this.poll();
        }
    }

    backoffDelay() {
        const exponent = Math.min(this.failureCount, 4);
        return Math.min(this.maxBackoff, this.interval * (2 ** exponent));
    }

    emitMetric(startedAt, outcome, nextDelay) {
        const now = globalThis.performance?.now?.() ?? Date.now();
        document.dispatchEvent(new CustomEvent('torrent-poll-metric', {
            detail: {
                durationMs: Math.max(0, Math.round(now - startedAt)),
                outcome,
                nextDelayMs: nextDelay,
            },
        }));
    }

    async poll() {
        if (!this.running || this.inFlight || document.hidden) {
            return false;
        }

        this.inFlight = true;
        const startedAt = globalThis.performance?.now?.() ?? Date.now();
        const controller = new AbortController();
        this.abortController = controller;
        const timeout = setTimeout(() => controller.abort(), this.requestTimeout);
        let outcome = 'error';
        let nextDelay = this.interval;

        try {
            const response = await fetch('/torrents/status', {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            this.handleStatusUpdate(data);

            if (data.connected) {
                this.failureCount = 0;
                outcome = 'connected';
            } else {
                this.failureCount += 1;
                nextDelay = this.backoffDelay();
                outcome = 'disconnected';
            }

            return true;
        } catch (error) {
            const stoppedAbort = error?.name === 'AbortError' && !this.running;
            if (!stoppedAbort) {
                console.warn('Polling failed:', error);
                this.handleError(error);
                this.failureCount += 1;
                nextDelay = this.backoffDelay();
                outcome = error?.name === 'AbortError' ? 'timeout' : 'error';
            } else {
                outcome = 'stopped';
            }

            return false;
        } finally {
            clearTimeout(timeout);
            if (this.abortController === controller) {
                this.abortController = null;
            }
            this.inFlight = false;
            this.emitMetric(startedAt, outcome, nextDelay);

            if (this.running && !document.hidden) {
                this.schedule(nextDelay);
            }
        }
    }

    handleStatusUpdate(data) {
        const icon = document.querySelector('#actionbar_torrent a');
        if (!icon) return;

        const panel = document.querySelector('.torrent-client');

        if (data.connected) {
            // Only update status color. Icon class is handled by Blade.
            // Explicitly set the client class if it's different from the initial load? 
            // The user wanted Blade to handle it. If we switch clients, a page reload might be expected 
            // or we could update the class here if absolutely necessary, but for now we stick to just status.
            // Actually, if the user switches clients in settings, the page usually reloads.
            // So we can assume the class on the icon is correct for the active client.

            icon.style.color = '#5cb85c'; // Bootstrap success green
            const connectedText = this.translations.connected || 'Connected to';
            icon.title = `${connectedText} ${data.client} (${data.active_count})`;

            // If we were showing a connecting message, and now we are connected, refresh the whole panel
            // Or if we were showing a "no torrents" message but now we have torrents, refresh.
            if (panel) {
                const isConnecting = panel.querySelector('.connecting-message');
                const isEmpty = panel.querySelector('.no-torrents-message');

                if (data.connected && (isConnecting || (isEmpty && data.active_count > 0))) {
                    const url = panel.dataset.url;
                    if (url && window.SidePanel) {
                        window.SidePanel.update(url);
                        return; // update will handle the new content
                    }
                }
            }

            // Update torrent list if visible
            const list = panel ? panel.querySelector('.torrent-list') : null;
            if (list && data.torrents) {
                data.torrents.forEach(torrent => {
                    const torrentEl = list.querySelector(`[data-sidepanel-expand*="${torrent.infoHash}"]`);
                    if (torrentEl) {
                        const progressContainer = torrentEl.closest('.torrent').querySelector('.progress-bar');
                        if (progressContainer) {
                            progressContainer.style.width = torrent.progress + '%';
                            progressContainer.querySelector('span').textContent = torrent.progress + '%';

                            // Update color based on status
                            progressContainer.className = 'progress-bar ' +
                                (!torrent.isStarted && torrent.progress < 100 ? 'progress-bar-danger' :
                                    (torrent.isStarted && torrent.progress < 100 ? 'progress-bar-info' :
                                        (!torrent.isStarted && torrent.progress == 100 ? 'progress-bar-success' : 'progress-bar-warning')));
                        }
                    }
                });
            }

            // Update torrent details if visible
            const details = document.querySelector('.torrent-details');
            if (details && data.torrents) {
                const currentHash = details.dataset.infoHash;
                const torrent = data.torrents.find(t => t.infoHash === currentHash);
                if (torrent) {
                    if (window.DialGauge) {
                        window.DialGauge.update(details.querySelector('#gauge1'), Math.floor((torrent.downloadSpeed / 1000) * 10) / 10);
                        window.DialGauge.update(details.querySelector('#gauge2'), torrent.progress);
                    }
                }
            }

            // Dispatch a custom event for other components (e.g. TorrentDialog)
            const event = new CustomEvent('torrent-status-update', { detail: data });
            document.dispatchEvent(event);
        } else {
            // Disconnected state
            // We do NOT reset the icon class/image here as per user request to avoid "bullshit default icon"
            // We just indicate error state via color/title

            icon.style.color = '#d9534f'; // Bootstrap danger red
            icon.title = this.translations.disconnected || 'Torrent Client Disconnected';

            // Show error in panel if it's open and showing connecting
            if (panel && panel.querySelector('.connecting-message') && data.error) {
                const msg = panel.querySelector('.connecting-message strong');
                if (msg) {
                    msg.textContent = '';
                    const errorText = document.createElement('span');
                    errorText.className = 'text-danger';
                    errorText.textContent = String(data.error);
                    msg.appendChild(errorText);
                }
            }
        }
    }

    handleError(error) {
        const icon = document.querySelector('#actionbar_torrent a');
        if (icon) {
            icon.style.color = '#f0ad4e'; // Bootstrap warning orange
            icon.title = this.translations.error || 'Polling Error';
        }
    }
}
