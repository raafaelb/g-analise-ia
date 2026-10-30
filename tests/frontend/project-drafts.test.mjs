import test from 'node:test';
import assert from 'node:assert/strict';
import {createProjectDrafts} from '../../resources/js/lib/project-drafts.ts';

test('a project draft never appears automatically in another project', () => {
    const drafts=createProjectDrafts();
    assert.equal(drafts.switchProject('tenant-a-project','tenant-b-project','Private A draft'),'');
    assert.equal(drafts.switchProject('tenant-b-project','tenant-a-project','Private B draft'),'Private A draft');
    assert.equal(drafts.switchProject('tenant-a-project','tenant-b-project','Edited A draft'),'Private B draft');
});

test('a delayed send clears only the exact text that was submitted', () => {
    const drafts=createProjectDrafts();
    drafts.switchProject('project-a','project-b','sent text');
    drafts.clearSent('project-a','sent text');
    assert.equal(drafts.switchProject('project-b','project-a','B draft'),'');
    drafts.switchProject('project-a','project-b','new A draft');
    drafts.clearSent('project-a','old sent text');
    assert.equal(drafts.switchProject('project-b','project-a','B draft'),'new A draft');
});

test('a new project receives only the explicitly supplied initial request', () => {
    const drafts=createProjectDrafts();
    drafts.seed('new-project','Explicit new dashboard request');
    assert.equal(drafts.switchProject(null,'new-project','Home composer draft'),'Explicit new dashboard request');
    assert.equal(drafts.switchProject('new-project','blank-project','New project draft'),'');
    assert.equal(drafts.switchProject('blank-project',null,''),'Home composer draft');
});
