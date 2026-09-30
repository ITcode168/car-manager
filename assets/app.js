const TOKEN = localStorage.getItem('token') || '';
const API = location.origin + '/api/';

// 强制登录：除了登录/注册/轮播图公开接口，其他页面没token就跳登录
(function(){
  const page = location.pathname.split('/').pop() || 'index.html';
  const publicPages = ['login.html','register.html'];
  if(!TOKEN && !publicPages.includes(page)){
    location.href = 'login.html';
  }
})();

// XSS转义
function esc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

async function api(path, opts={}) {
    opts.headers = { ...(opts.headers||{}), 'Authorization':'Bearer '+TOKEN };
    if (opts.body) {
        opts.method = opts.method || 'POST';
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(opts.body);
    }
    try {
        const r = await fetch(API+path, opts);
        const d = await r.json();
        if (d.code === 401) { location.href='login.html'; }
        if (d.code === 403) {
            localStorage.clear();
            location.href='login.html?msg='+encodeURIComponent(d.msg||'您的店铺已被停用');
        }
        return d;
    } catch(e) {
        return {code:0, msg:'网络错误'};
    }
}

function logout() { localStorage.clear(); location.href='login.html'; }

// 自定义确认弹窗（替代微信里的confirm）
function myConfirm(msg, onOk){
  var ov=document.createElement('div');
  ov.className='my-modal-overlay';
  ov.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;';
  var box=document.createElement('div');
  box.style.cssText='background:#fff;border-radius:16px;width:80%;max-width:320px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.2)';
  box.innerHTML='<div style="padding:28px 24px 20px;text-align:center;font-size:16px;color:#333;font-weight:500;line-height:1.5">'+msg+'</div>';
  var btns=document.createElement('div');
  btns.style.cssText='display:flex;border-top:1px solid #f0f0f0';
  var cancel=document.createElement('button');
  cancel.textContent='取消';
  cancel.style.cssText='flex:1;padding:15px;border:none;background:#fff;color:#666;font-size:15px;cursor:pointer';
  cancel.onclick=function(){document.body.removeChild(ov);};
  var ok=document.createElement('button');
  ok.textContent='确认删除';
  ok.style.cssText='flex:1;padding:15px;border:none;background:#fff;color:#d93030;font-size:15px;font-weight:600;cursor:pointer;border-left:1px solid #f0f0f0';
  ok.onclick=function(){document.body.removeChild(ov);onOk();};
  btns.appendChild(cancel);btns.appendChild(ok);
  box.appendChild(btns);
  ov.appendChild(box);
  document.body.appendChild(ov);
}

// 心跳检测：每10秒直接检查账号状态
setInterval(function(){
  if(!TOKEN) return;
  fetch(API+'settings.php?a=get',{headers:{'Authorization':'Bearer '+TOKEN}})
    .then(function(r){return r.json()})
    .then(function(d){
      if(d.code===403){
        localStorage.clear();
        location.href='login.html?msg='+encodeURIComponent(d.msg||'您的店铺已被停用');
      }
      if(d.role){localStorage.setItem('role',d.role);localStorage.setItem('myName',d.name||'');}
    })
    .catch(function(){});
},10000);

function checkPopup() {
    api('settings.php?a=announcements').then(d=>{
        if (!d.popup) return;
        const seen = localStorage.getItem('popup_'+d.popup.id);
        if (seen) return;
        document.body.insertAdjacentHTML('beforeend', `
          <div class="popup-mask">
            <div class="popup">
              <div class="popup-head">更新通知</div>
              <div class="popup-body">${esc(d.popup.content).replace(/\n/g,'<br>')}</div>
              <div class="popup-foot">
                <label style="font-size:13px;color:#666"><input type="checkbox" id="popupChk" checked> 24小时内不再提醒</label>
                <button class="btn" style="margin-top:10px" onclick="closePopup()">我知道了</button>
              </div>
            </div>
          </div>`);
    });
}
function closePopup() {
    const chk = document.getElementById('popupChk').checked;
    if (chk) {
        api('settings.php?a=announcements').then(d=>{
            if(d.popup) localStorage.setItem('popup_'+d.popup.id, Date.now());
        });
    }
    document.querySelector('.popup-mask').remove();
}
