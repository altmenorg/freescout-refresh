/*
 * FreeScout Interop — protocol 1.0.0 (browser side).
 *
 * @bridge-version 1.0.0
 * Shared bridge: the reference copy lives in its own repository (staged in interop-bridge/ until it is created); each
 * module ships an unchanged copy of a tagged release, checked by its CI against fs-interop.lock.json.
 *
 * A small, neutral, versioned registry through which sibling FreeScout modules (Refresh, RefreshGlobal…) cooperate
 * without depending on each other's private HTML or functions. Contract: docs/INTEROPERABILITY.md, machine-readable
 * declarations: interop.json of each module.
 *
 * Properties:
 *  - idempotent bootstrap: every participating module may ship this same file; the first copy loaded creates
 *    window.FreeScoutInterop, the others keep it (loading order does not matter); a NEWER copy with the same major
 *    protocol only adds what the older one misses;
 *  - no dependency (plain ES5, no jQuery), no network call, no HTML produced: modules declare intents, the module that
 *    owns a surface renders them;
 *  - failure-safe: a listener or handler that throws is isolated (logged to the console), never breaks the caller.
 *
 * Surface of the API (all optional to use):
 *   modules     register(info) / get(id) / list() / when(id, callback)
 *   supports(moduleId, capability, range)               capability discovery with version ranges (">=1.0 <2.0")
 *   events      on(name, fn) / off(name, fn) / emit(name, payload) / once(name, fn)
 *   context     setConversationOrigin(origin) / getConversationOrigin(conversationId)
 *   surfaces    register({module, surface, context, version}) / owner(surface, context)
 *   navigation, toolbar, swipe   register(item) / list(filter) / get(id) / run(id, context) — action registries
 *   semver      satisfies(version, range) / compare(a, b)
 *
 * Licence: AGPL-3.0 (the licence of Refresh and RefreshGlobal). Never edit a module's copy: change the shared
 * repository, release a new bridge version, then update each module's copy and lock file.
 */
