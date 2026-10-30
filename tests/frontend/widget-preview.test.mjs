import test from 'node:test';
import assert from 'node:assert/strict';
import {widgetPreviewPresentation} from '../../resources/js/lib/widget-preview.ts';

const nativeReason = 'Svelte widget — runs custom code in the browser via app.fetchMatrix(). Use `lumo app export` for a PNG-based preview.';

test('a browser-only widget explains the limitation without displaying its technical instruction', () => {
    const result = widgetPreviewPresentation({type:'Svelte',status:'skipped',reason:nativeReason});
    assert.equal(result.typeLabel,'Painel personalizado');
    assert.equal(result.notice.kind,'browser');
    assert.match(result.notice.message,/conferência em tabela não está disponível/);
    assert.doesNotMatch(JSON.stringify(result),/Svelte|fetchMatrix|lumo app export/);
});

test('custom panels are recognized without relying on the exact English warning', () => {
    for (const widget of [{type:'svelte',status:'skipped'},{type:'Svelte'},{status:'skipped',reason:nativeReason},{semantic:{notes:[nativeReason]}}]) {
        assert.equal(widgetPreviewPresentation(widget).notice.kind,'browser');
    }
});

test('ordinary numbers, table rows and useful notes remain available in mixed dashboards', () => {
    for (const widget of [{type:'Kpi',semantic:{value:42,valueFmt:'42'}},{type:'Table',rows:[{total:42}]},{type:'Svelte',status:'ok',data:{rows:[{total:42}]},semantic:{notes:[nativeReason]}}]) {
        assert.equal(widgetPreviewPresentation(widget).notice,null);
    }
    assert.deepEqual(widgetPreviewPresentation({semantic:{notes:['Resultados dos últimos 30 dias.',123]}}).notes,['Resultados dos últimos 30 dias.']);
});

test('an actual widget error remains an error instead of being presented as a browser limitation', () => {
    const result = widgetPreviewPresentation({type:'Svelte',status:'error',error:'DriverException: secret connection at source.example.invalid',semantic:{notes:[nativeReason]}});
    assert.equal(result.notice.kind,'error');
    assert.doesNotMatch(JSON.stringify(result),/DriverException|secret connection|example.invalid/);
});

test('other skipped formats and unknown errors never expose raw technical messages', () => {
    const skipped = widgetPreviewPresentation({type:'Other',status:'skipped',reason:'Use `lumo app export` for a PNG-based preview.'});
    assert.equal(skipped.notice.kind,'browser');
    assert.doesNotMatch(JSON.stringify(skipped),/lumo app export/);
    assert.equal(widgetPreviewPresentation({status:'skipped',reason:'Unsupported internal renderer'}).notice.kind,'unavailable');
    assert.equal(widgetPreviewPresentation({status:'failed'}).notice.kind,'error');
    assert.equal(widgetPreviewPresentation({error:{stack:'internal-call'}}).notice.kind,'error');
});

test('a confirmed empty response remains distinct from an unsupported custom panel', () => {
    assert.equal(widgetPreviewPresentation({type:'Svelte',status:'ok',semantic:{rowCount:0,isEmpty:true}}).notice,null);
    assert.equal(widgetPreviewPresentation({type:'Table',status:'ok',row_count:0}).notice,null);
    assert.equal(widgetPreviewPresentation({type:'Svelte',status:'skipped',row_count:0}).notice.kind,'browser');
});
