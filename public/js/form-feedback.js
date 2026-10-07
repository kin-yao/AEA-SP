/* Marks fields that have a validation message, brings the first one into view,
   and explains request failures (expired session, no connection, server error). */
(function () {
    var SEL = '.field-error, .rf-err';
    var seen = new WeakSet();
    var timer = null;
    var toastEl = null;

    function byModel(name) {
        var all = document.querySelectorAll('input,select,textarea');
        for (var i = 0; i < all.length; i++) {
            var a = all[i].attributes;
            for (var j = 0; j < a.length; j++) {
                if (a[j].name.indexOf('wire:model') === 0 && a[j].value === name) return all[i];
            }
        }
        return null;
    }

    function controlFor(err) {
        if (err.dataset && err.dataset.for) { var m = byModel(err.dataset.for); if (m) return m; }
        var el = err.previousElementSibling;
        while (el) {
            if (el.matches && el.matches('input:not([type=hidden]),select,textarea')) return el;
            var inner = el.querySelector && el.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea');
            if (inner) return inner;
            el = el.previousElementSibling;
        }
        var p = err.parentElement;
        return p ? p.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea') : null;
    }

    function toast(message, action) {
        if (toastEl) toastEl.remove();
        toastEl = document.createElement('div');
        toastEl.className = 'fb-toast';
        toastEl.setAttribute('role', 'alert');
        var span = document.createElement('span');
        span.textContent = message;
        toastEl.appendChild(span);
        if (action) {
            var b = document.createElement('button');
            b.type = 'button'; b.textContent = action.label; b.onclick = action.run;
            toastEl.appendChild(b);
        }
        var x = document.createElement('button');
        x.type = 'button'; x.className = 'fb-x'; x.textContent = '×'; x.setAttribute('aria-label', 'Dismiss');
        x.onclick = function () { toastEl.remove(); toastEl = null; };
        toastEl.appendChild(x);
        document.body.appendChild(toastEl);
        clearTimeout(toast.t);
        if (!action) toast.t = setTimeout(function () { if (toastEl) { toastEl.remove(); toastEl = null; } }, 6000);
    }

    function scan() {
        // Browser tooltips are small and easy to miss. The server messages below are the ones we show.
        document.querySelectorAll('form').forEach(function (f) { f.noValidate = true; });
        document.querySelectorAll('.field-invalid').forEach(function (c) { c.classList.remove('field-invalid'); c.removeAttribute('aria-invalid'); });
        var errs = Array.prototype.filter.call(document.querySelectorAll(SEL), function (e) { return e.offsetParent !== null; });
        var fresh = [];
        errs.forEach(function (e) {
            var c = controlFor(e);
            if (c) {
                c.classList.add('field-invalid');
                c.setAttribute('aria-invalid', 'true');
                if (!c.__fbBound) {
                    c.__fbBound = true;
                    c.addEventListener('input', function () { c.classList.remove('field-invalid'); c.removeAttribute('aria-invalid'); });
                }
            }
            if (!seen.has(e)) { seen.add(e); fresh.push({ err: e, ctl: c }); }
        });
        if (!fresh.length) return;
        var first = fresh[0];
        var target = first.ctl || first.err;
        try { target.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (_) {}
        if (first.ctl && first.ctl.focus) { try { first.ctl.focus({ preventScroll: true }); } catch (_) {} }
        target.classList.add('field-pulse');
        setTimeout(function () { target.classList.remove('field-pulse'); }, 1000);
        toast(errs.length === 1 ? 'Please fix the highlighted field.' : 'Please fix the ' + errs.length + ' highlighted fields.');
    }

    function schedule() { clearTimeout(timer); timer = setTimeout(scan, 60); }

    function start() {
        new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
        schedule();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

    document.addEventListener('livewire:init', function () {
        if (!window.Livewire || !Livewire.hook) return;
        Livewire.hook('request', function (ctx) {
            ctx.fail(function (r) {
                var s = r.status;
                if (s === 419) {
                    r.preventDefault();
                    toast('Your session has expired. Reload the page to continue.', { label: 'Reload', run: function () { location.reload(); } });
                } else if (s === 401) {
                    r.preventDefault();
                    toast('You have been signed out. Sign in again to continue.', { label: 'Sign in', run: function () { location.href = '/login'; } });
                } else if (s === 403) {
                    r.preventDefault();
                    toast('You are not allowed to do that.');
                } else if (s === 429) {
                    r.preventDefault();
                    toast('Too many attempts. Wait a moment and try again.');
                } else if (s === 503 || s === 0) {
                    r.preventDefault();
                    toast('Cannot reach the server. Check your connection and try again.');
                } else if (s >= 500) {
                    r.preventDefault();
                    toast('Something went wrong on our side. Your entries are still here, please try again.');
                }
            });
        });
    });

    window.addEventListener('offline', function () { toast('You are offline. Changes will not save until the connection returns.'); });
})();
