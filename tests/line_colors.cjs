const fs=require('fs'),vm=require('vm'),path=require('path');
const root=path.join(__dirname,'..'),html=fs.readFileSync(path.join(root,'index.html'),'utf8');
const code="const normalize=s=>s.toLowerCase();\n"+html.slice(html.indexOf('    const EAV_LINE_NUMBERS ='),html.indexOf('    function renderInfo('));
const classes={L1:'line-sorrento',TORRE:'line-torre-annunziata',L4:'line-poggiomarino',L6:'line-sarno',L8:'line-baiano'};
let checked=0;
for(const [file,day] of [['corse_linee.json','2026-10-16'],['corse_linee_20261017.json','2026-10-17']]){
 const map=JSON.parse(fs.readFileSync(path.join(root,file),'utf8'));
 const context={Intl:{DateTimeFormat:class{format(){return day}}},Date};vm.createContext(context);vm.runInContext(code,context);
 for(const [number,line] of Object.entries(map)){
  const actual=context.lineColorClass(number,'1','NAPOLI PORTA NOLANA');const rule=/^1\d{3}$/.test(number)?'L1':/^1\d{4}$/.test(number)?'TORRE':number.startsWith('4')?'L4':number.startsWith('6')?'L6':number.startsWith('8')?'L8':line;if(actual!==classes[rule])throw Error(`${day} ${number}: ${actual}, atteso ${classes[line]}`);checked++;
 }
}
console.log(`PASS: ${checked} associazioni verificate con priorità alle regole di numerazione verso Napoli, per entrambi i programmi.`);

// Exercise the real row renderer with conflicting upstream color classes.
const context={Intl,Date,JSON,window:{},selectedStation:'27',document:{getElementById(){return this.tbody}},requestAnimationFrame(){},escapeHtml:String,renderInfo:()=>'',delayClass:()=>'',lastRenderSignature:''};context.document.tbody={innerHTML:''};vm.createContext(context);vm.runInContext(code+html.slice(html.indexOf('    function renderDepartures('),html.indexOf('    function setStatus(')),context);
for(const [number,expected] of [['1052','line-sorrento'],['4052','line-poggiomarino'],['10654','line-torre-annunziata'],['6062','line-sarno'],['6080','line-sarno'],['6112','line-sarno'],['80609','line-baiano']]){
 context.renderDepartures([{number,destination:'NAPOLI PORTA NOLANA',color:'default-line line-sorrento',delay:0}]);const match=context.document.tbody.innerHTML.match(/class="train-number ([^"]+)"/);if(!match||match[1]!==expected)throw Error('Conflitto classi nel rendering: '+number+' '+match?.[1]);
}
console.log('PASS: HTML delle righe verificato con colore grigio in ingresso; una sola classe di linea applicata.');
