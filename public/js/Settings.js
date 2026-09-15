
window.Settings = {
    save: function (section) {
        const form = document.querySelector(`form[data-section="${section}"]`);
        if (!form) {
            console.error(`Form for section ${section} not found.`);
            return;
        }

        const formData = new FormData(form);
        const data = {};

        // Convert FormData to JSON object, handling booleans and checkboxes
        formData.forEach((value, key) => {
            // Check if it's a checkbox
            const input = form.querySelector(`[name="${key}"]`);
            if (input && input.type === 'checkbox') {
                data[key] = input.checked;
            } else {
                data[key] = value;
            }
        });

        // specific fix for unchecked checkboxes not being in FormData
        form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            if (!data.hasOwnProperty(checkbox.name)) {
                data[checkbox.name] = checkbox.checked;
            }
        });

        const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        return fetch(`/settings/${section}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data)
        })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => Promise.reject(err));
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const btn = form.querySelector('.btn-save');
                    if (btn) {
                        const originalText = btn.innerHTML;
                        btn.innerHTML = '<i class="glyphicon glyphicon-ok"></i> Saved!';
                        btn.classList.add('btn-success');
                        setTimeout(() => {
                            btn.innerHTML = originalText;
                            btn.classList.remove('btn-success');
                        }, 2000);
                    }
                    return data;
                }
            })
            .catch(error => {
                console.error('Error saving settings:', error);
                alert('Error saving settings: ' + (error.message || 'Unknown error'));
            });
    },

    test: function (section) {
        const form = document.querySelector(`form[data-section="${section}"]`);
        if (!form) return;

        const btn = form.querySelector('button[type="submit"]');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="glyphicon glyphicon-refresh spin"></i> Testing...';
        btn.disabled = true;

        const formData = new FormData(form);
        const data = { test: 1 };

        formData.forEach((value, key) => {
            const input = form.querySelector(`[name="${key}"]`);
            if (input && input.type === 'checkbox') {
                data[key] = input.checked;
            } else {
                data[key] = value;
            }
        });

        // Ensure checkboxes are included if unchecked
        form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            if (!data.hasOwnProperty(checkbox.name)) {
                data[checkbox.name] = checkbox.checked;
            }
        });

        const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        fetch(`/settings/${section}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data)
        })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => Promise.reject(err));
                }
                return response.json();
            })
            .then(data => {
                btn.innerHTML = originalText;
                btn.disabled = false;

                const statusEl = document.getElementById('connection-status');
                if (statusEl) {
                    // Hide all states first
                    statusEl.querySelectorAll('p').forEach(p => p.style.display = 'none');

                    if (data.connection_success) {
                        const successEl = statusEl.querySelector('.status-connected');
                        if (successEl) {
                            successEl.style.display = 'block';
                            const infoEl = successEl.querySelector('.server-info');
                            if (infoEl) infoEl.textContent = `${data.server || ''}:${data.port || ''}`;
                        }
                    } else {
                        const errorEl = statusEl.querySelector('.status-error');
                        if (errorEl) {
                            errorEl.style.display = 'block';
                            const msgEl = errorEl.querySelector('.error-message');
                            if (msgEl) msgEl.textContent = data.connection_error || 'Unknown error';
                        }
                    }
                } else {
                    alert(data.connection_success ? 'Connected!' : 'Connection Failed: ' + data.connection_error);
                }
            })
            .catch(error => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                console.error('Error testing settings:', error);
                alert('Error testing: ' + (error.message || 'Unknown error'));
            });
    }
};

// Historical Language settings changed both application.language and
// application.locale, then reloaded so the translation table was rebuilt.
// The server mirrors application.locale into application.language.
window.setLanguageLocale = function (locale) {
    const input = document.getElementById('input_application_locale');
    if (!input) {
        console.error('Language locale input not found.');
        return;
    }

    input.value = String(locale).toLowerCase().replace('-', '_');

    return window.Settings.save('language').then(data => {
        if (data && data.success) {
            window.location.reload();
        }

        return data;
    });
};

