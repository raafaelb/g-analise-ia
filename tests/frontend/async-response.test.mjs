import test from 'node:test';
import assert from 'node:assert/strict';
import {createRequestGuard} from '../../resources/js/lib/async-response.ts';

function deferred() {
    let resolve;
    const promise = new Promise(done => {resolve = done;});
    return {promise, resolve};
}

test('an older delayed response cannot overwrite the latest selection', async () => {
    const guard = createRequestGuard();
    const earlier = deferred(); const latest = deferred();
    let shown = 'initial';
    const firstIsCurrent = guard.next();
    const first = earlier.promise.then(value => {if (firstIsCurrent()) shown = value;});
    const lastIsCurrent = guard.next();
    const last = latest.promise.then(value => {if (lastIsCurrent()) shown = value;});
    latest.resolve('new project'); await last;
    earlier.resolve('old project'); await first;
    assert.equal(shown, 'new project');
});

test('leaving a project invalidates responses already in flight', async () => {
    const guard = createRequestGuard(); const pending = deferred();
    const isCurrent = guard.next(); let shown = null;
    const response = pending.promise.then(value => {if (isCurrent()) shown = value;});
    guard.invalidate(); pending.resolve('previous conversation'); await response;
    assert.equal(shown, null);
});

test('background observation preserves the current selection and expires with it', () => {
    const guard = createRequestGuard();
    const initial = guard.next(); const background = guard.current();
    assert.equal(initial(), true); assert.equal(background(), true);
    const newSelection = guard.next();
    assert.equal(initial(), false); assert.equal(background(), false); assert.equal(newSelection(), true);
});
