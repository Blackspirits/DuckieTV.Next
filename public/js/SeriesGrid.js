/**
 * Historical DuckieTV series-grid positioning, ported from the Angular
 * seriesGrid directive to Vanilla JS.
 *
 * library.seriesgrid=false keeps the normal flex layout. When enabled,
 * poster cards are positioned and animated explicitly so the legacy
 * [series-grid=true] CSS does not stack every card at 0,0.
 */
(function () {
    const instances = new WeakMap();

    class SeriesGrid {
        constructor(container) {
            this.container = container;
            this.grid = container.querySelector('.series-grid');
            this.scrollTimer = null;

            if (!this.grid) return;

            this.resizeHandler = () => this.recalculate();
            window.addEventListener('resize', this.resizeHandler);

            if ('ResizeObserver' in window) {
                this.resizeObserver = new ResizeObserver(() => this.recalculate());
                this.resizeObserver.observe(this.grid);
            }

            this.attributeObserver = new MutationObserver(() => this.recalculate());
            this.attributeObserver.observe(this.container, {
                attributes: true,
                attributeFilter: ['class', 'series-grid']
            });

            // Sidepanel/library updates can add or replace poster cards after
            // this instance already exists. Recalculate only on child-list
            // changes so our own style mutations cannot create an observer loop.
            this.gridObserver = new MutationObserver(() => this.recalculate());
            this.gridObserver.observe(this.grid, {
                childList: true
            });

            this.recalculate();
        }

        recalculate() {
            if (!this.grid) return;

            const allItems = Array.from(this.grid.querySelectorAll('serieheader'));
            const enabled = this.container.getAttribute('series-grid') === 'true';

            if (!enabled) {
                this.grid.style.position = '';
                this.grid.style.height = '';
                allItems.forEach(item => {
                    item.style.transform = '';
                });
                this.scrollToActive();
                return;
            }

            const items = allItems.filter(item => window.getComputedStyle(item).display !== 'none');
            const hiddenItems = allItems.filter(item => !items.includes(item));

            hiddenItems.forEach(item => {
                item.style.transform = '';
            });

            if (items.length === 0) {
                this.grid.style.height = '0px';
                return;
            }

            const isMini = this.container.classList.contains('miniposter');
            const posterWidth = isMini ? 140 : 175;
            const posterHeight = isMini ? 197 : 247;
            const gridWidth = this.grid.clientWidth;

            if (gridWidth <= 0) return;

            const postersPerRow = Math.max(1, Math.floor(gridWidth / posterWidth));
            const rowCount = Math.ceil(items.length / postersPerRow);

            this.grid.style.position = 'relative';
            this.grid.style.height = (rowCount * posterHeight) + 'px';

            items.forEach((item, index) => {
                const row = Math.floor(index / postersPerRow);
                const rowStart = row * postersPerRow;
                const postersInRow = Math.min(postersPerRow, items.length - rowStart);
                const positionInRow = index - rowStart;
                const rowWidth = postersInRow * posterWidth;
                const left = ((gridWidth - rowWidth) / 2) + (positionInRow * posterWidth);
                const top = row * posterHeight;

                item.style.transform = 'translate3d(' + left + 'px, ' + top + 'px, 0px)';
            });

            this.scrollToActive();
        }

        scrollToActive() {
            const active = this.grid.querySelector('.serieheader.active, serieheader.active');
            if (!active) return;

            clearTimeout(this.scrollTimer);
            this.scrollTimer = setTimeout(() => {
                active.scrollIntoView({ block: 'end', behavior: 'smooth' });
            }, 700);
        }
    }

    function initialise(root) {
        const containers = [];

        if (root.nodeType === Node.ELEMENT_NODE && root.matches('[series-grid]')) {
            containers.push(root);
        }

        if (root.querySelectorAll) {
            root.querySelectorAll('[series-grid]').forEach(container => containers.push(container));
        }

        containers.forEach(container => {
            if (!instances.has(container)) {
                instances.set(container, new SeriesGrid(container));
            }
        });
    }

    function recalculateAll() {
        document.querySelectorAll('[series-grid]').forEach(container => {
            const instance = instances.get(container);
            if (instance) instance.recalculate();
        });
    }

    document.addEventListener('DOMContentLoaded', () => initialise(document));
    document.addEventListener('seriesgrid:recalculate', recalculateAll);

    const documentObserver = new MutationObserver(mutations => {
        mutations.forEach(mutation => {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    initialise(node);
                }
            });
        });
    });

    documentObserver.observe(document.documentElement, {
        childList: true,
        subtree: true
    });

    window.SeriesGrid = {
        initialise,
        recalculateAll
    };
})();
