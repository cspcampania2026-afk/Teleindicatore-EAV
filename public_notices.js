(()=>{
  const box=document.getElementById('publicNotices'),status=document.getElementById('publicNoticesStatus');
  const names={generale:'Avviso',soppressione:'Soppressione',limitazione:'Limitazione',binario:'Binario',ritardo:'Ritardo'};
  const safe=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const render=(notices,eavNotice='')=>{const list=[...notices];if(eavNotice&&!/sistema\s+in\s+fase\s+di\s+test/i.test(eavNotice))list.unshift({kind:'generale',text:eavNotice,source:'EAV'});return list.length?list.map(n=>`<div class="notice-card" data-kind="${Object.hasOwn(names,n.kind)?n.kind:'generale'}"><span class="notice-kind">${n.source==='EAV'?'EAV':names[n.kind]||'Avviso'}</span>${safe(n.text)}</div>`).join(''):'Nessun avviso attivo per questa stazione.';};
  window.CspNotices={render};let busy=false,lastStation='',saved=[];
  const station=()=>document.getElementById('stationSelect')?.value||'';
  async function refresh(){const current=station();if(!current||busy)return;busy=true;if(current!==lastStation){saved=[];box.textContent='Caricamento avvisi…';}try{const response=await fetch('avvisi.php?'+new URLSearchParams({station:current}),{cache:'no-store'});const payload=await response.json();if(!response.ok||payload.error)throw Error();if(station()!==current)return;saved=payload.notices;lastStation=current;box.innerHTML=render(saved,window.cspSourceStation===current?window.cspSourceNotice:'');status.textContent='';}catch{status.textContent='Avvisi non aggiornati: nuovo tentativo automatico.';}finally{busy=false;}}
  document.getElementById('stationSelect')?.addEventListener('change',()=>{lastStation='';refresh();});document.getElementById('setupStart')?.addEventListener('click',()=>setTimeout(refresh,0));
  setInterval(refresh,10000);setTimeout(refresh,1000);
})();
