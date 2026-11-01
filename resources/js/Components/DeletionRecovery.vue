<script setup lang="ts">
import {computed,ref} from 'vue';
import {AlertCircle,LoaderCircle,RefreshCw} from 'lucide-vue-next';
type Item=Record<string,any>;
const props=defineProps<{approval:Item;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{confirmed:[result:Item]}>();
const busy=ref(false);const error=ref('');
const plan=computed(()=>props.approval.payload?.revision?.app_deletion??props.approval.payload?.revision?.flow_deletion);
const isApp=computed(()=>plan.value?.kind==='app');
const available=computed(()=>props.approval.status==='failed'&&props.approval.payload?.action?.action==='delete_draft'&&['app','flow'].includes(plan.value?.kind)&&plan.value?.resource===props.approval.payload?.action?.resource&&!!props.approval.payload?.deletion_attempt?.started_at&&!props.approval.payload?.deletion_attempt?.native_refused_at);
async function verify(){
 if(busy.value||!available.value)return;
 busy.value=true;error.value='';
 try{const result=await props.request(`/approvals/${props.approval.id}/deletion/verify`,'POST');emit('confirmed',result);}
 catch(e){error.value=(e as Error).message;}finally{busy.value=false;}
}
</script>
<template><div v-if="available" class="deletion-recovery"><p>{{isApp?'Confira se a aplicação foi removida e as tabelas vinculadas foram preservadas.':'Confira se o ETL e todas as tabelas da revisão foram removidos.'}} Esta consulta não exclui novamente, não remove dependências e não inicia uma tarefa de IA.</p><button type="button" class="secondary-button" :disabled="busy" @click="verify"><LoaderCircle v-if="busy" :size="15" class="spin"/><RefreshCw v-else :size="15"/>{{busy?'Conferindo exclusão…':'Conferir exclusão'}}</button><p v-if="error" class="form-error" role="alert"><AlertCircle :size="15"/>{{error}}</p></div></template>
<style scoped>.deletion-recovery{margin-top:12px;padding:13px;border:1px solid #dce6f5;background:#f8fbff;border-radius:8px}.deletion-recovery>p{font-size:12px;line-height:1.7;color:#68778f;margin-bottom:10px}.deletion-recovery .form-error{margin-top:10px;margin-bottom:0;color:#99552c}</style>
