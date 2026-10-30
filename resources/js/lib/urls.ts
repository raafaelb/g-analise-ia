export function basePath():string {
 return document.querySelector<HTMLMetaElement>('meta[name="app-base-path"]')?.content.replace(/\/$/,'')??'';
}
export function appUrl(path:string='/'):string {return basePath()+'/'+path.replace(/^\//,'');}
export function localPath():string {const base=basePath();return base&&location.pathname.startsWith(base+'/')?location.pathname.slice(base.length):location.pathname;}
