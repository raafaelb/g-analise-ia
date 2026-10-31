import test from 'node:test';
import assert from 'node:assert/strict';
import {flowResults,loadDate,observingLoadStates} from '../../resources/js/lib/bi-loads.ts';

test('running observations exclude verified terminal states and access changes',()=>{
 assert.equal(observingLoadStates.includes('accepted'),true);
 for(const state of ['succeeded','failed','ambiguous','access_changed','needs_attention'])assert.equal(observingLoadStates.includes(state),false);
});
test('results preserve source names and match repeated contexts separately',()=>{
 const bindings=[{flow_id:'10',name:'Receitas',context:'Incremental'},{flow_id:'10',name:'Receitas',context:'KeySweep'},{flow_id:'11',name:'Despesas',context:'Total'}];
 const proof={flows:[{flow_id:'10',context:'KeySweep',name:'wrong name',status:'failed',message:'Sweep failed'},{flow_id:'10',context:'Incremental',status:'succeeded',rows_out:0}]};
 const result=flowResults(bindings,proof);
 assert.equal(result[0].status,'succeeded');assert.equal(result[0].rows_out,0);assert.equal(result[0].name,'Receitas');
 assert.equal(result[1].status,'failed');assert.equal(result[1].message,'Sweep failed');
 assert.equal(result[2].status,'pending');
 assert.equal(proof.flows.length,2);
});
test('history without contexts consumes each outcome once and does not invent missing sources',()=>{
 const result=flowResults([{flow_id:'10',name:'Receitas',context:'Incremental'},{flow_id:'10',name:'Receitas',context:'KeySweep'}],{flows:[{flow_id:'10',context:null,status:'succeeded',rows_out:null}]});
 assert.equal(result[0].context,null);assert.equal(result[0].rows_out,null);
 assert.equal(result[1].status,'pending');
});

test('execution timestamps are shown in Brasilia regardless of the native UTC offset',()=>{
 assert.match(loadDate('2026-10-31T06:46:06Z'),/31\/10\/2026, 03:46:06/);
 assert.equal(loadDate('2026-10-31T06:46:06Z'),loadDate('2026-10-31T03:46:06-03:00'));
 assert.equal(loadDate('invalid'),'Horário não informado');
});
