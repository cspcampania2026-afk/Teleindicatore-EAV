'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../admin_dashboard.js','utf8');
const code=source.slice(source.indexOf('  async function refresh(){'),source.indexOf('  function changeStation()'));
const elements={opsStation:{value:'1'},refreshBoard:{disabled:false},refreshStamp:{textContent:''},stationTitle:{},sourceWarnings:{}};
let historyCalls=0,requests=0,rendered=0,resolveRequest;
const payload={rows:[{number:'1001'}],stationName:'Napoli Porta Nolana',errors:{},updated:{A:'2026-10-18T18:00:00Z'}};
const ctx={inFlight:false,pending:false,refreshStation:'',refreshCountdown:0,rows:[],historyRows:[],activeTab:'monitor',el:id=>elements[id],render:()=>rendered++,refreshHistory:()=>{historyCalls++;return new Promise(()=>{});},updateLinks:()=>{},feedback:()=>{},api:()=>{requests++;return new Promise(resolve=>resolveRequest=resolve);},Date};
vm.createContext(ctx);vm.runInContext(code,ctx);
(async()=>{
 const first=ctx.refresh();await ctx.refresh();assert.equal(requests,1);assert.equal(ctx.pending,false);resolveRequest(payload);await first;
 assert.equal(historyCalls,0);assert.equal(rendered,1);assert.equal(ctx.refreshCountdown,15);assert.equal(elements.refreshBoard.disabled,false);assert(!elements.refreshStamp.textContent.includes('in corso'));
 ctx.activeTab='history';const second=ctx.refresh();resolveRequest(payload);await second;
 assert.equal(historyCalls,1);assert.equal(ctx.inFlight,false);assert.equal(elements.refreshBoard.disabled,false);
 ctx.api=async()=>{throw Error('server lento');};await ctx.refresh();assert.equal(elements.refreshStamp.textContent,'Collegamento non disponibile');assert.equal(ctx.inFlight,false);
 console.log('PASS: aggiornamento non sovrapposto, resoconto indipendente, ripristino pulsante e ciclo di 15 secondi anche dopo errore.');
})().catch(e=>{console.error(e);process.exitCode=1;});
