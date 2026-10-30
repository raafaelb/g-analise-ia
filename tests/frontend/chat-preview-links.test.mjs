import test from 'node:test';
import assert from 'node:assert/strict';
import {chatPreviewTarget,interactivePreviewTarget} from '../../resources/js/lib/chat-preview-links.ts';

const project = {id:'own',source_ref:'app:10'};
const artifact = {id:'preview-1',project_id:'own',kind:'dashboard_export',mime:'image/png',metadata:{resource:'app:10',dashboard_id:99}};
const target = (href, changes = {}, base = '') => chatPreviewTarget(href, 'https://ia.example.test'+base+'/app/project/own', base, [{...artifact,...changes}], project);

test('old preview download links identify the interactive application and tab', () => {
    assert.deepEqual(target('/api/artifacts/preview-1/download'), {artifactId:'preview-1',mode:'interactive',dashboard:'99'});
});
test('subpath installations keep links within their own application path', () => {
    assert.equal(target('/api/artifacts/preview-1/download',{},'/ia-ganalise'), null);
    assert.equal(target('/ia-ganalise/api/artifacts/preview-1/download',{},'/ia-ganalise')?.mode,'interactive');
});
test('foreign hosts and projects cannot open a private preview through the chat', () => {
    assert.equal(target('https://other.example.test/api/artifacts/preview-1/download'),null);
    assert.equal(target('/api/artifacts/preview-1/download',{project_id:'other'}),null);
    assert.equal(target('https://user:secret@ia.example.test/api/artifacts/preview-1/download'),null);
});
test('attachments and illustrative images keep their original link behavior', () => {
    assert.equal(target('/api/artifacts/preview-1/download',{kind:'attachment'}),null);
    assert.equal(target('/api/artifacts/preview-1/download',{metadata:{resource:'app:10',example:true}}),null);
    assert.equal(target('/api/artifacts/preview-1/download?download=1'),null);
});
test('a previous application opens its image instead of pretending it is the current dashboard', () => {
    assert.equal(target('/api/artifacts/preview-1/download',{metadata:{resource:'app:9'}})?.mode,'image');
});
test('unknown artifacts and malformed paths cannot select a dashboard', () => {
    assert.equal(target('/api/artifacts/unknown/download'),null);
    assert.equal(target('/api/artifacts/preview-1/download/extra'),null);
    assert.equal(target('javascript:alert(1)'),null);
});


test('legacy localhost preview links open in the current loopback session instead of navigating to another host', () => {
    assert.deepEqual(interactivePreviewTarget('http://localhost:8088/app/project/own?preview=interactive&dashboard=99','http://127.0.0.1:8088/app/project/own','',project), {path:'/app/project/own?preview=interactive&dashboard=99',dashboard:'99'});
});
test('preview interception accepts only the current project and portal prefix', () => {
    const current = 'https://ia.example.test/ia-ganalise/app/project/own';
    assert.deepEqual(interactivePreviewTarget('/ia-ganalise/app/project/own?preview=interactive',current,'/ia-ganalise',project),{path:'/ia-ganalise/app/project/own?preview=interactive',dashboard:null});
    for (const path of ['/app/project/own?preview=interactive','/ia-ganalise/app/project/foreign?preview=interactive','https://other.example.test/ia-ganalise/app/project/own?preview=interactive','/ia-ganalise/app/project/own?preview=interactive&dashboard=invalid']) assert.equal(interactivePreviewTarget(path,current,'/ia-ganalise',project),null);
});
test('loopback aliases do not authorize different ports, protocols or credentials', () => {
    const current = 'http://127.0.0.1:8088/app/project/own';
    for (const url of ['http://localhost:9000/app/project/own?preview=interactive','https://localhost:8088/app/project/own?preview=interactive','http://user:secret@localhost:8088/app/project/own?preview=interactive','javascript:alert(1)']) assert.equal(interactivePreviewTarget(url,current,'',project),null);
});
