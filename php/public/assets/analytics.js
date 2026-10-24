(() => {
  const source=document.currentScript;
  const id=source?.dataset.analyticsId;
  if(!/^G-[A-Z0-9]{6,20}$/.test(id||'')) return;
  const banner=document.getElementById('analytics-choice');
  const key='bryce5-analytics-choice-v1';
  const privacySignal=navigator.globalPrivacyControl===true || navigator.doNotTrack==='1';
  let started=false;
  const read=()=>{try{return localStorage.getItem(key);}catch{return null;}};
  const save=value=>{try{localStorage.setItem(key,value);}catch{/* Choice still applies for this page. */}};
  function clearCookies(){
    for(const part of document.cookie.split(';')){
      const name=part.split('=')[0].trim();
      if(!/^_ga(?:_|$)/.test(name))continue;
      for(const domain of ['',location.hostname,'.'+location.hostname])document.cookie=name+'=; Max-Age=0; Path=/; SameSite=Lax; Secure'+(domain ? '; Domain='+domain : '');
    }
  }
  function start(){
    if(started || privacySignal)return;
    started=true;
    window.dataLayer=window.dataLayer||[];
    window.gtag=function(){window.dataLayer.push(arguments);};
    window.gtag('consent','default',{analytics_storage:'granted',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});
    window.gtag('js',new Date());
    // Deliberately omit query strings, fragments, artwork titles and account data.
    let referrer='';try{if(document.referrer)referrer=new URL(document.referrer).origin+'/';}catch{/* Invalid referrers are omitted. */}
    const fields={page_location:location.origin+location.pathname,page_referrer:referrer,page_title:source.dataset.pageTitle,allow_google_signals:false,allow_ad_personalization_signals:false};
    window.gtag('set',fields);
    window.gtag('config',id,{...fields,send_page_view:true,cookie_flags:'SameSite=Lax;Secure'});
    const script=document.createElement('script');script.async=true;
    script.src='https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(id);
    document.head.append(script);
  }
  function choose(allow){
    save(allow ? 'allow' : 'decline');banner.hidden=true;
    if(allow)start();
    else {
      clearCookies();
      if(started){window.gtag('consent','update',{analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});location.reload();}
    }
  }
  document.getElementById('analytics-allow')?.addEventListener('click',()=>choose(true));
  document.getElementById('analytics-decline')?.addEventListener('click',()=>choose(false));
  document.querySelectorAll('[data-analytics-preferences]').forEach(button=>button.addEventListener('click',()=>{
    banner.hidden=false;document.getElementById('analytics-decline')?.focus();
  }));
  if(privacySignal){save('decline');clearCookies();document.getElementById('analytics-allow').disabled=true;}
  else if(read()==='allow')start();
  else if(read()!=='decline')banner.hidden=false;
})();
