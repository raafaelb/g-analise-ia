<script setup lang="ts">
import {computed} from 'vue';
import {Eye,Image,Info} from 'lucide-vue-next';
import {widgetPreviewPresentation} from '../lib/widget-preview';
type Item=Record<string,any>;
const props=defineProps<{widget:Item;interactiveAvailable?:boolean;visualAvailable?:boolean;busy?:boolean}>();
const emit=defineEmits<{interactive:[];visual:[]}>();
const presentation=computed(()=>widgetPreviewPresentation(props.widget));
const rows=computed<Item[]>(()=>{const data=props.widget.rows??props.widget.data?.rows??(Array.isArray(props.widget.data)?props.widget.data:[]);return Array.isArray(data)?data.filter(v=>v!==null&&typeof v==='object'):[];});
const fields=computed(()=>Array.from(new Set(rows.value.flatMap(row=>Object.keys(row)))).slice(0,40));
const semantic=computed<Item>(()=>props.widget.semantic??{});
const hasValue=computed(()=>semantic.value.value!==null&&semantic.value.value!==undefined);
const hasTotal=computed(()=>semantic.value.total!==null&&semantic.value.total!==undefined);
const highlights=computed<Item[]>(()=>Array.isArray(semantic.value.top)?semantic.value.top:[]);
const hasShareFormat=computed(()=>highlights.value.some(item=>typeof item.shareFmt==='string'));
const empty=computed(()=>semantic.value.isEmpty===true||semantic.value.rowCount===0||props.widget.row_count===0);
const notes=computed(()=>presentation.value.notes);
function number(value:unknown){return typeof value==='number'?value.toLocaleString('pt-BR'):String(value??'—');}
</script>
<template>
 <article class="catalog-widget-data"><div><strong>{{widget.nome||widget.title||'Indicador '+widget.widget_id}}</strong><span>{{presentation.typeLabel}}</span></div>
  <section v-if="presentation.notice" class="widget-preview-notice" :class="presentation.notice.kind" role="note"><Info :size="20"/><div><strong>{{presentation.notice.title}}</strong><p>{{presentation.notice.message}}</p><div v-if="interactiveAvailable||visualAvailable" class="widget-preview-actions"><button v-if="interactiveAvailable" class="secondary-button" :disabled="busy" @click="emit('interactive')"><Eye :size="16"/>Abrir painel interativo</button><button v-if="visualAvailable" class="secondary-button" :disabled="busy" @click="emit('visual')"><Image :size="16"/>Ver prévia visual</button></div></div></section>
  <template v-else><div v-if="hasValue" class="widget-semantic-value"><strong>{{semantic.valueFmt??number(semantic.value)}}</strong><span>Valor do indicador</span></div><p v-else-if="hasTotal" class="resource-note">Resumo numérico retornado pela consulta: <strong>{{semantic.totalFmt??number(semantic.total)}}</strong></p><p v-if="typeof semantic.rowCount==='number'" class="resource-note">{{semantic.rowCount.toLocaleString('pt-BR')}} {{semantic.rowCount===1?'linha consultada':'linhas consultadas'}}</p>
   <div v-if="highlights.length" class="catalog-table-scroll"><h4>Destaques retornados pelo BI</h4><table><thead><tr><th>Item</th><th>Valor</th><th v-if="hasShareFormat">Participação informada</th></tr></thead><tbody><tr v-for="(item,index) in highlights" :key="index"><td>{{item.label}}</td><td>{{item.valueFmt??number(item.value)}}</td><td v-if="hasShareFormat">{{item.shareFmt??'—'}}</td></tr></tbody></table><p class="resource-note">Este resumo pode conter apenas os principais resultados. Use a prévia visual para conferir o gráfico completo.</p></div>
   <div v-else-if="rows.length" class="catalog-table-scroll"><table><thead><tr><th v-for="field in fields" :key="field">{{field}}</th></tr></thead><tbody><tr v-for="(row,index) in rows.slice(0,20)" :key="index"><td v-for="field in fields" :key="field">{{number(row[field])}}</td></tr></tbody></table></div>
   <p v-else-if="!hasValue&&!hasTotal" class="resource-note">{{empty?'A consulta não encontrou dados.':widget.status==='ok'?'Consulta concluída. O BI não disponibilizou os valores detalhados deste indicador.':'Este indicador não retornou dados para a conferência.'}}</p><p v-for="(note,index) in notes" :key="index" class="resource-note">{{note}}</p>
  </template>
 </article>
</template>

<style scoped>
.widget-preview-notice{display:flex;align-items:flex-start;gap:12px;margin-top:15px;padding:17px;background:#f5f8ff;border:1px solid #dfe9fa;border-radius:8px;color:#6284b3}
.widget-preview-notice>svg{margin-top:1px}
.widget-preview-notice strong{display:block;color:#405e88;font-size:12px;line-height:1.6}
.widget-preview-notice p{font-size:12px;line-height:1.8;margin-top:6px;color:#7185a3}
.widget-preview-notice.error{background:#fffbf5;border-color:#eee1c7}
.widget-preview-actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:14px}
.widget-preview-actions .secondary-button{background:#fff;font-size:11px}
@media(max-width:760px){.widget-preview-notice{padding:13px;gap:9px}.widget-preview-actions{flex-direction:column;align-items:flex-start}}
</style>
