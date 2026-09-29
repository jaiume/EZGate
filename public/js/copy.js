(function () {
    document.addEventListener('click', function (event) {
        var el = event.target.closest('.js-copy-hardware-id');
        if (!el) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        var text = (el.getAttribute('data-copy') || '').trim();
        if (!text) {
            return;
        }
        copyText(text).then(function () {
            el.classList.add('is-copied');
            el.setAttribute('title', 'Copied');
            window.setTimeout(function () {
                el.classList.remove('is-copied');
                el.setAttribute('title', 'Copy device ID');
            }, 1500);
        }).catch(function () {});
    }, true);

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.left = '-9999px';
            document.body.appendChild(area);
            area.select();
            try {
                if (!document.execCommand('copy')) {
                    reject(new Error('copy failed'));
                    return;
                }
                resolve();
            } catch (err) {
                reject(err);
            } finally {
                document.body.removeChild(area);
            }
        });
    }
})();
