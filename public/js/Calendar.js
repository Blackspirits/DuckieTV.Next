class DuckieCalendar {
    constructor() {
        console.log('Calendar: constructor');
        this.el = document.querySelector('calendar');
        if (!this.el) {
            console.error('Calendar: <calendar> element not found!');
            return;
        }

        this.datePicker = this.el.querySelector('[date-picker]');
        const initialState = this.readState();
        this.currentMode = initialState.mode || this.el.dataset.initialMode || 'month';
        this.currentDate = initialState.date || this.el.dataset.initialDate || new Date().toISOString().slice(0, 10);
        console.log('Calendar: initialized', this.el);
        this.init();
    }

    init() {
        // Observer to watch body classes for sidepanel state
        this.observer = new MutationObserver(() => this.zoom());
        this.observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });

        window.addEventListener('resize', () => this.zoom());
        this.zoom(); // Initial zoom
        this.applyState();

        // Listen for torrent updates to animate progress bars
        document.addEventListener('torrent-status-update', (e) => this.onTorrentUpdate(e));

        // Listen for back/forward browser navigation
        window.addEventListener('popstate', (e) => {
            if (e.state) {
                this.navigate(e.state.mode, e.state.date, false);
            }
        });
    }

    /**
     * Navigate to a different calendar view via XHR
     * @param {string} mode 
     * @param {string} date 
     * @param {boolean} pushState 
     */
    async navigate(mode, date, pushState = true) {
        console.log('Calendar: navigate', mode, date);
        const container = document.getElementById('calendar-content');
        if (!container) return; // Should not happen

        // Update URL
        const url = new URL(window.location.origin + '/calendar');
        url.searchParams.set('mode', mode);
        url.searchParams.set('date', date);

        try {
            // Fetch partial content
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                }
            });

            if (!response.ok) throw new Error('Failed to load calendar');

            const html = await response.text();
            container.innerHTML = html;

            // Re-apply calendar state and zoom to the new content.
            this.datePicker = this.el.querySelector('[date-picker]');
            const state = this.readState();
            this.currentMode = state.mode || mode;
            this.currentDate = state.date || date;
            this.el.dataset.initialMode = this.currentMode;
            this.el.dataset.initialDate = this.currentDate;
            this.applyState(state);
            this.zoom();

            if (pushState) {
                window.history.pushState({ mode, date }, '', url);
            }

        } catch (error) {
            console.error('Calendar: navigation failed', error);
            window.Toast.error('Failed to load calendar view.');
        }
    }

    /**
     * Refresh the current view
     */
    async refresh() {
        // If we haven't navigated yet, try to infer from URL or defaults
        if (!this.currentMode) {
            const params = new URLSearchParams(window.location.search);
            this.currentMode = params.get('mode') || 'month';
            this.currentDate = params.get('date') || new Date().toISOString().slice(0, 10);
        }
        return this.navigate(this.currentMode, this.currentDate, false);
    }

    readState() {
        const node = this.el.querySelector('[data-calendar-state]');
        if (!node) return {};

        try {
            return JSON.parse(node.textContent || '{}');
        } catch (error) {
            console.error('Calendar: invalid state payload', error);
            return {};
        }
    }

    applyState(state = this.readState()) {
        this.state = state || {};
        this.normalizeWeekdayHeaders(Boolean(this.state.startSunday));

        if (this.state.mode === 'month') {
            this.normalizeMonthRows();
        }

        this.applyEpisodePresentation(this.state);
    }

    normalizeWeekdayHeaders(startSunday) {
        if (!this.datePicker) return;

        const rows = Array.from(this.datePicker.querySelectorAll('thead tr'));
        const headerRow = rows.find(row => {
            const labels = Array.from(row.querySelectorAll('th')).map(th => th.textContent.trim());
            return labels.length === 7 && labels.includes('Mon') && labels.includes('Sun');
        });

        if (!headerRow) return;

        const cells = Array.from(headerRow.querySelectorAll('th'));
        const byLabel = new Map(cells.map(cell => [cell.textContent.trim(), cell]));
        const order = startSunday
            ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
            : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        order.forEach(label => {
            const cell = byLabel.get(label);
            if (cell) headerRow.appendChild(cell);
        });
    }

    normalizeMonthRows() {
        if (!this.datePicker) return;

        const tbody = this.datePicker.querySelector('tbody');
        if (!tbody) return;

        const cells = Array.from(tbody.querySelectorAll('td.day'));
        if (cells.length === 0 || cells.length % 7 !== 0) return;

        tbody.innerHTML = '';

        for (let index = 0; index < cells.length; index += 7) {
            const row = document.createElement('tr');
            cells.slice(index, index + 7).forEach(cell => row.appendChild(cell));
            tbody.appendChild(row);
        }
    }

    applyEpisodePresentation(state) {
        const showDownloaded = Boolean(state.showDownloaded);
        const showEpisodeNumbers = Boolean(state.showEpisodeNumbers);
        const downloaded = new Set(
            Array.isArray(state.downloadedEpisodeIds)
                ? state.downloadedEpisodeIds.map(id => String(id))
                : []
        );

        this.el.querySelectorAll('a[data-sidepanel-show]').forEach(anchor => {
            const match = (anchor.dataset.sidepanelShow || '').match(/\/episodes\/(\d+)/);
            if (!match) return;

            const episodeId = match[1];
            const name = anchor.querySelector('.eventNameInner');

            if (name && !showEpisodeNumbers) {
                name.textContent = name.textContent.replace(
                    /\s+-\s+s\d+e\d+(?:\(\d+\))?\s*$/i,
                    ''
                );
            }

            const isDownloaded = downloaded.has(episodeId);
            let overlay = anchor.querySelector('.calendar-downloaded-status');

            if (showDownloaded && isDownloaded && !overlay) {
                overlay = document.createElement('div');
                overlay.className = 'calendar-downloaded-status torrent-mini-remote-control-progress progress-striped progress';
                overlay.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;margin:0;opacity:0.3;z-index:1;';

                const bar = document.createElement('span');
                bar.className = 'progress-bar progress-bar-success';
                bar.style.width = '100%';
                overlay.appendChild(bar);
                anchor.appendChild(overlay);
            } else if ((!showDownloaded || !isDownloaded) && overlay) {
                overlay.remove();
            }

            if (!showDownloaded && isDownloaded) {
                const remoteBar = anchor.querySelector(
                    '.torrent-mini-remote-control-progress:not(.calendar-downloaded-status) .progress-bar'
                );

                if (remoteBar && remoteBar.classList.contains('progress-bar-success')) {
                    remoteBar.classList.remove('progress-bar-success');
                    remoteBar.classList.add('progress-bar-info');
                    remoteBar.style.width = '0%';
                }
            }
        });
    }

    /**
     * Handle real-time torrent progress updates
     * @param {CustomEvent} e 
     */
    onTorrentUpdate(e) {
        const data = e.detail;
        if (!data || !data.torrents) return;

        // Create a map of infoHash -> torrent data for O(1) lookup
        const torrentMap = {};
        data.torrents.forEach(t => {
            // Normalized upper case for comparison
            if (t.infoHash) torrentMap[t.infoHash.toUpperCase()] = t;
        });

        // Find all episode elements with a magnet hash (inside the calendar)
        const episodes = this.el.querySelectorAll('a[data-magnet-hash]');

        episodes.forEach(el => {
            const hash = el.dataset.magnetHash.toUpperCase();
            const bar = el.querySelector(
                '.torrent-mini-remote-control-progress:not(.calendar-downloaded-status) .progress-bar'
            );

            if (!bar) return;

            if (torrentMap[hash]) {
                const t = torrentMap[hash];
                const progress = parseFloat(t.progress || 0);

                bar.style.width = progress + '%';

                // Update class based on state matches legacy logic
                bar.className = 'progress-bar'; // Reset
                if (!t.isStarted && progress < 100) {
                    bar.classList.add('progress-bar-danger');
                } else if (t.isStarted && progress < 100) {
                    bar.classList.add('progress-bar-info');
                } else if (!t.isStarted && progress === 100) {
                    bar.classList.add('progress-bar-success');
                } else if (t.isStarted && progress === 100) {
                    bar.classList.add('progress-bar-warning');
                }
            }
        });
    }

    zoom() {
        if (!this.datePicker) return;

        const isShowing = document.body.classList.contains('sidepanelActive');
        const isExpanded = document.body.classList.contains('sidepanelExpanded');

        let spaceToTheRight = 0;
        if (isExpanded) {
            spaceToTheRight = 840;
        } else if (isShowing) {
            spaceToTheRight = 450;
        }

        const cw = document.body.clientWidth;
        const avail = cw - spaceToTheRight;
        const zoom = avail / cw;

        // console.log(`Calendar: zoom ${zoom} (space: ${spaceToTheRight})`);

        this.datePicker.style.transform = `scale(${zoom})`;
        this.datePicker.style.transformOrigin = 'top left'; // Ensure it scales from top-left

        if (zoom < 1) {
            this.datePicker.classList.add('zoom');
        } else {
            this.datePicker.classList.remove('zoom');
        }
    }
}
