import test from 'node:test';
import assert from 'node:assert/strict';
import {projectPreviews, choosePreview, dashboardPreviews} from '../../resources/js/lib/project-previews.ts';

const artifact = (id, changes = {}) => ({id, project_id:'own-project', kind:'dashboard_export', mime:'image/png', created_at:'2026-10-30T05:00:00Z', metadata:{resource:'app:10'}, ...changes});

test('only real dashboard previews from the selected project are eligible', () => {
    const result = projectPreviews([
        artifact('own'), artifact('foreign', {project_id:'another-project'}),
        artifact('attachment', {kind:'attachment'}), artifact('illustrative', {metadata:{resource:'app:10',example:true}}),
        artifact('pdf', {mime:'application/pdf'}), artifact('unknown-origin', {metadata:{resource:'flow:10'}}),
        null, 'invalid', {},
    ], 'own-project');
    assert.deepEqual(result.map(value => value.id), ['own']);
});

test('newest preview wins across timezone representations and stable ties', () => {
    const first = artifact('01', {created_at:'2026-10-30T05:00:00Z'});
    const second = artifact('02', {created_at:'2026-10-30T02:00:00-03:00'});
    const newest = artifact('03', {created_at:'2026-10-30T05:01:00Z'});
    const input = [first, newest, second];
    assert.deepEqual(projectPreviews(input, 'own-project').map(value => value.id), ['03','02','01']);
    assert.deepEqual(input.map(value => value.id), ['01','03','02']);
});

test('following new previews updates the image while a deliberate old selection remains stable', () => {
    const earlier = artifact('01');
    const latest = artifact('02', {created_at:'2026-10-30T05:01:00Z'});
    const previews = projectPreviews([earlier, latest], 'own-project');
    assert.equal(choosePreview(previews, '01', true)?.id, '02');
    assert.equal(choosePreview(previews, '01', false)?.id, '01');
});

test('removed selection falls back safely and an empty project cannot keep an old image', () => {
    const previews = projectPreviews([artifact('01')], 'own-project');
    assert.equal(choosePreview(previews, 'removed', false)?.id, '01');
    assert.equal(choosePreview(projectPreviews([artifact('01')], 'another-project'), '01', false), null);
});

test('a project working on a new application cannot display its old application as the current preview', () => {
    const previews = projectPreviews([artifact('current'),artifact('older-app',{metadata:{resource:'app:9'}})],'own-project','app:10');
    assert.deepEqual(previews.map(value => value.id),['current']);
});

test('dashboard selection excludes other tabs and legacy default exports belong only to the first tab', () => {
    const previews = projectPreviews([artifact('first',{metadata:{resource:'app:10',dashboard_id:1}}),artifact('second',{metadata:{resource:'app:10',dashboard_id:2}}),artifact('legacy')],'own-project');
    assert.deepEqual(dashboardPreviews(previews,'1','1').map(value=>value.id).sort(),['first','legacy']);
    assert.deepEqual(dashboardPreviews(previews,'2','1').map(value=>value.id),['second']);
});
