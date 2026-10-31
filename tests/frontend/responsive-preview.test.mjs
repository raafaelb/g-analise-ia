import test from 'node:test';
import assert from 'node:assert/strict';
import {canStartPreviewSession,fitPreview} from '../../resources/js/lib/responsive-preview.ts';

test('automatic preview occupies all available width and height', () => {
    assert.deepEqual(fitPreview('auto',683.5,712), {ready:true,viewportWidth:683.5,viewportHeight:712,displayWidth:683.5,displayHeight:712,scale:1});
});
test('phone keeps a real mobile viewport while fitting the available height', () => {
    const preview=fitPreview('phone',650,680);
    assert.equal(preview.viewportWidth,390);
    assert.equal(preview.viewportHeight,844);
    assert.equal(Math.round(preview.displayWidth),299);
    assert.equal(preview.displayHeight,648);
});
test('tablet keeps its portrait viewport and fits without horizontal clipping', () => {
    const preview=fitPreview('tablet',600,680);
    assert.equal(preview.viewportWidth,768);
    assert.equal(preview.viewportHeight,1024);
    assert.equal(preview.displayWidth,486);
    assert.equal(preview.displayHeight,648);
});
test('desktop scales to the available width without changing the responsive viewport', () => {
    const preview=fitPreview('desktop',600,680);
    assert.equal(preview.viewportWidth,1280);
    assert.equal(preview.viewportHeight,800);
    assert.equal(preview.displayWidth,568);
    assert.equal(preview.displayHeight,355);
});
test('a large panel does not enlarge device pixels beyond their native preview size', () => {
    const preview=fitPreview('phone',1800,1200);
    assert.equal(preview.scale,1);
    assert.equal(preview.displayWidth,390);
    assert.equal(preview.displayHeight,844);
});
test('an unmeasured or hidden panel waits for a valid size instead of rendering invalid geometry', () => {
    for(const [width,height] of [[0,0],[-1,600],[600,0],[NaN,600],[600,Infinity]]) {
        const preview=fitPreview('phone',width,height);
        assert.equal(preview.ready,false);
        assert.equal(preview.scale,0);
        assert.equal(preview.displayWidth,0);
        assert.equal(preview.displayHeight,0);
        assert.equal(preview.viewportWidth,390);
        assert.equal(preview.viewportHeight,844);
    }
});

const sessionContext=(changes={})=>({supported:true,active:true,responsive:true,measured:true,...changes});

test('responsive sessions wait for both visible content and a measured viewport', () => {
    assert.equal(canStartPreviewSession(sessionContext({active:false})),false);
    assert.equal(canStartPreviewSession(sessionContext({measured:false})),false);
    assert.equal(canStartPreviewSession(sessionContext()),true);
});

test('the ordinary catalog viewer remains available without responsive measurement', () => {
    assert.equal(canStartPreviewSession(sessionContext({responsive:false,measured:false})),true);
});

test('unsupported browsers cannot start authenticated sessions in either viewer', () => {
    for(const responsive of [true,false])assert.equal(canStartPreviewSession(sessionContext({supported:false,responsive})),false);
});


test('device chrome reserves its margin in the geometry without resizing the measured stage', () => {
    const phone=fitPreview('phone',833,714);
    const automatic=fitPreview('auto',833,714);

    assert.equal(phone.viewportWidth,390);
    assert.equal(phone.viewportHeight,844);
    assert.equal(phone.displayHeight,682);
    assert.equal(automatic.viewportWidth,833);
    assert.equal(automatic.viewportHeight,714);
    assert.equal(automatic.scale,1);
});

test('a stage smaller than the device margin waits instead of producing negative sizes', () => {
    for(const [width,height] of [[32,714],[833,32],[24,24]]) {
        const preview=fitPreview('tablet',width,height);

        assert.equal(preview.ready,false);
        assert.equal(preview.viewportWidth,768);
        assert.equal(preview.viewportHeight,1024);
        assert.equal(preview.scale,0);
    }
});
