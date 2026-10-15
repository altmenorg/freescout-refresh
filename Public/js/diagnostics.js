/* Refresh: diagnostics file for bug reports.
   - On every page: keeps the last JavaScript errors and failed ajax calls of this browser (localStorage, 40 at most),
     without their query string, so that a bug seen on a ticket page shows up in the file made later.
   - Manage > Settings > Refresh (#rf-diag): "Create the diagnostics file" joins the server part
     (/refresh/diagnostics, Services\Diagnostics) and what the browser sees, shows it, then offers to download or copy.
   Nothing that identifies the installation or its customers: no ticket, name, e-mail address, domain or tag name.
   ES5. */
(function () {
    if (!window.jQuery) {
        return;
    }
    var $ = window.jQuery;
    var rfT = function (s) { return window.rfT ? window.rfT(s) : s; };
    var KEY = 'rf-diag-events';
    var MAX = 40;

    function redact(s) {
        return String(s == null ? '' : s)
            .replace(/\b[a-z][a-z0-9+.-]*:\/\/[^\s'"]+/gi, function (u) { return '<url:' + path(u) + '>'; })
            .replace(/[^\s@<>()'"]+@[^\s@<>()'"]+\.[a-z]{2,}/gi, '<email>')
            .replace(/\b\d{1,3}(\.\d{1,3}){3}\b/g, '<ip>')
            .slice(0, 300);
    }
    // path only, numbers replaced: /conversation/12741?folder_id=7 -> /conversation/:n
    function path(url) {
        var p = String(url || '');
        try { p = new URL(p, location.href).pathname; } catch (e) { p = p.split('?')[0]; }
        return p.replace(/\/\d+(?=\/|$)/g, '/:n');
    }
    function read() {
        try { return JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { return []; }
    }
    function record(ev) {
        try {
            var list = read();
            ev.t = new Date().toISOString();
            ev.page = path(location.href);
            ev.mobile_layout = window.matchMedia ? window.matchMedia('(max-width: 767px)').matches : null;
            list.push(ev);
            localStorage.setItem(KEY, JSON.stringify(list.slice(-MAX)));
        } catch (e) { /* storage blocked: nothing kept */ }
    }

    window.addEventListener('error', function (e) {
        if (!e || !e.message) {
            return; // a resource that failed to load, not a script error
        }
        record({ type: 'error', message: redact(e.message), source: path(e.filename) + ':' + (e.lineno || 0) + ':' + (e.colno || 0) });
    });
    window.addEventListener('unhandledrejection', function (e) {
        var r = e && e.reason;
        record({ type: 'rejection', message: redact(r && r.message ? r.message : r) });
    });
    $(document).ajaxError(function (e, xhr, settings, thrown) {
        if (xhr && xhr.statusText === 'abort') {
            return;
        }
        var ct = xhr && xhr.getResponseHeader ? (xhr.getResponseHeader('Content-Type') || '') : '';
        record({
            type: 'ajax',
            method: (settings && settings.type) || 'GET',
            url: path(settings && settings.url),
            status: xhr ? xhr.status : 0,
            error: redact(thrown || (xhr && xhr.statusText) || ''),
            content_type: ct.split(';')[0]
        });
    });

    function browser() {
        var mm = function (q) { return window.matchMedia ? window.matchMedia(q).matches : null; };
        var tz = null;
        try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) {}
        return {
            user_agent: navigator.userAgent,
            language: navigator.language,
            timezone: tz,
            viewport: window.innerWidth + 'x' + window.innerHeight,
            device_pixel_ratio: window.devicePixelRatio || 1,
            touch: ('ontouchstart' in window) || (navigator.maxTouchPoints || 0) > 0,
            mobile_layout: mm('(max-width: 767px)'),
            dark_mode: mm('(prefers-color-scheme: dark)'),
            local_storage: (function () { try { localStorage.setItem('rf-diag-t', '1'); localStorage.removeItem('rf-diag-t'); return true; } catch (e) { return false; } })(),
            jquery: $.fn && $.fn.jquery,
            select2: !!($.fn && $.fn.select2),
            laroute: !!window.laroute
        };
    }

    // The tag search exactly as the Tags module and Refresh send it (read-only): status and shape, no tag name
    function tagsTest(done) {
        var url = null;
        try { url = window.laroute && window.laroute.route('tags.ajax'); } catch (e) {}
        if (!url) {
            done({ route: false });
            return;
        }
        var t = Date.now();
        $.ajax({ url: url, type: 'POST', dataType: 'json', global: false, timeout: 15000,
            data: { _token: $('meta[name="csrf-token"]').attr('content'), q: 'a', action: 'autocomplete', page: 1 } })
            .done(function (r) {
                done({
                    route: true, status: 200, ms: Date.now() - t,
                    results: r && r.results ? r.results.length : null,
                    more: r && r.pagination ? !!r.pagination.more : null
                });
            })
            .fail(function (xhr, status, thrown) {
                done({
                    route: true, ms: Date.now() - t, status: xhr.status, error: redact(thrown || status),
                    content_type: (xhr.getResponseHeader('Content-Type') || '').split(';')[0],
                    // first characters of a non-JSON answer (a PHP error, a login page): redacted
                    body_start: redact((xhr.responseText || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').slice(0, 200))
                });
            });
    }

    $(function () {
        var box = $('#rf-diag');
        if (!box.length) {
            return;
        }
        var out = box.find('.rf-diag-out');
        var pre = box.find('.rf-diag-pre');
        var text = '';

        box.on('click', '.rf-diag-make', function () {
            // the previous file stays shown until the new one replaces it: hiding it would shorten the page and scroll it up
            var btn = $(this).prop('disabled', true);
            var report = null;
            var browserPart = null;
            var finish = function () {
                if (!report || !browserPart) {
                    return;
                }
                report.browser = browserPart.info;
                report.browser_tests = { tags_autocomplete: browserPart.tags };
                report.browser_events = read();
                text = JSON.stringify(report, null, 2);
                pre.text(text);
                out.show();
                btn.prop('disabled', false);
            };
            $.ajax({ url: box.data('url'), dataType: 'json', global: false, timeout: 60000 })
                .done(function (r) { report = r || {}; })
                .fail(function (xhr, status, thrown) {
                    report = { server_part: { status: xhr.status, error: redact(thrown || status) } };
                })
                .always(finish);
            tagsTest(function (tags) {
                browserPart = { info: browser(), tags: tags };
                finish();
            });
        });

        box.on('click', '.rf-diag-download', function () {
            var d = new Date();
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            var name = 'refresh-diagnostics-' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '-' + pad(d.getHours()) + pad(d.getMinutes()) + '.json';
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([text], { type: 'application/json' }));
            a.download = name;
            document.body.appendChild(a);
            a.click();
            setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
        });

        box.on('click', '.rf-diag-copy', function () {
            var btn = $(this);
            var ok = function () { btn.text(rfT('Copied')); setTimeout(function () { btn.text(rfT('Copy')); }, 2000); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(ok);
            } else {
                var ta = $('<textarea></textarea>').val(text).appendTo('body').select();
                document.execCommand('copy');
                ta.remove();
                ok();
            }
        });

        box.on('click', '.rf-diag-clear', function () {
            try { localStorage.removeItem(KEY); } catch (e) {}
            $(this).text(rfT('Cleared'));
        });
    });
})();
