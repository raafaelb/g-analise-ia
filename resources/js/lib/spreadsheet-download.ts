export const spreadsheetMime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

export async function saveSpreadsheet(response:Response,filename:string,isCurrent:()=>boolean=()=>true):Promise<boolean>{
    if(!isCurrent()){await response.body?.cancel().catch(()=>{});return false;}
    if(!response.ok||response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase()!==spreadsheetMime){
        throw new Error('O BI não retornou uma planilha válida para download.');
    }
    const blob=await response.blob();
    if(!isCurrent())return false;
    if(blob.size===0)throw new Error('O BI retornou um arquivo vazio. Tente consultar o cadastro novamente.');
    const url=URL.createObjectURL(blob);
    const link=document.createElement('a');
    try{
        link.href=url;
        link.download=/^cadastro-[1-9][0-9]*\.xlsx$/.test(filename)?filename:'cadastro.xlsx';
        link.hidden=true;
        document.body.appendChild(link);
        link.click();
    }finally{
        link.remove();
        setTimeout(()=>URL.revokeObjectURL(url),1000);
    }
    return true;
}
