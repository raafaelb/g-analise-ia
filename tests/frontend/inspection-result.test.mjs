import test from 'node:test';
import assert from 'node:assert/strict';
import {previousInspectionIsUsable} from '../../resources/js/lib/inspection-result.ts';

test('a transport interruption can retain the identified previous inspection',()=>{
 assert.equal(previousInspectionIsUsable(new TypeError('Network unavailable')),true);
 assert.equal(previousInspectionIsUsable(Object.assign(new Error('Rate limited'),{status:429})),true);
});
test('access loss, session expiry, resource disappearance and server failure discard previous inspection content',()=>{
 for(const status of [401,403,404,409,419,423,500])assert.equal(previousInspectionIsUsable(Object.assign(new Error('Unavailable'),{status})),false);
});
test('only validation of inspection input fields can keep the previous scope',()=>{
 assert.equal(previousInspectionIsUsable(Object.assign(new Error('Invalid search'),{status:422,fields:['search']})),true);
 assert.equal(previousInspectionIsUsable(Object.assign(new Error('Invalid filters'),{status:422,fields:['schema','offset']})),true);
 assert.equal(previousInspectionIsUsable(Object.assign(new Error('Connection changed'),{status:422,fields:['connection']})),false);
 assert.equal(previousInspectionIsUsable(Object.assign(new Error('Unclassified'),{status:422,fields:[]})),false);
 assert.equal(previousInspectionIsUsable('Network unavailable'),false);
});
