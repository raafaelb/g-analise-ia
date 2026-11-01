export type ExecutionMessage = {summary:string;details:string|null};

export function describeExecutionMessage(value:unknown,state:unknown):ExecutionMessage|null {
 if(typeof value!=='string'||!value.trim())return null;
 const message=value.trim();
 const technical=message.length>320||message.split(/\r?\n/).length>3||/\b(?:[a-z_$][\w.$]*(?:Exception|Error)|SQLSTATE|Traceback|StackTrace)\b|(?:^|\n)\s*(?:at\s+\S+\s*\(|#\d+\s+|(?:Error|Exception)\s*:)/i.test(message);
 if(!technical)return {summary:message,details:null};
 const failed=typeof state==='string'&&['failed','failure','error','crashed'].includes(state.toLowerCase());
 return {summary:failed?'O BI informou uma falha nesta execução. Abra os detalhes técnicos para conferir o motivo.':'O BI retornou uma mensagem técnica para esta execução. Abra os detalhes para consultar o conteúdo.',details:message};
}
