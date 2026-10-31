import test from 'node:test';
import assert from 'node:assert/strict';
import {newScheduleTrigger,scheduleChangeCount,scheduleContextLabel,scheduleDraft,schedulePatch,sourceContextMode,sourceContextModes} from '../../resources/js/lib/schedule-editor.ts';
const source=()=>({resource:'schedule:20',version:'a'.repeat(64),default_anchor:'2026-10-30T00:00:00-03:00',configuration:{name:'Receitas',active:true,agent:'5',parallel_lane:false,notifications:{on_error:true,on_success:false,emails:['Alerts@example.test']},triggers:[{key:'trigger-day',type:'Day',time:'03:00',period:1,label:'Diariamente às 03:00'},{key:'trigger-cdc',type:'CDC',period:10,anchor:'2026-10-30T00:00:00-03:00',window_start:null,window_end:null,probe_configured:true,label:'Quando os dados mudarem'}],flows:[{key:'flow-a',flow_id:'10',context:'Total'},{key:'flow-b',flow_id:'11',context:'Incremental'}]}});

test('opening an existing schedule and surrounding whitespace prepare no change or hidden default reset',()=>{
 const response=source(),draft=scheduleDraft(response);draft.name=' Receitas ';draft.emails='Alerts@example.test\n';
 assert.deepEqual(schedulePatch(response,draft),{resource:'schedule:20',version:'a'.repeat(64)});
 assert.equal(scheduleChangeCount(schedulePatch(response,draft)),0);
 assert.equal(response.configuration.name,'Receitas');assert.equal(response.configuration.triggers[1].probe,undefined);
});
test('renaming leaves timing, source order, alerts and native probe configuration untouched',()=>{
 const response=source(),draft=scheduleDraft(response);draft.name='Receitas por mês';
 assert.deepEqual(schedulePatch(response,draft),{resource:'schedule:20',version:'a'.repeat(64),name:'Receitas por mês'});
});
test('a changed clock sends only its row key and changed field while preserving the second trigger',()=>{
 const response=source(),draft=scheduleDraft(response);draft.triggers[0].time='04:30';
 assert.deepEqual(schedulePatch(response,draft).triggers,[{key:'trigger-day',time:'04:30'},{key:'trigger-cdc'}]);
});
test('source reordering retains original row keys and does not reconstruct native bindings',()=>{
 const response=source(),draft=scheduleDraft(response);draft.flows.reverse();
 assert.deepEqual(schedulePatch(response,draft).flows,[{key:'flow-b'},{key:'flow-a'}]);
 assert.equal(response.configuration.flows[0].flow_id,'10');
});
test('changing a source window patches its keyed binding without swapping its identity',()=>{
 const response=source(),draft=scheduleDraft(response);draft.flows[1].context='Total';
 assert.deepEqual(schedulePatch(response,draft).flows,[{key:'flow-a'},{key:'flow-b',context:'Total'}]);
});
test('adjusting a CDC interval preserves its private original query and replacement is explicit',()=>{
 const response=source(),draft=scheduleDraft(response);draft.triggers[1].period='30';
 assert.deepEqual(schedulePatch(response,draft).triggers,[{key:'trigger-day'},{key:'trigger-cdc',period:30}]);
 draft.triggers[1]._replaceProbe=true;draft.triggers[1].probe={credential_id:'8',sql:' SELECT MAX(updated_at) FROM example ',timeout_seconds:'4'};
 assert.deepEqual(schedulePatch(response,draft).triggers[1],{key:'trigger-cdc',period:30,probe:{credential_id:'8',sql:'SELECT MAX(updated_at) FROM example',timeout_seconds:4}});
});
test('a new schedule starts inactive with a daily madrugada time and explicit notification defaults',()=>{
 const response={agents:[{id:'5',name:'Agente'}],default_anchor:'2026-10-30T00:00:00-03:00',configuration:null},draft=scheduleDraft(response);
 draft.name=' Nova agenda ';draft.flows.push({flow_id:'10',context:'Total'});
 assert.deepEqual(schedulePatch(response,draft),{name:'Nova agenda',active:false,agent:'5',parallel_lane:false,triggers:[{type:'Day',time:'03:00'}],flows:[{flow_id:'10',context:'Total'}],notifications:{on_error:false,on_success:false,emails:[]}});
 assert.equal(newScheduleTrigger(response.default_anchor,'CDC').probe.timeout_seconds,5);
});
test('unknown native triggers remain opaque and clearing recipients does not reset other flags',()=>{
 const response=source();response.configuration.triggers=[{key:'future-key',type:'FutureNative',period:123,label:'Manter origem'}];
 const draft=scheduleDraft(response);draft.emails='';
 assert.deepEqual(schedulePatch(response,draft),{resource:'schedule:20',version:'a'.repeat(64),notifications:{emails:[]}});
 draft.triggers.push(newScheduleTrigger(response.default_anchor));
 assert.deepEqual(schedulePatch(response,draft).triggers,[{key:'future-key'},{type:'Day',time:'03:00'}]);
});
test('load descriptions and choices distinguish incremental data, temporal windows and deletion sync',()=>{
 assert.equal(scheduleContextLabel('Temporal:Days:30'),'Atualizar os últimos 30 dias');
 assert.equal(scheduleContextLabel('Temporal:Year:Current'),'Atualizar o ano atual');
 assert.equal(sourceContextMode('Temporal:Month:10-2026'),'Month');
 assert.equal(sourceContextModes({load_type:'Total'}).some(mode=>mode.value==='KeySweep'),false);
 assert.equal(sourceContextModes({load_type:'Incremental',has_key_sweep:true}).some(mode=>mode.value==='KeySweep'),true);
});

test('resource identifiers beyond the JavaScript safe integer range retain every digit',()=>{
 const response={agents:[],default_anchor:'2026-10-30T00:00:00-03:00',configuration:null},draft=scheduleDraft(response);draft.name='Carga';draft.agent='9007199254740993';draft.flows.push({flow_id:'9007199254740995',context:'Total'});
 assert.equal(schedulePatch(response,draft).agent,'9007199254740993');assert.equal(schedulePatch(response,draft).flows[0].flow_id,'9007199254740995');
});
