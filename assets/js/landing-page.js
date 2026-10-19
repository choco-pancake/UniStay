document.querySelectorAll('nav a[href^="#"]').forEach(function(link){
  link.addEventListener('click',function(e){
    var target=document.querySelector(link.getAttribute('href'));
    if(!target) return;
    e.preventDefault();
    var top=target.getBoundingClientRect().top+window.pageYOffset-88;
    window.scrollTo({top:Math.max(0,top),behavior:'smooth'});
    history.replaceState(null,'',link.getAttribute('href'));
  });
});

function placeIndicator(){
  var tabs=document.querySelector('.tabs');
  var indicator=tabs&&tabs.querySelector('.tab-indicator');
  if(!indicator) return;
  var active=tabs.querySelector('.tab.active');
  if(!active) return;
  if(!placeIndicator.ready){indicator.style.transition='none';}
  indicator.style.width=active.offsetWidth+'px';
  indicator.style.transform='translateX('+active.offsetLeft+'px)';
  if(!placeIndicator.ready){void indicator.offsetWidth;indicator.style.transition='';placeIndicator.ready=true;}
}

document.querySelectorAll('.tab').forEach(function(tab){
  tab.addEventListener('click',function(){
    document.querySelectorAll('.tab').forEach(function(t){t.classList.toggle('active',t===tab)});
    document.querySelectorAll('.panel').forEach(function(p){p.hidden=p.id!==tab.dataset.t});
    placeIndicator();
  });
});

placeIndicator();
window.addEventListener('load',placeIndicator);
window.addEventListener('resize',placeIndicator);
if(document.fonts&&document.fonts.ready){document.fonts.ready.then(placeIndicator)}

var subForm=document.getElementById('sub');
if(subForm){
  subForm.addEventListener('submit',function(e){
    e.preventDefault();
    var input=document.getElementById('sub-email');
    var msg=document.getElementById('sub-msg');
    var btn=document.getElementById('sub-btn');
    var email=input.value.trim();
    function show(text,state){msg.textContent=text;msg.className='news-msg '+state;}
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){
      show('Please enter a valid email address.','error');
      input.focus();
      return;
    }
    btn.disabled=true;
    btn.textContent='Subscribing...';
    fetch('api/subscribe.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:email})})
      .then(function(r){return r.text().then(function(t){var d=null;try{d=JSON.parse(t);}catch(err){}return {ok:r.ok,data:d};});})
      .then(function(res){
        if(!res.ok||!res.data||res.data.ok===false){show((res.data&&res.data.error)||'Something went wrong. Please try again.','error');return;}
        show('Subscribed! We will keep you posted at '+res.data.email+'.','success');
        input.value='';
      })
      .catch(function(){show('Could not reach the server. Please try again.','error');})
      .then(function(){btn.disabled=false;btn.textContent='Subscribe';});
  });
}
