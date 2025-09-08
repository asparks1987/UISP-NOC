function openTab(id){
  document.querySelectorAll('.tabcontent').forEach(x=>x.style.display='none');
  document.querySelectorAll('.tablink').forEach(x=>x.classList.remove('active'));
  document.getElementById(id).style.display='block';
  event.target.classList.add('active');
}
function badgeVal(v,label,suf){if(v==null)return'';let cls='good';if(v>90)cls='bad';else if(v>75)cls='warn';return `<span class="badge ${cls}">${label}: ${v}${suf}</span>`;}
function badgeLatency(v){if(v==null)return'';let cls='good';if(v>500)cls='bad';else if(v>100)cls='warn';return `<span class="badge ${cls}">Latency: ${v} ms</span>`;}

let sirenTimeout=null;
let devicesCache=[];
let audioUnlocked=false;

function unlockAudio(){
  if(audioUnlocked) return;
  const a=document.getElementById('siren');
  if(!a) return;
  const prevMuted=a.muted;
  const prevVol=a.volume;
  a.muted=false;
  a.volume=0.05; // brief quiet blip
  const p=a.play();
  const onSuccess=()=>{
    setTimeout(()=>{ try{ a.pause(); a.currentTime=0; a.volume=prevVol; a.muted=prevMuted; }catch(_){}; audioUnlocked=true; const b=document.getElementById('enableSoundBtn'); if(b) b.style.display='none'; }, 120);
  };
  if(p && p.then){
    p.then(onSuccess).catch(()=>{ /* still blocked */ });
  } else {
    try{ onSuccess(); }catch(_){ }
  }
}

function enableSound(){
  unlockAudio();
}

function fetchDevices(){
 fetch('?ajax=devices').then(r=>r.json()).then(j=>{
  devicesCache=j.devices;
  const gws=j.devices.filter(d=>d.gateway).sort((a,b)=>a.online-b.online||a.name.localeCompare(b.name));
  const cps=j.devices.filter(d=>!d.gateway).sort((a,b)=>a.name.localeCompare(b.name));

  // Gateways
  const gwHTML=gws.map(d=>{
    const badges=[badgeVal(d.cpu,'CPU','%'),badgeVal(d.ram,'RAM','%'),badgeVal(d.temp,'Temp','°C'),badgeLatency(d.latency)].join(' ');
    const ackActive = d.ack_until && d.ack_until > (Date.now()/1000);
    return `<div class="card ${d.online?'':'offline'}">
      <h2>${d.name}</h2>
      <div class="status" style="color:${d.online?'#b06cff':'#f55'}">${d.online?'ONLINE':'OFFLINE'}</div>
      <div>${badges}</div>
      <div class="actions">
        ${!d.online ? `
          <div class="dropdown">
            <button onclick="toggleAckMenu('${d.id}')">Ack ▾</button>
            <div id="ack-${d.id}" class="dropdown-content" style="display:none;background:#333;position:absolute;">
              <a href="#" onclick="ack('${d.id}','30m')">30m</a>
              <a href="#" onclick="ack('${d.id}','1h')">1h</a>
              <a href="#" onclick="ack('${d.id}','6h')">6h</a>
              <a href="#" onclick="ack('${d.id}','8h')">8h</a>
              <a href="#" onclick="ack('${d.id}','12h')">12h</a>
            </div>
          </div>
          ${ackActive ? `<button onclick="clearAck('${d.id}')">Clear Ack</button>`:''}
        `:``}
        ${d.simulate
          ? `<button onclick="clearSim('${d.id}')">Clear Sim</button>`
          : `<button onclick="simulate('${d.id}')">Simulate Outage</button>`}
        <button onclick="showHistory('${d.id}','${d.name}')">History</button>
      </div>
    </div>`;
  }).join('');
  document.getElementById('gateGrid').innerHTML=gwHTML;

  // CPEs
  const cpeHTML=cps.map(d=>`<div class="card ${d.online?'':'offline'}"><h2>${d.name}</h2><div style="color:${d.online?'#b06cff':'#f55'}">${d.online?'ONLINE':'OFFLINE'}</div></div>`).join('');
  document.getElementById('cpeGrid').innerHTML=cpeHTML;

  document.getElementById('footer').innerText=`HTTP ${j.http}, API latency ${j.api_latency} ms, Updated ${new Date().toLocaleTimeString()}`;

  // Siren logic (also trigger for simulated outages so you can test it)
  if(gws.some(d=>!d.online)){
    if(!sirenTimeout){
      sirenTimeout=setTimeout(()=>{
        const a=document.getElementById('siren');
        if(!a) return;
        try{ a.pause(); a.currentTime=0; a.muted=false; a.volume=1; }catch(_){ }
        const pr=a.play();
        if(pr && pr.catch){ pr.catch(()=>{ const b=document.getElementById('enableSoundBtn'); if(b) b.style.display=''; }); }
      },30000);
    }
  } else {
    clearTimeout(sirenTimeout); sirenTimeout=null;
    document.getElementById('siren').pause(); document.getElementById('siren').currentTime=0;
  }
 });
}
function toggleAckMenu(id){const el=document.getElementById('ack-'+id);el.style.display=el.style.display==='none'?'block':'none';}
function ack(id,dur){
  // Optimistic UI
  let dev=devicesCache.find(x=>x.id===id);
  if(dev){dev.ack_until=Date.now()/1000+1800;}
  fetch(`?ajax=ack&id=${id}&dur=${dur}`).then(fetchDevices);
}
function clearAck(id){
  let dev=devicesCache.find(x=>x.id===id);
  if(dev){dev.ack_until=null;}
  fetch(`?ajax=clear&id=${id}`).then(fetchDevices);
}
function simulate(id){
  // Try to unlock audio on explicit user action
  unlockAudio();
  let dev=devicesCache.find(x=>x.id===id);
  if(dev){dev.simulate=true;}
  fetch(`?ajax=simulate&id=${id}`).then(fetchDevices);
}
function clearSim(id){
  let dev=devicesCache.find(x=>x.id===id);
  if(dev){dev.simulate=false;}
  fetch(`?ajax=clearsim&id=${id}`).then(fetchDevices);
}
function clearAll(){fetch(`?ajax=clearall`).then(fetchDevices);}
function closeModal(){document.getElementById('historyModal').style.display='none';}
function showHistory(id,name){
 fetch(`?ajax=history&id=${id}`).then(r=>r.json()).then(rows=>{
   document.getElementById('histTitle').innerText=`History for ${name}`;
   const labels=rows.map(r=>r.timestamp);
   const cpu=rows.map(r=>r.cpu);
   const ram=rows.map(r=>r.ram);
   const temp=rows.map(r=>r.temp);
   const lat=rows.map(r=>r.latency);

   new Chart(document.getElementById('cpuChart'),{type:'line',data:{labels,datasets:[{label:'CPU %',data:cpu,borderColor:'lime'}]}});
   new Chart(document.getElementById('ramChart'),{type:'line',data:{labels,datasets:[{label:'RAM %',data:ram,borderColor:'yellow'}]}});
   new Chart(document.getElementById('tempChart'),{type:'line',data:{labels,datasets:[{label:'Temp °C',data:temp,borderColor:'red'}]}});
   new Chart(document.getElementById('latChart'),{type:'line',data:{labels,datasets:[{label:'Latency ms',data:lat,borderColor:'cyan'}]}});
   document.getElementById('historyModal').style.display='block';
 });
}
setInterval(fetchDevices,10000);
fetchDevices();

// Best-effort unlock on first user click anywhere
window.addEventListener('click',unlockAudio,{once:true});

