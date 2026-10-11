document.querySelectorAll('.tab').forEach(function(tab){
  tab.addEventListener('click',function(){
    document.querySelectorAll('.tab').forEach(function(t){t.classList.toggle('active',t===tab)});
    document.querySelectorAll('.panel').forEach(function(p){p.hidden=p.id!==tab.dataset.t});
  });
});
document.getElementById('sub').addEventListener('submit',function(e){e.preventDefault();e.target.reset()});
