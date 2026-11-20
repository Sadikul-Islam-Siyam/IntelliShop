/**
 * IntelliShop Smooth UX, History Management & Async Interactions Engine
 * Zero extra dependencies. Progressive enhancement over standard Laravel Blade + REST API.
 */
(function () {
    'use strict';

    const state = {
        activeControllers: new Map(),
        prefetchedUrls: new Set(),
        scrollMemory: new Map(),
        progressTimer: null,
    };

    /* ------------------------------------------------------------------
     * 1. Top Progress Bar
     * ------------------------------------------------------------------ */
    function getProgressBar() {
        let bar = document.getElementById('is-progress-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'is-progress-bar';
            bar.className = 'is-progress-bar';
            document.body.prepend(bar);
        }
        return bar;
    }

    function startProgress() {
        const bar = getProgressBar();
        clearTimeout(state.progressTimer);
        bar.style.width = '18%';
        bar.classList.add('is-active');
        state.progressTimer = setTimeout(() => {
            bar.style.width = '72%';
        }, 180);
    }

    function finishProgress() {
        const bar = getProgressBar();
        clearTimeout(state.progressTimer);
        bar.style.width = '100%';
        state.progressTimer = setTimeout(() => {
            bar.classList.remove('is-active');
            setTimeout(() => { bar.style.width = '0%'; }, 200);
        }, 160);
    }

    /* ------------------------------------------------------------------
     * 2. Toast Notifications
     * ------------------------------------------------------------------ */
    function getToastStack() {
        let stack = document.getElementById('is-toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'is-toast-stack';
            stack.className = 'is-toast-stack';
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }
        return stack;
    }

    function toast(message, type = 'success', duration = 4200) {
        if (!message) return;
        const stack = getToastStack();
        const el = document.createElement('div');
        el.className = `is-toast is-${type}`;
        el.innerHTML = `
            <span>${escapeHtml(message)}</span>
            <button type="button" class="is-toast-close" aria-label="Dismiss">&times;</button>
        `;
        const dismiss = () => {
            el.classList.remove('is-show');
            setTimeout(() => el.remove(), 200);
        };
        el.querySelector('.is-toast-close').addEventListener('click', dismiss);
        stack.appendChild(el);
        requestAnimationFrame(() => el.classList.add('is-show'));
        if (duration > 0) {
            setTimeout(dismiss, duration);
        }
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ------------------------------------------------------------------
     * 3. Collapsible Desktop Sidebar + Mobile Drawer + Auto Responsive Tables
     * ------------------------------------------------------------------ */
    function isMobileViewport() {
        return window.matchMedia('(max-width: 1024px)').matches;
    }

    function ensureResponsiveTables(root = document) {
        root.querySelectorAll('.is-page-body table').forEach((table) => {
            if (table.closest('.is-table-wrap, .table-responsive, .table-wrapper, .data-table-card')) {
                return;
            }
            const wrapper = document.createElement('div');
            wrapper.className = 'is-table-wrap';
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        });
    }

    function initSidebar() {
        const sidebar = document.getElementById('is-sidebar');
        const toggle = document.getElementById('is-menu-toggle');
        const backdrop = document.getElementById('is-sidebar-backdrop');
        if (!sidebar || !toggle || !backdrop) return;

        const closeMobile = () => {
            sidebar.classList.remove('is-open');
            backdrop.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
        };

        const openMobile = () => {
            sidebar.classList.add('is-open');
            backdrop.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
        };

        const toggleSidebar = () => {
            if (isMobileViewport()) {
                sidebar.classList.contains('is-open') ? closeMobile() : openMobile();
            } else {
                const collapsed = document.documentElement.classList.toggle('is-sidebar-collapsed');
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                try {
                    localStorage.setItem('intellishop.sidebarCollapsed', collapsed ? '1' : '0');
                } catch (e) {}
            }
        };

        toggle.addEventListener('click', toggleSidebar);
        backdrop.addEventListener('click', closeMobile);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
                closeMobile();
            }
            // Ctrl+B shortcut to toggle sidebar on desktop
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
                const tag = (document.activeElement && document.activeElement.tagName) || '';
                if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) {
                    e.preventDefault();
                    toggleSidebar();
                }
            }
        });

        window.addEventListener('resize', () => {
            if (!isMobileViewport() && sidebar.classList.contains('is-open')) {
                closeMobile();
            }
        });

        // Hover prefetch for fast module switching
        sidebar.querySelectorAll('a.is-nav-link[href]').forEach((link) => {
            const prefetch = () => {
                const href = link.getAttribute('href');
                if (!href || href.startsWith('#') || state.prefetchedUrls.has(href)) return;
                if (href === window.location.pathname) return;
                state.prefetchedUrls.add(href);
                const hint = document.createElement('link');
                hint.rel = 'prefetch';
                hint.href = href;
                document.head.appendChild(hint);
            };
            link.addEventListener('mouseenter', prefetch, { passive: true, once: true });
            link.addEventListener('focus', prefetch, { passive: true, once: true });
        });
    }

    /* ------------------------------------------------------------------
     * 4. Skeleton Rows + Async Swap + Browser History
     * ------------------------------------------------------------------ */
    function renderTableSkeletons(container) {
        container.querySelectorAll('table').forEach((table) => {
            const tbody = table.querySelector('tbody');
            const thCount = table.querySelectorAll('thead th').length || 5;
            if (!tbody) return;
            const widths = ['68%', '52%', '40%', '58%', '46%', '35%'];
            let rowsHtml = '';
            for (let r = 0; r < 5; r++) {
                let cellsHtml = '';
                for (let c = 0; c < thCount; c++) {
                    const w = widths[(r + c) % widths.length];
                    cellsHtml += `<td><div class="is-skeleton-bar" style="width:${w};"></div></td>`;
                }
                rowsHtml += `<tr class="is-skeleton-row">${cellsHtml}</tr>`;
            }
            tbody.innerHTML = rowsHtml;
        });
    }

    function buildUrlFromForm(form) {
        const action = form.getAttribute('action') || window.location.pathname;
        const url = new URL(action, window.location.origin);
        const formData = new FormData(form);
        url.search = '';
        for (const [key, value] of formData.entries()) {
            const trimmed = typeof value === 'string' ? value.trim() : value;
            if (trimmed !== '') {
                url.searchParams.append(key, trimmed);
            }
        }
        // Reset pagination to page 1 whenever search or filters change
        url.searchParams.delete('page');
        return url.toString();
    }

    function syncFormsWithUrl(urlString) {
        const url = new URL(urlString, window.location.origin);
        document.querySelectorAll('form[data-async-form]').forEach((form) => {
            form.querySelectorAll('input[name], select[name]').forEach((field) => {
                if (field.type === 'hidden' || field.type === 'submit') return;
                const paramVal = url.searchParams.get(field.name);
                if (field.type === 'checkbox') {
                    field.checked = paramVal !== null && paramVal !== '' && paramVal !== '0';
                } else {
                    field.value = paramVal !== null ? paramVal : '';
                }
            });
        });
    }

    async function fetchAndSwap(url, targetSelector, historyMode = 'replace') {
        const targets = document.querySelectorAll(targetSelector);
        if (!targets.length) {
            window.location.assign(url);
            return;
        }

        // Cancel any outdated in-flight request for this target
        const prevController = state.activeControllers.get(targetSelector);
        if (prevController) {
            prevController.abort();
        }
        const controller = new AbortController();
        state.activeControllers.set(targetSelector, controller);

        targets.forEach((el) => {
            el.classList.add('is-loading');
            renderTableSkeletons(el);
        });
        startProgress();

        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html, application/xhtml+xml',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            });

            if (response.redirected && response.url.includes('/login')) {
                window.location.assign(response.url);
                return;
            }

            if (!response.ok) {
                throw new Error(`Request failed (${response.status})`);
            }

            const html = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            const newTargets = doc.querySelectorAll(targetSelector);
            if (!newTargets.length) {
                window.location.assign(url);
                return;
            }

            targets.forEach((oldEl, idx) => {
                if (newTargets[idx]) {
                    oldEl.innerHTML = newTargets[idx].innerHTML;
                }
                oldEl.classList.remove('is-loading');
            });

            // Update browser history cleanly
            if (historyMode === 'push') {
                state.scrollMemory.set(window.location.href, window.scrollY);
                window.history.pushState({ isAsync: true, targetSelector, url }, '', url);
            } else if (historyMode === 'replace') {
                window.history.replaceState({ isAsync: true, targetSelector, url }, '', url);
            }

            // Re-bind dynamic listeners inside swapped regions
            ensureResponsiveTables();
            bindAsyncPagination();
            bindAsyncActions();

            // Notify module scripts (e.g., Chart.js re-init on reports)
            document.dispatchEvent(new CustomEvent('intellishop:swapped', {
                detail: { url, targetSelector, doc },
            }));
        } catch (err) {
            if (err.name === 'AbortError') return;
            targets.forEach((el) => el.classList.remove('is-loading'));
            toast('Unable to refresh data. Please try again.', 'error');
        } finally {
            if (state.activeControllers.get(targetSelector) === controller) {
                state.activeControllers.delete(targetSelector);
                finishProgress();
            }
        }
    }

    function debounce(fn, wait) {
        let timer = null;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), wait);
        };
    }

    function bindAsyncForms() {
        document.querySelectorAll('form[data-async-form]').forEach((form) => {
            if (form.dataset.isBound === '1') return;
            form.dataset.isBound = '1';

            const targetSelector = form.dataset.asyncTarget || '[data-async-target]';
            const trigger = () => {
                const url = buildUrlFromForm(form);
                fetchAndSwap(url, targetSelector, 'replace');
            };
            const debouncedTrigger = debounce(trigger, 350);

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                trigger();
            });

            form.querySelectorAll('select, input[type="date"], input[type="checkbox"]').forEach((el) => {
                // Remove legacy inline onchange="this.form.submit()" so it stays async!
                if (el.hasAttribute('onchange')) {
                    el.removeAttribute('onchange');
                }
                el.addEventListener('change', trigger);
            });

            form.querySelectorAll('input[type="text"], input[type="search"]').forEach((el) => {
                if (el.dataset.noDebounce === '1') return;
                el.addEventListener('input', debouncedTrigger);
            });
        });
    }

    function bindAsyncPagination() {
        document.querySelectorAll('[data-async-target] .pagination a, [data-async-target] nav[role="navigation"] a').forEach((link) => {
            if (link.dataset.isBound === '1') return;
            link.dataset.isBound = '1';

            link.addEventListener('click', (e) => {
                const href = link.getAttribute('href');
                if (!href || href === '#') return;
                e.preventDefault();
                const container = link.closest('[data-async-target]');
                const selector = container && container.id ? `#${container.id}` : '[data-async-target]';
                fetchAndSwap(href, selector, 'push').then(() => {
                    if (container) {
                        container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });
        });
    }

    /* ------------------------------------------------------------------
     * 5. Async Action Forms (POST/DELETE without full-page reload)
     * ------------------------------------------------------------------ */
    function bindAsyncActions() {
        document.querySelectorAll('form[data-async-action]').forEach((form) => {
            if (form.dataset.isBound === '1') return;
            form.dataset.isBound = '1';

            form.addEventListener('submit', async (e) => {
                const confirmMsg = form.dataset.confirm;
                if (confirmMsg && !window.confirm(confirmMsg)) {
                    e.preventDefault();
                    return;
                }

                e.preventDefault();
                const submitter = e.submitter || form.querySelector('button[type="submit"]');
                const originalHtml = submitter ? submitter.innerHTML : '';
                if (submitter) {
                    submitter.disabled = true;
                    submitter.innerHTML = `<span class="is-spinner"></span>${submitter.dataset.loadingText || 'Working...'}`;
                }

                const formData = new FormData(form);
                if (submitter && submitter.name) {
                    formData.set(submitter.name, submitter.value);
                }

                const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : (formData.get('_token') || '');

                startProgress();
                try {
                    const response = await fetch(form.getAttribute('action'), {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json, text/html',
                        },
                        body: formData,
                        credentials: 'same-origin',
                    });

                    const contentType = response.headers.get('content-type') || '';
                    if (contentType.includes('application/json')) {
                        const data = await response.json();
                        if (!response.ok) {
                            toast(data.message || 'Action failed.', 'error');
                        } else {
                            toast(data.message || form.dataset.successMessage || 'Action completed.', 'success');
                            const refreshTarget = form.dataset.refreshTarget || '[data-async-target]';
                            if (document.querySelector(refreshTarget)) {
                                await fetchAndSwap(window.location.href, refreshTarget, 'none');
                            }
                        }
                    } else {
                        // Server returned HTML (e.g. after standard Laravel redirect)
                        const html = await response.text();
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const refreshTarget = form.dataset.refreshTarget || '[data-async-target]';
                        const targets = document.querySelectorAll(refreshTarget);
                        const newTargets = doc.querySelectorAll(refreshTarget);

                        if (targets.length && newTargets.length) {
                            targets.forEach((oldEl, idx) => {
                                if (newTargets[idx]) {
                                    oldEl.innerHTML = newTargets[idx].innerHTML;
                                }
                            });
                            bindAsyncPagination();
                            bindAsyncActions();
                        }

                        // Extract flash message if present in response
                        const flashEl = doc.querySelector('.alert-success, [data-flash-success]');
                        const msg = flashEl ? flashEl.textContent.trim().replace(/^✓\s*/, '') : (form.dataset.successMessage || 'Updated successfully.');
                        toast(msg, 'success');
                    }
                } catch (err) {
                    toast('Network error while performing action.', 'error');
                } finally {
                    if (submitter && document.body.contains(submitter)) {
                        submitter.disabled = false;
                        submitter.innerHTML = originalHtml;
                    }
                    finishProgress();
                }
            });
        });
    }

    /* ------------------------------------------------------------------
     * 6. Popstate (Browser Back / Forward Restoration)
     * ------------------------------------------------------------------ */
    function initPopstate() {
        window.addEventListener('popstate', (e) => {
            const targetSelector = (e.state && e.state.targetSelector) || '[data-async-target]';
            if (!document.querySelector(targetSelector)) {
                window.location.reload();
                return;
            }
            const url = window.location.href;
            syncFormsWithUrl(url);
            fetchAndSwap(url, targetSelector, 'none').then(() => {
                const savedY = state.scrollMemory.get(url);
                if (typeof savedY === 'number') {
                    window.scrollTo({ top: savedY, behavior: 'auto' });
                }
            });
        });
    }

    /* ------------------------------------------------------------------
     * 7. Boot
     * ------------------------------------------------------------------ */
    document.addEventListener('DOMContentLoaded', () => {
        initSidebar();
        ensureResponsiveTables();
        bindAsyncForms();
        bindAsyncPagination();
        bindAsyncActions();
        initPopstate();
    });

    window.IntelliShopUX = {
        toast,
        fetchAndSwap,
        startProgress,
        finishProgress,
    };
})();
