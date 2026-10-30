<script setup lang="ts">
import {computed} from 'vue';
type Item=Record<string,any>;
const props=defineProps<{widget:Item}>();
const rows=computed<Item[]>(()=>{const data=props.widget.rows??props.widget.data?.rows??(Array.isArray(props.widget.data)?props.widget.data:[]);return Array.isArray(data)?data.filter(v=>v!==null&&typeof v==='object'):[];});
const fields=computed(()=>Array.from(new Set(rows.value.flatMap(row=>Object.keys(row)))).slice(0,40));
const semantic=computed<Item>(()=>props.widget.semantic??{});
const hasValue=computed(()=>semantic.value.value!==null&&semantic.value.value!==undefined);
const hasTotal=computed(()=>semantic.value.total!==null&&semantic.value.total!==undefined);
const highlights=computed<Item[]>(()=>Array.isArray(semantic.value.top)?semantic.value.top:[]);
const hasShareFormat=computed(()=>highlights.value.some(item=>typeof item.shareFmt==='string'));
const empty=computed(()=>semantic.value.isEmpty===true||semantic.value.rowCount===0||props.widget.row_count===0);
const notes=computed(()=>Array.isArray(semantic.value.notes)?semantic.value.notes:[]);
function number(value:unknown){return typeof value==='number'?value.toLocaleString('pt-BR'):String(value??'—');}
</script>
<template>
 <article class="catalog-widget-data"><div><strong>{{widget.nome||widget.title||'Indicador '+widget.widget_id}}</strong><span>{{widget.type}}</span></div><p v-if="widget.reason||widget.error">{{widget.reason||widget.error}}</p>
  <template v-else><div v-if="hasValue" class="widget-semantic-value"><strong>{{semantic.valueFmt??number(semantic.value)}}</strong><span>Valor do indicador</span></div><p v-else-if="hasTotal" class="resource-note">Resumo numérico retornado pela consulta: <strong>{{semantic.totalFmt??number(semantic.total)}}</strong></p><p v-if="typeof semantic.rowCount==='number'" class="resource-note">{{semantic.rowCount.toLocaleString('pt-BR')}} {{semantic.rowCount===1?'linha consultada':'linhas consultadas'}}</p>
   <div v-if="highlights.length" class="catalog-table-scroll"><h4>Destaques retornados pelo BI</h4><table><thead><tr><th>Item</th><th>Valor</th><th v-if="hasShareFormat">Participação informada</th></tr></thead><tbody><tr v-for="(item,index) in highlights" :key="index"><td>{{item.label}}</td><td>{{item.valueFmt??number(item.value)}}</td><td v-if="hasShareFormat">{{item.shareFmt??'—'}}</td></tr></tbody></table><p class="resource-note">Este resumo pode conter apenas os principais resultados. Use a prévia visual para conferir o gráfico completo.</p></div>
   <div v-else-if="rows.length" class="catalog-table-scroll"><table><thead><tr><th v-for="field in fields" :key="field">{{field}}</th></tr></thead><tbody><tr v-for="(row,index) in rows.slice(0,20)" :key="index"><td v-for="field in fields" :key="field">{{number(row[field])}}</td></tr></tbody></table></div>
   <p v-else-if="!hasValue&&!hasTotal" class="resource-note">{{empty?'A consulta não encontrou dados.':widget.status==='ok'?'Consulta concluída. O BI não disponibilizou os valores detalhados deste indicador.':'Este indicador não retornou dados para a conferência.'}}</p><p v-for="(note,index) in notes" :key="index" class="resource-note">{{note}}</p>
  </template>
 </article>
</template>
