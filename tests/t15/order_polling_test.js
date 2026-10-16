'use strict';

const polling = require('../../public/assets/js/frontend/enterprise-order-polling.js');

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

const now = Date.parse('2026-08-25T18:30:00+08:00');
const recentQueued = { status: 'queued', updated_at: '2026-08-25 18:29:30' };
const staleQueued = { status: 'queued', updated_at: '2026-08-25 18:20:00' };

assert(polling.isStalled(recentQueued, now) === false, 'recent queued order should continue background polling');
assert(polling.shouldPoll(recentQueued, now) === true, 'recent queued order should still poll');
assert(polling.isStalled(staleQueued, now) === true, 'old queued order should be identified as stalled');
assert(polling.shouldPoll(staleQueued, now) === false, 'stalled queued order must stop infinite polling');
assert(polling.shouldPoll({ status: 'submitted', updated_at: staleQueued.updated_at }, now) === true, 'submitted order still needs status polling');
assert(polling.shouldPoll({ status: 'published', updated_at: staleQueued.updated_at }, now) === false, 'terminal order must not poll');

const detail = { order: recentQueued, items: [{ id: 1, status: 'queued' }] };
const signature = polling.signature(detail);
assert(polling.hasChanged('', detail) === true, 'first detail render must be visible');
assert(polling.hasChanged(signature, detail) === false, 'unchanged background result must not redraw the detail panel');
assert(polling.hasChanged(signature, { order: { ...recentQueued, status: 'submitted' }, items: detail.items }) === true, 'status change must redraw');

console.log('PASS: enterprise order polling is silent and stops on stale queued orders');
