(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.GeoOrderPolling = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var STALLED_AFTER_MS = 2 * 60 * 1000;

    function timestamp(value) {
        if (typeof value !== 'string' || value.trim() === '') {
            return NaN;
        }
        var normalized = value.trim().replace(' ', 'T');
        if (!/[zZ]|[+-]\d\d:\d\d$/.test(normalized)) {
            normalized += '+08:00';
        }
        return Date.parse(normalized);
    }

    function isStalled(order, now) {
        if (!order || order.status !== 'queued') {
            return false;
        }
        var updatedAt = timestamp(order.updated_at || order.created_at || '');
        if (!Number.isFinite(updatedAt)) {
            return false;
        }
        return Math.max(0, now - updatedAt) >= STALLED_AFTER_MS;
    }

    function shouldPoll(order, now) {
        if (!order) {
            return false;
        }
        if (order.status === 'queued') {
            return !isStalled(order, now);
        }
        return order.status === 'submitted' || order.status === 'processing';
    }

    function signature(detail) {
        return JSON.stringify(detail || null);
    }

    function hasChanged(previousSignature, detail) {
        return previousSignature !== signature(detail);
    }

    return {
        STALLED_AFTER_MS: STALLED_AFTER_MS,
        isStalled: isStalled,
        shouldPoll: shouldPoll,
        signature: signature,
        hasChanged: hasChanged
    };
}));
