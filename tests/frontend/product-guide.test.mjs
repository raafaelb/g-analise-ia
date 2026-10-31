import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {guidePage,searchGuideArticles,visibleGuideSteps} from '../../resources/js/lib/product-guide.ts';
const content=JSON.parse(await readFile(new URL('../../resources/help/product-guide.json',import.meta.url),'utf8'));

test('all projects share one tour page and an unavailable project cannot claim a home tour',()=>{
 assert.equal(guidePage('project',true),'project');
 assert.equal(guidePage('project',false),null);
 assert.equal(guidePage('catalog'),'catalog');
 assert.equal(guidePage('not-a-page'),null);
});
test('the required question about projects finds the product explanation first',()=>{
 assert.equal(searchGuideArticles(content.articles,'Pra que servem os projetos aqui dentro do G-Analise IA?','home')[0]?.id,'projects');
});
test('financial dashboard and authorization questions find their actual help articles',()=>{
 assert.equal(searchGuideArticles(content.articles,'dashboard financeiro',null)[0]?.id,'financial-dashboard');
 assert.equal(searchGuideArticles(content.articles,'autorização','project')[0]?.id,'reviews');
 assert.equal(searchGuideArticles(content.articles,'zzzinexistente',null).length,0);
});
test('contextual suggestions remain available before a query without excluding the rest of the knowledge base',()=>{
 const results=searchGuideArticles(content.articles,'','catalog');
 assert.ok(results[0].pages.includes('catalog'));
 assert.equal(results.length,content.articles.length);
});
test('hidden mobile navigation or missing controls are skipped while the centered introduction remains',()=>{
 const steps=[{id:'intro',title:'Intro',description:'Contexto'},{id:'menu',title:'Menu',description:'Abrir',anchor:'menu'},{id:'composer',title:'Pedido',description:'Converse',anchor:'composer'}];
 assert.deepEqual(visibleGuideSteps(steps,anchor=>anchor==='composer').map(step=>step.id),['intro','composer']);
 assert.deepEqual(visibleGuideSteps(steps,()=>false).map(step=>step.id),['intro']);
});

test('questions about scrolling over a map find the contextual navigation help',()=>{
 assert.equal(searchGuideArticles(content.articles,'rolagem mapa','project')[0]?.id,'preview-navigation');
});