window.SubtitleSettings = {
    current: function () {
        const root = document.getElementById('subtitle-settings');
        if (!root) return [];

        try {
            const selected = JSON.parse(root.dataset.selectedLanguages || '[]');
            return Array.isArray(selected) ? selected : [];
        } catch (error) {
            console.error('Invalid subtitle language state:', error);
            return [];
        }
    },

    save: function (languages) {
        const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        return fetch('/settings/subtitles', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ 'subtitles.languages': languages })
        })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => Promise.reject(err));
                }

                return response.json();
            })
            .then(data => {
                if (data.success) {
                    this.render(languages);
                }

                return data;
            })
            .catch(error => {
                console.error('Error saving subtitle languages:', error);
                alert('Error saving subtitle languages: ' + (error.message || 'Unknown error'));
            });
    },

    render: function (languages) {
        const root = document.getElementById('subtitle-settings');
        if (!root) return;

        root.dataset.selectedLanguages = JSON.stringify(languages);

        const selectedNames = [];
        root.querySelectorAll('[data-subtitle-code]').forEach(button => {
            const enabled = languages.includes(button.dataset.subtitleCode);
            button.classList.toggle('btn-success', enabled);

            if (enabled) {
                selectedNames.push(button.dataset.subtitleName);
            }
        });

        const selectedLabel = document.getElementById('subtitle-selected-label');
        const selectedText = document.getElementById('subtitle-selected-languages');
        const selectedNone = document.getElementById('subtitle-selected-none');
        const clearButton = document.getElementById('subtitle-clear-selection');
        const hasSelection = languages.length > 0;

        if (selectedLabel) selectedLabel.style.display = hasSelection ? '' : 'none';
        if (selectedText) selectedText.textContent = selectedNames.join(', ');
        if (selectedNone) selectedNone.style.display = hasSelection ? 'none' : '';
        if (clearButton) clearButton.style.display = hasSelection ? 'inline-block' : 'none';
    },

    toggle: function (code) {
        const languages = this.current();
        const next = languages.includes(code)
            ? languages.filter(language => language !== code)
            : [...languages, code];

        return this.save(next);
    },

    clear: function () {
        return this.save([]);
    }
};

window.toggleSubtitleLanguage = function (code) {
    return window.SubtitleSettings.toggle(code);
};

window.clearSubtitleLanguages = function () {
    return window.SubtitleSettings.clear();
};

// Global functions for Torrent Settings (accessed via inline onclick)
window.updateTorrentSetting = function (key, value) {
    const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    return fetch('/settings/torrent', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ [key]: value })
    }).then(response => {
        if (!response.ok) {
            return response.json().then(err => Promise.reject(err));
        }
        return response.json();
    });
};

window.toggleTorrent = function () {
    console.error("Use toggleTorrentSetting(newValue) instead.");
};

window.toggleTorrentSetting = function (key, newValue) {
    window.updateTorrentSetting(key, newValue).then(() => {
        window.location.reload();
    }).catch(error => {
        console.error('Failed to update setting', error);
        alert('Failed to update setting: ' + (error.message || 'Unknown error'));
    });
};

window.setTorrentClient = function (clientKey) {
    window.updateTorrentSetting('torrenting.client', clientKey).then(() => {
        // Update UI without reload
        document.querySelectorAll('.choose-client').forEach(btn => {
            btn.classList.remove('btn-success');
            if (btn.getAttribute('data-client-key') === clientKey) {
                btn.classList.add('btn-success');
            }
        });

        // Trigger sidebar refresh via SidePanel
        if (window.SidePanel) {
            window.SidePanel.update('/settings');
        } else {
            console.error('SidePanel not initialized, falling back to reload');
            window.location.reload();
        }
    }).catch(error => {
        console.error('Failed to set torrent client', error);
        alert('Failed to set torrent client: ' + (error.message || 'Unknown error'));
    });
};

window.updateSidebarClient = function () {
    // Deprecated: now handled by SidePanel.update('/settings') in setTorrentClient
    if (window.SidePanel) {
        window.SidePanel.update('/settings');
    }
};

// Torrent Search settings use a distinct validation contract and endpoint.
window.updateTorrentSearchSetting = function (key, value) {
    const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    return fetch('/settings/torrent-search', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ [key]: value })
    }).then(response => {
        if (!response.ok) {
            return response.json().then(err => Promise.reject(err));
        }

        return response.json();
    });
};

window.refreshTorrentSearchSettings = function () {
    if (window.SidePanel && document.querySelector('[data-section="torrent-search"]')) {
        return window.SidePanel.expand('/settings/torrent-search');
    }

    window.location.reload();
    return undefined;
};

window.saveTorrentSearchSetting = function (key, value) {
    return window.updateTorrentSearchSetting(key, value).then(data => {
        if (data && data.success) {
            window.refreshTorrentSearchSettings();
        }

        return data;
    }).catch(error => {
        console.error('Failed to save torrent search setting:', error);
        alert('Failed to save torrent search setting: ' + (error.message || 'Unknown error'));
    });
};

window.toggleTorrentSearchSetting = function (key, value) {
    return window.saveTorrentSearchSetting(key, value);
};

window.numericSettingOrNull = function (inputId) {
    const input = document.getElementById(inputId);
    if (!input || input.value.trim() === '') {
        return null;
    }

    return Number(input.value);
};

window.setSearchProvider = function (provider) {
    return window.saveTorrentSearchSetting('torrenting.searchprovider', provider);
};

window.setSearchQuality = function (quality) {
    return window.saveTorrentSearchSetting('torrenting.searchquality', quality);
};
