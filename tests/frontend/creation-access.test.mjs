import test from 'node:test';
import assert from 'node:assert/strict';
import {creationAccess} from '../../resources/js/lib/creation-access.ts';

const base={role:'editor',openaiConfigured:true,openai:{status:'connected',requires_reauthorization:false,metadata:{plan_usage:true}},biConnected:true,hasTenants:true,projectSelected:true,tenantSelected:true,tenantAuthorized:true};

test('an authorized author can send after selecting an accessible tenant',()=>{
    assert.equal(creationAccess(base).canSend,true);
});

test('a read-only profile receives a useful route instead of a create action',()=>{
    const result=creationAccess({...base,role:'viewer',openaiConfigured:false});
    assert.equal(result.canSend,false);assert.equal(result.state,'read_only');
    assert.equal(result.actions[0].target,'catalog');
});

test('a new installation without ChatGPT offers the catalog and configuration status',()=>{
    const result=creationAccess({...base,openai:undefined,openaiConfigured:false});
    assert.equal(result.canSend,false);assert.equal(result.state,'configuration');
    assert.deepEqual(result.actions.map(action=>action.target),['catalog','connections']);
});

test('an existing valid grant is not invalidated just because new sign-ins are disabled',()=>{
    assert.equal(creationAccess({...base,openaiConfigured:false}).canSend,true);
});

test('a missing or expired account asks for its own connection',()=>{
    for(const openai of [undefined,{status:'reauthentication_required'},{status:'disconnected'}]){
        const result=creationAccess({...base,openai});
        assert.equal(result.canSend,false);assert.equal(result.state,'chatgpt');
        assert.equal(result.actions[0].target,'connections');
    }
});

test('a local authorization restored in another environment cannot enable sending',()=>{
    const openai={...base.openai,requires_reauthorization:true};
    assert.equal(creationAccess({...base,openai}).state,'chatgpt');
    assert.equal(creationAccess({...base,openai,openaiConfigured:false}).state,'configuration');
});

test('identity-only and malformed grants do not imply plan authorization',()=>{
    for(const plan_usage of [false,undefined,'true',1]){
        const result=creationAccess({...base,openai:{...base.openai,metadata:{plan_usage}}});
        assert.equal(result.canSend,false);assert.equal(result.state,'plan');
    }
});

test('a missing BI account is distinguished from a missing ChatGPT account',()=>{
    const result=creationAccess({...base,biConnected:false});
    assert.equal(result.canSend,false);assert.equal(result.state,'bi');
    assert.equal(result.actions[0].target,'connections');
});

test('an empty list of accessible tenants is not a ready BI environment',()=>{
    const result=creationAccess({...base,hasTenants:false});
    assert.equal(result.canSend,false);assert.equal(result.state,'tenants');
});

test('an offline project can be opened but must select its environment before sending',()=>{
    const result=creationAccess({...base,tenantSelected:false,tenantAuthorized:false});
    assert.equal(result.canSend,false);assert.equal(result.state,'tenant');
    assert.equal(result.actions[0].target,'configure');
});

test('a project outside the refreshed BI scope asks to review access',()=>{
    const result=creationAccess({...base,tenantAuthorized:false});
    assert.equal(result.canSend,false);assert.equal(result.state,'tenant_access');
    assert.equal(result.actions[0].target,'connections');
});

test('home can start project setup before an individual tenant has been selected',()=>{
    assert.equal(creationAccess({...base,projectSelected:false,tenantSelected:false,tenantAuthorized:false}).canSend,true);
});
