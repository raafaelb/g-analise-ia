<script setup lang="ts">
import {onUnmounted,ref} from 'vue';
import {AlertCircle,ArrowUpRight,Building2,CheckCircle2,KeyRound,LoaderCircle,ShieldCheck} from 'lucide-vue-next';
import {appUrl} from '../lib/urls';
import {createRequestGuard} from '../lib/async-response';
type Item=Record<string,any>;
const props=defineProps<{user:Item;suiteUrl?:string;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{updated:[];notice:[message:string]}>();
const currentPassword=ref('');const password=ref('');const confirmation=ref('');const busy=ref(false);const error=ref('');const success=ref('');const guard=createRequestGuard();
async function save(){
 if(busy.value)return;const current=guard.next();busy.value=true;error.value='';success.value='';
 try{const result=await props.request('/account/password','PATCH',{current_password:currentPassword.value,password:password.value,password_confirmation:confirmation.value});if(!current())return;success.value=result.message;emit('notice',result.message);if(props.user.must_change_password){window.location.assign(appUrl('/app'));return;}emit('updated');}
 catch(e){if(current())error.value=(e as Error).message;}
 finally{currentPassword.value='';password.value='';confirmation.value='';if(current())busy.value=false;}
}
onUnmounted(()=>{guard.invalidate();currentPassword.value='';password.value='';confirmation.value='';});
</script>
<template>
 <div class="content-view account-security-view"><div class="page-heading"><div><div class="section-eyebrow">SEU ACESSO, COM SEGURANÇA</div><h1>Segurança da conta</h1><p>{{user.email}}</p></div><span class="soft-badge"><ShieldCheck :size="15"/>Acesso pessoal</span></div>
  <section v-if="user.auth_provider==='suite'" class="account-security-card"><Building2 :size="27"/><h2>Sua conta está vinculada à Suíte</h2><p>Use sua senha de colaborador da Inovadora para entrar. Seus dados de identidade e sua senha são gerenciados na Suíte.</p><a :href="suiteUrl||'https://suite.inovadora.com.br'" target="_blank" rel="noopener noreferrer" class="secondary-button">Abrir a Suíte<ArrowUpRight :size="16"/></a></section>
  <section v-else class="account-security-card"><KeyRound :size="27"/><h2>{{user.must_change_password?'Defina sua senha pessoal':'Trocar sua senha'}}</h2><p>{{user.must_change_password?'Sua senha inicial permite apenas este primeiro passo. Escolha uma senha pessoal para liberar seus projetos e conexões.':'Escolha uma senha exclusiva para o G-Analise IA. As outras sessões serão encerradas, e tarefas e revisões anteriores serão canceladas.'}}</p><form @submit.prevent="save"><label>{{user.must_change_password?'Senha inicial':'Senha atual'}}<input v-model="currentPassword" type="password" autocomplete="current-password" required :disabled="busy"></label><label>Nova senha<input v-model="password" type="password" autocomplete="new-password" required minlength="12" maxlength="64" :disabled="busy"></label><label>Confirme a nova senha<input v-model="confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="64" :disabled="busy"></label><p class="account-password-hint">Use pelo menos 12 caracteres, com letras maiúsculas, minúsculas e números.</p><p v-if="error" class="form-error" role="alert"><AlertCircle :size="16"/>{{error}}</p><p v-if="success" class="account-password-success" role="status"><CheckCircle2 :size="16"/>{{success}}</p><button class="primary-button" :disabled="busy"><LoaderCircle v-if="busy" :size="16" class="spin"/>{{user.must_change_password?'Salvar senha e continuar':'Atualizar senha'}}</button></form></section>
 </div>
</template>
<style scoped>
.account-security-view{max-width:940px;width:100%;margin:0 auto}
.account-security-card{box-sizing:border-box;width:100%;max-width:580px;padding:29px;background:#fff;border:1px solid #e1e7f0;border-radius:13px;color:#5077ac}
.account-security-card h2{font-size:19px;color:#304967;margin:17px 0 12px}
.account-security-card>p{font-size:13px;line-height:1.9;color:#74849d}
.account-security-card>a{margin-top:20px}
.account-security-card form{display:grid;grid-template-columns:minmax(0,1fr);gap:18px;margin-top:24px}
.account-security-card form>label{display:grid;gap:8px;min-width:0;font-size:12px;font-weight:600;color:#50627d}
.account-security-card input{box-sizing:border-box;display:block;width:100%;min-width:0;height:44px;padding:11px 12px;border:1px solid #dbe3ef;border-radius:8px;background:#fbfcff;color:#30496a;font:inherit;font-size:13px;line-height:1.5;transition:border-color .15s,box-shadow .15s}
.account-security-card input:hover{border-color:#b6c8e1}
.account-security-card input:focus-visible{outline:2px solid #0861ee;outline-offset:2px;border-color:#8db6f0;box-shadow:0 0 0 3px #0861ee0b}
.account-security-card input:disabled{cursor:wait;background:#f2f5fa;color:#74849d}
.account-password-hint{font-size:11px;color:#8191a8;line-height:1.8;margin:0}
.account-password-success{display:flex;align-items:center;gap:8px;font-size:12px;color:#307b5b;margin:0}
.account-security-card form>.form-error{margin:0}
.account-security-card form>button{justify-self:start;margin-top:3px}
@media(max-width:600px){.account-security-card{padding:23px}.account-security-card form>button{justify-self:stretch;width:100%}}
@media(prefers-reduced-motion:reduce){.account-security-card input{transition:none}}
</style>
