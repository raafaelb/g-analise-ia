import test from 'node:test';
import assert from 'node:assert/strict';
import {describeExecutionMessage} from '../../resources/js/lib/execution-message.ts';

test('missing or non-text execution messages are omitted without inventing diagnostic content',()=>{
 for(const value of [null,undefined,false,0,{},[], '  \n '])assert.equal(describeExecutionMessage(value,'failed'),null);
});

test('a brief actionable message remains readable without an unnecessary technical disclosure',()=>{
 assert.deepEqual(describeExecutionMessage('Agente indisponível. Confira sua conexão.','failed'),{summary:'Agente indisponível. Confira sua conexão.',details:null});
 assert.deepEqual(describeExecutionMessage('Processamento concluído sem erros.','succeeded'),{summary:'Processamento concluído sem erros.',details:null});
});

test('a short database diagnostic is summarized while preserving its actual text for investigation',()=>{
 const message='SQLSTATE[08006]: conexão encerrada pela fonte';
 const result=describeExecutionMessage(message,'failed');
 assert.equal(result.summary,'O BI informou uma falha nesta execução. Abra os detalhes técnicos para conferir o motivo.');
 assert.equal(result.details,message);
});

test('a long message remains complete inside the technical details instead of filling the primary result',()=>{
 const message='Mensagem detalhada da execução. '.repeat(20);
 const result=describeExecutionMessage(message,'ERROR');
 assert.equal(result.details,message.trim());
 assert.ok(result.summary.length<150);
 assert.match(result.summary,/falha nesta execução/);
});

test('multiple diagnostic lines move to details even without a known exception class',()=>{
 const message='Leitura interrompida\nEtapa de origem\nEtapa de transformação\nEtapa de destino';
 assert.equal(describeExecutionMessage(message,'CRASHED').details,message);
});

test('technical information never reclassifies a successful or unconfirmed execution as failed',()=>{
 const message='java.lang.IllegalStateException: diagnóstico registrado';
 for(const state of ['succeeded','running','unknown',null]){
  const result=describeExecutionMessage(message,state);
  assert.equal(result.summary,'O BI retornou uma mensagem técnica para esta execução. Abra os detalhes para consultar o conteúdo.');
  assert.equal(result.details,message);
  assert.doesNotMatch(result.summary,/falha/i);
 }
});
