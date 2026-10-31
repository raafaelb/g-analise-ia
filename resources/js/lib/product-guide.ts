export type GuideStep = {id:string;title:string;description:string;anchor?:string};
export type GuideArticle = {id:string;title:string;summary:string;body:string;pages:string[];actions:{page:string;label:string}[]};
export type GuideProgress = {page_key:string;last_status:string;last_instance_id:string;first_seen_at:string|null;replay_count:number};
export type GuideContent = {version:string;welcome:{title:string;description:string;features:{title:string;description:string}[]};pages:Record<string,{title:string;steps:GuideStep[]}>;articles:GuideArticle[];progress:Record<string,GuideProgress>};
const stopWords=new Set(['como','para','pra','que','quem','onde','serve','servem','aqui','dentro','posso','faco','fazer','meus','minhas','g-analise','sistema','sobre','pelo','pela','uma','com','dos','das']);
function words(value:string){return value.normalize('NFD').replace(/\p{M}/gu,'').toLocaleLowerCase('pt-BR').split(/[^a-z0-9-]+/).filter(word=>word.length>2&&!stopWords.has(word)).map(word=>word.length>5&&word.endsWith('s')?word.slice(0,-1):word);}
export function searchGuideArticles(articles:GuideArticle[],query:string,page:string|null):GuideArticle[]{
 const terms=[...new Set(words(query))];
 return articles.map((article,index)=>{const title=words(article.title).join(' '),summary=words(article.summary).join(' '),body=words(article.body).join(' ');const relevance=terms.reduce((score,term)=>score+(title.includes(term)?8:summary.includes(term)?4:body.includes(term)?1:0),0);return{article,index,score:terms.length?relevance:article.pages.includes(page??'')?2:1};}).filter(item=>item.score>0).sort((a,b)=>b.score-a.score||a.index-b.index).map(item=>item.article);
}
export function visibleGuideSteps(steps:GuideStep[],visible:(anchor:string)=>boolean):GuideStep[]{return steps.filter(step=>!step.anchor||visible(step.anchor));}
export function guidePage(section:string,projectAvailable=true):string|null{return ['home','projects','catalog','connections','refresh','activity','account','users','project'].includes(section)&&(section!=='project'||projectAvailable)?section:null;}
