export const observingLoadStates=['requesting','accepted','running','uncertain'];
export function loadLabel(status:string):string {
 return ({requesting:'Enviando pedido',accepted:'Pedido enviado · aguardando execução',running:'Atualizando dados',succeeded:'Atualização concluída',failed:'Carga com falha',cancelled:'Carga cancelada no BI',rejected:'Pedido recusado pelo BI',uncertain:'Envio em conferência',ambiguous:'Resultado sem vínculo confirmado',access_changed:'Acompanhamento pausado · acesso alterado',needs_attention:'Resultado pendente de conferência',needs_revalidation:'Conferir após restauração'} as Record<string,string>)[status]??'Resultado não confirmado';
}
export function flowResults(bindings:Record<string,any>[],proof:Record<string,any>|null):Record<string,any>[] {
 const remaining=[...(proof?.flows??[])];
 return bindings.map(binding=>{
  const index=remaining.findIndex(row=>row.flow_id===binding.flow_id&&(row.context===binding.context||row.context===null));
  const result=index<0?{}:remaining.splice(index,1)[0];
  return {...binding,...result,name:binding.name,context:result.context===null?null:binding.context,status:result.status??'pending'};
 });
}

export function loadDate(value:string):string {
 const date=new Date(value);
 return Number.isFinite(date.getTime())?date.toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo'}):'Horário não informado';
}