(function (root) {
    'use strict';

    var PROTOCOL = '1.0.0';

    /* ------------------------------------------------------------------ helpers */

    /** Logs an isolated error without breaking the caller. */
    function report(where, error) {
        if (root.console && root.console.warn) {
            root.console.warn('[FreeScoutInterop] ' + where + ':', error);
        }
    }

    /** Calls fn(arg) and isolates any exception. */
    function safeCall(fn, arg, where) {
        try {
            return fn(arg);
        } catch (e) {
            report(where, e);
            return undefined;
        }
    }

    /** Shallow copy of a plain object (registries never keep the caller's object). */
    function copy(obj) {
        var out = {};
        for (var k in obj) {
            if (Object.prototype.hasOwnProperty.call(obj, k)) {
                out[k] = obj[k];
            }
        }
        return out;
    }

    function isString(v) {
        return typeof v === 'string' && v !== '';
    }

    /* ------------------------------------------------------------------ semantic versions */

    /** "1.2" -> [1, 2, 0]; null when not a version. */
    function parseVersion(v) {
        var m = /^\s*v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/.exec(String(v));
        return m ? [parseInt(m[1], 10), parseInt(m[2] || '0', 10), parseInt(m[3] || '0', 10)] : null;
    }

    /** -1, 0 or 1 (unparsable versions compare as equal to nothing: -1). */
    function compare(a, b) {
        var x = parseVersion(a), y = parseVersion(b);
        if (!x || !y) {
            return -1;
        }
        for (var i = 0; i < 3; i++) {
            if (x[i] !== y[i]) {
                return x[i] < y[i] ? -1 : 1;
            }
        }
        return 0;
    }

    /**
     * Does a version satisfy a range? Ranges: space-separated comparators (">=1.0 <2.0", "=1.2.0", "1.2"),
     * "^1.2" (same major), "*" or "" (any). Unknown syntax: false (never assume support).
     */
    function satisfies(version, range) {
        if (!parseVersion(version)) {
            return false;
        }
        range = String(range === undefined || range === null ? '*' : range).trim();
        if (range === '' || range === '*') {
            return true;
        }
        if (range.charAt(0) === '^') {
            var base = parseVersion(range.slice(1));
            return !!base && compare(version, range.slice(1)) >= 0 && parseVersion(version)[0] === base[0];
        }
        var parts = range.split(/\s+/);
        for (var i = 0; i < parts.length; i++) {
            var m = /^(>=|<=|>|<|=)?(.+)$/.exec(parts[i]);
            if (!m || !parseVersion(m[2])) {
                return false;
            }
            var c = compare(version, m[2]);
            var op = m[1] || '=';
            var ok = (op === '>=' && c >= 0) || (op === '<=' && c <= 0) || (op === '>' && c > 0) || (op === '<' && c < 0) || (op === '=' && c === 0);
            if (!ok) {
                return false;
            }
        }
        return true;
    }

    /* ------------------------------------------------------------------ factory */

    function createFreeScoutInterop() {
        var api = { protocol: PROTOCOL };

        /* ---------- events: tiny publish / subscribe bus */
        var listeners = {};
        api.events = {
            on: function (name, fn) {
                if (isString(name) && typeof fn === 'function') {
                    (listeners[name] = listeners[name] || []).push(fn);
                }
                return api.events;
            },
            off: function (name, fn) {
                var list = listeners[name] || [];
                for (var i = list.length - 1; i >= 0; i--) {
                    if (list[i] === fn) {
                        list.splice(i, 1);
                    }
                }
                return api.events;
            },
            once: function (name, fn) {
                var wrapper = function (payload) {
                    api.events.off(name, wrapper);
                    return fn(payload);
                };
                return api.events.on(name, wrapper);
            },
            /** Payloads are plain data (schema, source_module, ids…); every listener gets its own copy. */
            emit: function (name, payload) {
                var list = (listeners[name] || []).slice();
                for (var i = 0; i < list.length; i++) {
                    safeCall(list[i], payload && typeof payload === 'object' ? copy(payload) : payload, 'listener of ' + name);
                }
                return list.length;
            }
        };

        /* ---------- modules: registration and discovery */
        var modules = {};
        var waiting = {};
        api.modules = {
            /**
             * info: {id, version, protocol, capabilities: {name: "1.0"}}. A module registers once per page; a second
             * registration of the same id replaces the first (e.g. a script loaded twice).
             */
            register: function (info) {
                if (!info || !isString(info.id)) {
                    report('modules.register', 'an id is required');
                    return null;
                }
                var entry = {
                    id: info.id,
                    version: String(info.version || ''),
                    protocol: String(info.protocol || PROTOCOL),
                    capabilities: copy(info.capabilities || {})
                };
                modules[entry.id] = entry;
                api.events.emit('module:registered', { schema: '1.0', source_module: entry.id, module: copy(entry) });
                var queue = waiting[entry.id] || [];
                delete waiting[entry.id];
                for (var i = 0; i < queue.length; i++) {
                    safeCall(queue[i], copy(entry), 'modules.when(' + entry.id + ')');
                }
                return copy(entry);
            },
            get: function (id) {
                return modules[id] ? copy(modules[id]) : null;
            },
            list: function () {
                var out = [];
                for (var id in modules) {
                    if (Object.prototype.hasOwnProperty.call(modules, id)) {
                        out.push(copy(modules[id]));
                    }
                }
                return out;
            },
            /** Calls back now if the module is registered, otherwise when it registers (order-independent). */
            when: function (id, fn) {
                if (modules[id]) {
                    safeCall(fn, copy(modules[id]), 'modules.when(' + id + ')');
                } else {
                    (waiting[id] = waiting[id] || []).push(fn);
                }
            }
        };

        /**
         * Capability discovery: true only if the module is registered, speaks a compatible protocol (same major) and
         * provides the capability in a version within the range. Never throws; false means "use your own fallback".
         */
        api.supports = function (moduleId, capability, range) {
            var m = modules[moduleId];
            if (!m || !satisfies(m.protocol, '^' + PROTOCOL.split('.')[0] + '.0')) {
                return false;
            }
            var version = m.capabilities[capability];
            return version !== undefined && satisfies(version, range === undefined ? '*' : range);
        };

        /* ---------- conversation context: where a ticket was opened from */
        var STORE = 'fsinterop.origin';
        var memory = {};
        var storage = function () {
            try {
                return root.sessionStorage || null;
            } catch (e) {
                return null;
            }
        };
        api.context = {
            /**
             * origin: {schema: "1.0", sourceModule, conversationId (number or null = the next ticket opened), mailboxId,
             * origin: {id, label, href, count}}. Kept for the browser tab (sessionStorage), then announced
             * ("context:changed"). The module that renders the ticket page decides how to show it.
             */
            setConversationOrigin: function (origin) {
                if (!origin || !origin.origin || !isString(origin.origin.href)) {
                    report('context.setConversationOrigin', 'origin.href is required');
                    return false;
                }
                var value = copy(origin);
                value.schema = value.schema || '1.0';
                value.origin = copy(origin.origin);
                value.at = Date.now();
                memory = value;
                var s = storage();
                if (s) {
                    try {
                        s.setItem(STORE, JSON.stringify(value));
                    } catch (e) {
                        // private mode: memory only
                    }
                }
                api.events.emit('context:changed', { schema: '1.0', source_module: value.sourceModule, kind: 'conversation-origin', origin: copy(value.origin) });
                return true;
            },
            /** The origin recorded for this tab (optionally only if it targets this conversation or any), or null. */
            getConversationOrigin: function (conversationId) {
                var value = memory && memory.origin ? memory : null;
                var s = storage();
                if (!value && s) {
                    try {
                        value = JSON.parse(s.getItem(STORE) || 'null');
                    } catch (e) {
                        value = null;
                    }
                }
                if (!value || !value.origin) {
                    return null;
                }
                if (conversationId && value.conversationId && String(value.conversationId) !== String(conversationId)) {
                    return null;
                }
                var out = copy(value);
                out.origin = copy(value.origin);
                return out;
            },
            clearConversationOrigin: function () {
                memory = {};
                var s = storage();
                if (s) {
                    try {
                        s.removeItem(STORE);
                    } catch (e) {
                        // nothing stored
                    }
                }
            }
        };

        /* ---------- surfaces: which module renders an area in a given context */
        var surfaces = [];
        api.surfaces = {
            /** {module, surface, context, version}: the module renders this surface in this context. */
            register: function (s) {
                if (!s || !isString(s.module) || !isString(s.surface)) {
                    report('surfaces.register', 'module and surface are required');
                    return false;
                }
                surfaces.push({ module: s.module, surface: s.surface, context: s.context || '*', version: String(s.version || '1.0') });
                api.events.emit('surface:registered', { schema: '1.0', source_module: s.module, surface: s.surface, context: s.context || '*' });
                return true;
            },
            /** Owner of a surface in a context: the last exact registration, else a "*" one, else null. */
            owner: function (surface, context) {
                var any = null;
                for (var i = surfaces.length - 1; i >= 0; i--) {
                    if (surfaces[i].surface !== surface) {
                        continue;
                    }
                    if (surfaces[i].context === context) {
                        return copy(surfaces[i]);
                    }
                    if (!any && surfaces[i].context === '*') {
                        any = copy(surfaces[i]);
                    }
                }
                return any;
            }
        };

        /* ---------- action registries: navigation entries, ticket toolbar actions, swipe actions */
        function registry(kind, required) {
            var items = {};
            var order = [];
            return {
                /**
                 * Registers (or replaces, same id) an intent. Returns false when a required field is missing.
                 * Fields are listed in docs/interop/schemas/<kind>-1.0.schema.json; "handler" is a function called
                 * with a plain context object by the module that renders the item.
                 */
                register: function (item) {
                    for (var i = 0; i < required.length; i++) {
                        if (!item || item[required[i]] === undefined || item[required[i]] === '') {
                            report(kind + '.register', required[i] + ' is required');
                            return false;
                        }
                    }
                    if (!items[item.id]) {
                        order.push(item.id);
                    }
                    items[item.id] = copy(item);
                    api.events.emit(kind + ':registered', { schema: '1.0', source_module: item.owner, id: item.id });
                    return true;
                },
                get: function (id) {
                    return items[id] ? copy(items[id]) : null;
                },
                /** Items, optionally filtered by a function, sorted by "order" (then registration order). */
                list: function (filter) {
                    var out = [];
                    for (var i = 0; i < order.length; i++) {
                        var it = items[order[i]];
                        if (it && (!filter || safeCall(filter, it, kind + '.list filter'))) {
                            out.push(copy(it));
                        }
                    }
                    out.sort(function (a, b) {
                        return (a.order || 0) - (b.order || 0);
                    });
                    return out;
                },
                /** Runs the handler of an item with a context (isolated). */
                run: function (id, context) {
                    var it = items[id];
                    return it && typeof it.handler === 'function' ? safeCall(it.handler, context || {}, kind + ' ' + id) : undefined;
                }
            };
        }
        api.navigation = registry('navigation', ['id', 'owner', 'label', 'href']);
        api.toolbar = registry('toolbar', ['id', 'owner', 'label', 'handler']);
        api.swipe = registry('swipe', ['id', 'owner', 'label', 'direction', 'handler']);

        api.semver = { satisfies: satisfies, compare: compare };

        return api;
    }

    /* ------------------------------------------------------------------ bootstrap (idempotent) */

    var existing = root.FreeScoutInterop;
    if (!existing) {
        root.FreeScoutInterop = createFreeScoutInterop();
    } else if (existing.semver && existing.semver.compare(PROTOCOL, existing.protocol) > 0
        && parseVersion(PROTOCOL)[0] === parseVersion(existing.protocol)[0]) {
        // a newer copy of the same major protocol: add only what the loaded copy misses, keep its registries; the added
        // parts announce themselves on the loaded copy's event bus (they read api.events when they emit)
        var fresh = createFreeScoutInterop();
        fresh.events = existing.events;
        for (var key in fresh) {
            if (Object.prototype.hasOwnProperty.call(fresh, key) && existing[key] === undefined) {
                existing[key] = fresh[key];
            }
        }
        existing.protocol = PROTOCOL;
    }
    root.FreeScoutInterop.events.emit('interop:ready', { schema: '1.0', protocol: root.FreeScoutInterop.protocol });
})(typeof window !== 'undefined' ? window : this);
