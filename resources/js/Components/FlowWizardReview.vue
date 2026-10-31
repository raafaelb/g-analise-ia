<script setup lang="ts">
import {computed,ref} from 'vue';
import {ArrowRight,Database,FolderOpen,Info} from 'lucide-vue-next';
type Item=Record<string,any>;
const props=defineProps<{value:Item}>();
const expanded=ref(false);
const source=computed(()=>props.value.source??{});
const columns=computed<Item[]>(()=>source.value.columns??[]);
const visible=computed(()=>expanded.value?columns.value:columns.value.slice(0,12));
const stage=computed(()=>({create_source_flow:'Criar a configuração do ETL',configure_source_flow:'Concluir a configuração do ETL existente',test_source_flow:'Testar a extração dos dados',create_source_table:'Criar a tabela privada',load_source_table:'Carregar a tabela privada'} as Record<string,string>)[props.value.step]??'Conferir etapa do ETL');
</script>
<template>
 <section class="flow-wizard-review" aria-label="Revisão do assistente de ETL">
  <h3>{{stage}}</h3><div class="flow-review-path"><div><Database :size="17"/><strong>{{source.name}}</strong><small>{{source.schema}} · {{source.table}}</small></div><ArrowRight :size="17"/><div><FolderOpen :size="17"/><strong>{{value.repair?.resource||value.table||'Nova tabela própria'}}</strong><small>{{value.repair?'Mesmo ETL · sem nova criação':'Minha Mesa · neste ambiente'}}</small></div></div>
  <dl><div><dt>Agente</dt><dd>{{source.agent?.name}}</dd></div><div><dt>Campos escolhidos</dt><dd>{{columns.length}} de {{source.visible_column_count}} campos visíveis</dd></div><div><dt>Registros por extração</dt><dd>{{source.row_limit?'Até '+source.row_limit+' · carga de teste':'Todos os registros dos campos escolhidos'}}</dd></div><div v-if="value.captured_fields"><dt>Esquema capturado</dt><dd>{{value.captured_fields}} campos</dd></div></dl>
  <p v-if="source.row_limit" class="flow-review-attention"><Info :size="16"/><span>O limite de teste também limita a futura tabela. Essa amostra precisa de revisão antes de usar seus números como indicadores completos.</span></p>
  <p v-else-if="['test_source_flow','load_source_table'].includes(value.step)" class="flow-review-attention"><Info :size="16"/><span>O agente consultará todos os registros dos campos escolhidos. Confira se esse volume é adequado antes de autorizar.</span></p>
  <details><summary>Conferir campos escolhidos</summary><ul><li v-for="column in visible" :key="column.name"><strong>{{column.name}}</strong><small>{{column.type}}</small></li></ul><button v-if="!expanded&&columns.length>visible.length" type="button" class="text-button" @click="expanded=true">Mostrar os {{columns.length}} campos</button></details>
  <details v-if="value.sql"><summary>Consulta gerada automaticamente</summary><pre>{{value.sql}}</pre></details>
  <p class="flow-review-effect">{{value.impact}}</p>
 </section>
</template>
<style scoped>
.flow-wizard-review{margin-top:16px;color:#3e5d85}.flow-wizard-review h3{font-size:14px;margin:0 0 14px}.flow-review-path{display:grid;grid-template-columns:minmax(0,1fr) 20px minmax(0,1fr);gap:14px;align-items:center;background:#f3f7fe;border-radius:8px;padding:14px}.flow-review-path>div{min-width:0}.flow-review-path strong{display:block;font-size:12px;margin:6px 0;overflow-wrap:anywhere}.flow-review-path small{display:block;font-size:11px;line-height:1.7;color:#7186a2;overflow-wrap:anywhere}.flow-wizard-review dl{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin:16px 0}.flow-wizard-review dt{font-size:11px;color:#7889a0;margin-bottom:5px}.flow-wizard-review dd{font-size:12px;margin:0;line-height:1.7;overflow-wrap:anywhere}.flow-review-attention{display:flex;gap:9px;font-size:12px;line-height:1.8;padding:12px;background:#fff8eb;border:1px solid #eddfc5;border-radius:7px;color:#886329}.flow-review-attention svg{flex-shrink:0;margin-top:3px}.flow-wizard-review details{margin:14px 0}.flow-wizard-review summary{font-size:12px;cursor:pointer}.flow-wizard-review ul{list-style:none;margin:10px 0;padding:0}.flow-wizard-review li{display:flex;justify-content:space-between;gap:12px;border-top:1px solid #e5edf7;padding:8px 0;font-size:11px;overflow-wrap:anywhere}.flow-wizard-review li small{color:#7186a2}.flow-wizard-review pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f5f7fb;border:1px solid #dfe7f2;border-radius:7px;padding:12px;font-size:11px;line-height:1.8}.flow-review-effect{font-size:12px;line-height:1.85;color:#597193;background:#eef5ff;border-left:3px solid #4e84d7;padding:12px;margin:16px 0 0}@media(max-width:600px){.flow-wizard-review dl{grid-template-columns:1fr}.flow-review-path{grid-template-columns:1fr}.flow-review-path>svg{transform:rotate(90deg)}.flow-wizard-review li{flex-wrap:wrap}}
</style>
