import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {parse,compileScript} from '@vue/compiler-sfc';
import ts from 'typescript';
import {createSSRApp} from 'vue';
import {renderToString} from '@vue/server-renderer';

const path=new URL('../../resources/js/Pages/Error.vue',import.meta.url);
const {descriptor}=parse(await readFile(path,'utf8'));
let script=compileScript(descriptor,{id:'error-recovery',inlineTemplate:true}).content;
script=script.replace(/import\s*{\s*Head\s*}\s*from\s*['"]@inertiajs\/vue3['"];?/,"const Head={props:['title'],setup:()=>()=>null};");
script=script.replace(/from (['"])([^'"]+)\1/g,(_,quote,source)=>`from ${quote}${source.startsWith('.')?new URL(source+'.ts',path).href:import.meta.resolve(source)}${quote}`);
const code=ts.transpileModule(script,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText;
const ErrorPage=(await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'))).default;

for(const prefix of ['', '/ia-ganalise']){
 test(`an expired page offers a normal GET back to the workspace in ${prefix||'the root'}`,async t=>{
  const previous=Object.getOwnPropertyDescriptor(globalThis,'document');
  globalThis.document={querySelector:selector=>selector==='meta[name="app-base-path"]'?{content:prefix}:null};
  t.after(()=>{if(previous)Object.defineProperty(globalThis,'document',previous);else delete globalThis.document;});

  const html=await renderToString(createSSRApp(ErrorPage,{status:419}));

  assert.match(html,/Sua sessão expirou/);
  assert.match(html,/Abrir o sistema novamente/);
  assert.match(html,new RegExp('href="'+prefix+'/app"'));
  assert.match(html,new RegExp('src="'+prefix+'/brand/g-analise-icon.png"'));
  assert.match(html,/conversa ou em Atividade/);
  assert.doesNotMatch(html,/<form\b|<button\b|javascript:|http-equiv="refresh"/i);
 });
}
