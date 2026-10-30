import test from 'node:test';
import assert from 'node:assert/strict';
import {projectPreviews, choosePreview, dashboardPreviews, shouldFollowLivePreview} from '../../resources/js/lib/project-previews.ts';

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

const liveContext = (changes={}) => ({projectId:'project-one',resource:'app:10',revision:'confirmed-one',...changes});

test('a confirmed app checkpoint refreshes without an exported image and repeated polling is stable', () => {
    const first=liveContext();
    const next=liveContext({revision:'confirmed-two'});
    assert.equal(shouldFollowLivePreview(first,next,true,'interactive'),true);
    assert.equal(shouldFollowLivePreview(next,{...next},true,'interactive'),false);
    assert.equal(shouldFollowLivePreview(liveContext({revision:null}),next,true,'interactive'),true);
});

test('another project or app cannot refresh the current authenticated preview', () => {
    for(const change of [{projectId:'another-project'},{resource:'app:20'},{resource:'flow:10'}]) {
        assert.equal(shouldFollowLivePreview(liveContext(),liveContext({...change,revision:'confirmed-two'}),true,'interactive'),false);
    }
});

test('paused following and a selected historical image stay stable after a new app checkpoint', () => {
    const next=liveContext({revision:'confirmed-two'});
    assert.equal(shouldFollowLivePreview(liveContext(),next,false,'interactive'),false);
    assert.equal(shouldFollowLivePreview(liveContext(),next,true,'image'),false);
});

test('a missing confirmation or non-app context cannot trigger a live session', () => {
    for(const revision of [null,''])assert.equal(shouldFollowLivePreview(liveContext(),liveContext({revision}),true,'interactive'),false);
    for(const resource of [null,'app:0','table:10'])assert.equal(shouldFollowLivePreview(liveContext({resource}),liveContext({resource,revision:'confirmed-two'}),true,'interactive'),false);
});

test('hidden previews wait until they are visible before consuming a new login session', () => {
    const before=liveContext();
    const next=liveContext({revision:'confirmed-two'});
    assert.equal(shouldFollowLivePreview(before,next,true,'interactive',false),false);
    assert.equal(shouldFollowLivePreview(before,next,true,'interactive',true),true);
    assert.equal(shouldFollowLivePreview(next,{...next},true,'interactive',true),false);
});
