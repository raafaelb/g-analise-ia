type Item=Record<string,any>;
const copy=<T>(value:T):T=>JSON.parse(JSON.stringify(value));
const same=(left:unknown,right:unknown)=>JSON.stringify(left)===JSON.stringify(right);
const id=()=>crypto.randomUUID();

export const scheduleTriggerTypes=[{value:'Day',label:'Todos os dias'},{value:'Week',label:'Um dia da semana'},{value:'Month',label:'Um dia do mês'},{value:'Hour',label:'A cada algumas horas'},{value:'Minute',label:'A cada alguns minutos'},{value:'CDC',label:'Quando os dados mudarem'}];
export function newScheduleTrigger(anchor:string,type='Day'):Item{return {_uid:id(),_editing:true,type,period:type==='CDC'?10:1,time:'03:00',anchor,window_start:'00:00',window_end:'23:59',_replaceProbe:type==='CDC',probe:{credential_id:'',sql:'',timeout_seconds:5}};}
export function scheduleDraft(response:Item):Item{
 const configuration=response.configuration;
 if(!configuration)return {name:'',active:false,agent:response.agents?.length===1?String(response.agents[0].id):'',triggers:[newScheduleTrigger(response.default_anchor)],flows:[],notifications:{on_error:false,on_success:false},emails:'',parallel_lane:false};
 return {...copy(configuration),agent:String(configuration.agent),triggers:configuration.triggers.map((row:Item)=>({...copy(row),_uid:id(),_editing:false,_replaceProbe:false,probe:{credential_id:'',sql:'',timeout_seconds:5}})),flows:configuration.flows.map((row:Item)=>({...copy(row),flow_id:String(row.flow_id),_uid:id()})),emails:(configuration.notifications.emails??[]).join('\n')};
}
function triggerPatch(row:Item,original?:Item):Item{
 if(original&&row.type===original.type&&!scheduleTriggerTypes.some(type=>type.value===row.type))return {key:row.key};
 const fields:Item={type:row.type};
 if(['Day','Week','Month'].includes(row.type))fields.time=row.time;
 else fields.anchor=row.anchor;
 if(row.type!=='Day')fields.period=Number(row.period);
 if(['Hour','Minute'].includes(row.type)||(row.type==='CDC'&&(row.window_start||row.window_end))){fields.window_start=row.window_start||null;fields.window_end=row.window_end||null;}
 if(row.type==='CDC'&&row._replaceProbe)fields.probe={credential_id:String(row.probe.credential_id).trim(),sql:row.probe.sql.trim(),timeout_seconds:Number(row.probe.timeout_seconds)};
 if(!original)return fields;
 const patch:Item={key:row.key};
 for(const [field,value] of Object.entries(fields)){
  const before=field==='period'?Number(original.period):original[field]??null;
  if(!same(value,before))patch[field]=value;
 }
 return patch;
}
export function schedulePatch(response:Item,draft:Item):Item{
 const source=response.configuration;const patch:Item=source?{resource:response.resource,version:response.version}:{};
 for(const field of ['name','active','agent','parallel_lane']){
  const value=field==='name'?draft.name.trim():field==='agent'?String(draft.agent).trim():Boolean(draft[field]);
  const before=field==='agent'?String(source?.agent??'').trim():source?.[field];
  if(!source||!same(value,before))patch[field]=value;
 }
 const oldTriggers=new Map<string,Item>((source?.triggers??[]).map((row:Item)=>[row.key,row]));
 const triggers=draft.triggers.map((row:Item)=>triggerPatch(row,oldTriggers.get(row.key)));
 if(!source||!same(triggers,source.triggers.map((row:Item)=>({key:row.key}))))patch.triggers=triggers;
 const oldFlows=new Map<string,Item>((source?.flows??[]).map((row:Item)=>[row.key,row]));
 const flows=draft.flows.map((row:Item)=>{
  const before=oldFlows.get(row.key);if(!before)return {flow_id:String(row.flow_id).trim(),context:row.context};
  return before.context===row.context?{key:row.key}:{key:row.key,context:row.context};
 });
 if(!source||!same(flows,source.flows.map((row:Item)=>({key:row.key}))))patch.flows=flows;
 const emails=String(draft.emails).split(/[\n,;]+/).map(value=>value.trim()).filter(Boolean);
 const notifications={on_error:Boolean(draft.notifications.on_error),on_success:Boolean(draft.notifications.on_success),emails};
 if(!source)patch.notifications=notifications;
 else{const changes=Object.fromEntries(Object.entries(notifications).filter(([key,value])=>!same(value,source.notifications[key]??(key==='emails'?[]:false))));if(Object.keys(changes).length)patch.notifications=changes;}
 return patch;
}
export function scheduleChangeCount(patch:Item):number{return Object.keys(patch).filter(key=>!['resource','version'].includes(key)).length;}
export function scheduleContextLabel(context:string):string{
 const simple:Record<string,string>={Total:'Atualizar todos os dados',Incremental:'Buscar novos registros e alterações',KeySweep:'Sincronizar registros excluídos','Temporal:Year:Current':'Atualizar o ano atual'};
 if(simple[context])return simple[context];
 const parts=context.split(':');if(parts[0]==='Temporal'){
  if(parts[1]==='Days')return `Atualizar os últimos ${parts[2]} dias`;
  if(parts[1]==='Future')return `Atualizar os próximos ${parts[2]} dias`;
  if(parts[1]==='Month')return `Atualizar o mês ${parts[2]}`;
  if(parts[1]==='Year')return `Atualizar o ano ${parts[2]}`;
 }
 return 'Manter a forma de carga atual';
}
export function sourceContextModes(flow:Item):Array<{value:string;label:string}>{
 const modes=[{value:'Total',label:'Todos os dados'}];
 if(flow.load_type==='Incremental'){modes.unshift({value:'Incremental',label:'Novos registros e alterações'});if(flow.has_key_sweep)modes.push({value:'KeySweep',label:'Sincronizar exclusões'});}
 if(flow.load_type==='Temporal')modes.unshift({value:'Days',label:'Últimos dias'},{value:'Future',label:'Próximos dias'},{value:'Month',label:'Mês específico'},{value:'Year',label:'Ano específico'},{value:'Current',label:'Ano atual'});
 return modes;
}
export function sourceContextMode(context:string):string{return context==='Temporal:Year:Current'?'Current':context.startsWith('Temporal:')?context.split(':')[1]:context;}
