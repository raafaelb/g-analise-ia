export function previousInspectionIsUsable(error:unknown):boolean{
 if(!(error instanceof Error))return false;
 const {status,fields}=error as Error&{status?:number;fields?:string[]};
 if(status===undefined||status===429)return true;
 return status===422&&Array.isArray(fields)&&fields.length>0&&fields.every(field=>['schema','search','offset'].includes(field));
}
