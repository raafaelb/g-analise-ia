import test from 'node:test';
import assert from 'node:assert/strict';
import {tablePresentationDraft,tablePresentationPatch,tablePresentationChangeCount,tablePresentationProblems} from '../../resources/js/lib/table-presentation.ts';

const source = () => ({resource:'table:10',name:'Vendas',description:'Dados de teste',version:'a'.repeat(64),mask_options:[],columns:[
    {key:' VALOR (Number)',name:'VALOR',type:'Number',label:'Valor',description:null,mask:'CUSTOM',mask_editable:true,has_custom_mask:true},
    {key:'DATA (Date)',name:'DATA',type:'Date',label:'Data',description:'Data do registro',mask:'DATE',mask_editable:false,has_custom_mask:true},
]});

test('opening the form or changing only surrounding whitespace prepares no changes', () => {
    const original=source(),draft=tablePresentationDraft(original);
    draft.name=' Vendas ';draft.description='Dados de teste\r\n';draft.columns[0].label='Valor ';
    const patch=tablePresentationPatch(original,draft);
    assert.deepEqual(patch,{version:original.version});
    assert.equal(tablePresentationChangeCount(patch),0);
    assert.equal(original.name,'Vendas');
});

test('editing a label preserves opaque column keys and unselected custom formats', () => {
    const original=source(),draft=tablePresentationDraft(original);
    draft.columns[0].label='Saldo final';
    const patch=tablePresentationPatch(original,draft);
    assert.deepEqual(patch.columns,[{key:' VALOR (Number)',label:'Saldo final'}]);
    assert.equal(tablePresentationChangeCount(patch),1);
    assert.equal(original.columns[0].label,'Valor');
    assert.equal(original.columns[0].mask,'CUSTOM');
});

test('clearing descriptions and choosing the default format are explicit nullable patches', () => {
    const original=source(),draft=tablePresentationDraft(original);
    draft.description=' ';draft.columns[0].mask=null;draft.columns[1].description='';
    const patch=tablePresentationPatch(original,draft);
    assert.deepEqual(patch,{version:original.version,description:null,columns:[{key:' VALOR (Number)',mask:null},{key:'DATA (Date)',description:null}]});
    assert.equal(tablePresentationChangeCount(patch),3);
});

test('read-only formats, added columns and removed columns cannot create structural patches', () => {
    const original=source(),draft=tablePresentationDraft(original);
    draft.columns[1].mask=null;draft.columns.splice(0,1);
    draft.columns.push({...original.columns[0],key:'ADDED (Number)',label:'Outro'});
    assert.deepEqual(tablePresentationPatch(original,draft),{version:original.version});
});

test('changes remain correct when columns are reordered or edited outside the displayed page', () => {
    const original=source(),draft=tablePresentationDraft(original);
    draft.columns.reverse();draft.columns[0].label='Competência';draft.columns[1].mask='N2';draft.name='Vendas mensais';
    const patch=tablePresentationPatch(original,draft);
    assert.deepEqual(patch.columns,[{key:' VALOR (Number)',mask:'N2'},{key:'DATA (Date)',label:'Competência'}]);
    assert.equal(tablePresentationChangeCount(patch),3);
});

test('the form explains empty labels, oversized descriptions and large batches before review', () => {
    const original=source(),draft=tablePresentationDraft(original);
    draft.name=' ';draft.columns[0].label=' ';draft.description='x'.repeat(4001);
    assert.equal(tablePresentationProblems(draft,tablePresentationPatch(original,draft)).length,3);
    assert.ok(tablePresentationProblems(tablePresentationDraft(original),{version:original.version,columns:Array.from({length:501},(_,i)=>({key:String(i),label:'Nome'}))}).some(message=>message.includes('500')));
});
