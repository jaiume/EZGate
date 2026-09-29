(function () {
    var root = document.getElementById('dashboard-live');
    if (!root) {
        return;
    }

    var intervalMs = Math.max(2, parseInt(root.getAttribute('data-interval') || '5', 10)) * 1000;
    var inFlight = false;
    var timeFormatter = new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        timeZoneName: 'short',
    });

    function formatTimes(scope) {
        scope.querySelectorAll('time[data-utc][datetime]').forEach(function (el) {
            var iso = el.getAttribute('datetime');
            if (!iso) {
                return;
            }
            var date = new Date(iso);
            if (Number.isNaN(date.getTime())) {
                return;
            }
            el.textContent = timeFormatter.format(date);
        });
    }

    function openCollapseIds() {
        return Array.prototype.map.call(root.querySelectorAll('.accordion-collapse.show'), function (el) {
            return el.id;
        }).filter(Boolean);
    }

    function restoreOpen(ids) {
        ids.forEach(function (id) {
            var panel = document.getElementById(id);
            if (!panel) {
                return;
            }
            panel.classList.add('show');
            var btn = document.querySelector('[data-bs-target="#' + CSS.escape(id) + '"]');
            if (btn) {
                btn.classList.remove('collapsed');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    }

    function modalOpen() {
        return document.querySelector('.modal.show') !== null;
    }

    async function refresh() {
        if (inFlight || document.hidden || modalOpen()) {
            return;
        }
        inFlight = true;
        var open = openCollapseIds();
        try {
            var response = await fetch('/dashboard/live', {
                credentials: 'same-origin',
                headers: { Accept: 'text/html' },
            });
            if (response.redirected || response.status === 401) {
                window.location.href = '/login';
                return;
            }
            if (!response.ok) {
                return;
            }
            var html = await response.text();
            if (html.indexOf('dashboard-live-fragment') < 0) {
                return;
            }
            root.innerHTML = html;
            restoreOpen(open);
            formatTimes(root);
        } catch (_err) {
        } finally {
            inFlight = false;
        }
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            refresh();
        }
    });
    setInterval(refresh, intervalMs);
})();
