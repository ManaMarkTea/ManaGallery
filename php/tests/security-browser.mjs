// Real WebAuthn signatures from Chromium's virtual authenticator; no external mail.
import {createRequire} from 'node:module';
import {spawn,execFileSync} from 'node:child_process';
import {once} from 'node:events';
import {cpSync,mkdirSync,mkdtempSync,readFileSync,writeFileSync} from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const require=createRequire(path.join(root,'package.json'));
const {chromium}=require('@playwright/test');
const php=process.env.PHP_BINARY || (process.platform==='win32'?path.join(root,'.tools/php/php.exe'):'php');
mkdirSync(path.join(root,'test-results'),{recursive:true});
const fixture=mkdtempSync(path.join(root,'test-results/security-browser-'));
for(const name of ['src','templates','public','scripts','vendor','router.php','config.example.php'])cpSync(path.join(root,name),path.join(fixture,name),{recursive:true});
writeFileSync(path.join(fixture,'config.local.php'),"<?php return ['notification_mail_enabled'=>true,'notifications_paused'=>true,'notification_origin'=>'https://gallery.example.com'];");
const storage=path.join(fixture,'storage');
const env={...process.env,RENDERGALLERY_PHP_DATA_DIR:storage,RENDERGALLERY_PHP_CATALOGUE:path.join(storage,'gallery.json')};
execFileSync(php,['scripts/initialize.php'],{cwd:fixture,env});
writeFileSync(path.join(fixture,'fixture-query.php'),`<?php
require __DIR__.'/src/gallery.php'; require __DIR__.'/src/database.php';
$input=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
echo json_encode(query($input['sql'],$input['args'])->fetchAll(),JSON_THROW_ON_ERROR);
`);
const sql=(text,args=[])=>JSON.parse(execFileSync(php,['fixture-query.php',JSON.stringify({sql:text,args})],{cwd:fixture,env,encoding:'utf8'}));
let logs='';
const server=spawn(php,['-S','127.0.0.1:8083','-t','public','router.php'],{cwd:fixture,env,stdio:['ignore','pipe','pipe']});
server.stderr.on('data',chunk=>{logs+=chunk;});
let browser;
const password='fictional secure account password';
try {
  for(let i=0;i<80;i++){
    try{const response=await fetch('http://localhost:8083/');if(response.ok)break;}catch{}
    await new Promise(resolve=>setTimeout(resolve,100));
  }
  browser=await chromium.launch({headless:true});
  const context=await browser.newContext({baseURL:'http://localhost:8083'});
  const page=await context.newPage();
  page.setDefaultTimeout(15000);
  const cdp=await context.newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  const {authenticatorId}=await cdp.send('WebAuthn.addVirtualAuthenticator',{options:{protocol:'ctap2',transport:'internal',hasResidentKey:true,hasUserVerification:true,isUserVerified:true,automaticPresenceSimulation:true}});
  const csrf=()=>page.locator('input[name=csrf]').first().inputValue();
  const register=async(name,email)=>{
    await page.goto('/register');
    await page.getByLabel('Username',{exact:true}).fill(name);
    await page.getByLabel('Password',{exact:true}).fill(password);
    if(email)await page.getByLabel('Email address',{exact:true}).fill(email);
    await page.getByRole('button',{name:'Create account',exact:true}).click();
    await page.waitForURL('**/account');
  };
  await register('existing_member');
  assert.equal((await page.request.post('/passkeys/register/options',{form:{current_password:password}})).status(),403);
  assert.equal((await page.request.post('/passkeys/register/options',{form:{csrf:await csrf(),current_password:'wrong'}})).status(),422);
  const enrollment=page.locator('form[data-passkey=register]');
  await enrollment.getByLabel('Passkey name').fill('Test device');
  await enrollment.getByLabel('Current password').fill(password);
  const finishRequest=page.waitForRequest('**/passkeys/register/finish');
  await enrollment.getByRole('button',{name:'Add passkey',exact:true}).click();
  const enrolled=await finishRequest;
  await page.waitForURL('**/account#passkeys');
  await page.getByText('Test device · Added',{exact:false}).waitFor();
  assert.equal(sql('SELECT count(*) AS total FROM passkeys')[0].total,1);
  const replay=Object.fromEntries(new URLSearchParams(enrolled.postData()));
  assert.equal((await page.request.post('/passkeys/register/finish',{form:replay})).status(),422);
  sql("INSERT INTO settings(key,value) VALUES ('signupEmailVerification','true')");
  await page.getByRole('button',{name:'Sign out',exact:true}).click();
  await page.goto('/login');
  // Ask the browser to produce an assertion, optionally corrupting selected fields.
  const assertion=()=>page.evaluate(async()=>{
    const csrf=document.querySelector('input[name=csrf]').value;
    const options=await fetch('/passkeys/login/options',{method:'POST',body:new URLSearchParams({csrf})}).then(r=>r.json());
    const decode=s=>Uint8Array.from(atob(s.replace(/-/g,'+').replace(/_/g,'/')),c=>c.charCodeAt(0));
    const encode=b=>btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
    options.options.publicKey.challenge=decode(options.options.publicKey.challenge);
    const credential=await navigator.credentials.get(options.options);
    return {csrf,id:encode(credential.rawId),clientData:encode(credential.response.clientDataJSON),authenticatorData:encode(credential.response.authenticatorData),signature:encode(credential.response.signature),userHandle:encode(credential.response.userHandle)};
  });
  for(const override of [{isBogusSignature:true},{isBadUV:true},{isBadUP:true}]){
    await cdp.send('WebAuthn.setResponseOverrideBits',{authenticatorId,...override});
    const forged=await assertion();
    assert.equal((await page.request.post('/passkeys/login/finish',{form:forged})).status(),422);
  }
  await cdp.send('WebAuthn.setResponseOverrideBits',{authenticatorId});
  const wrongHandle=await assertion();wrongHandle.userHandle='incorrect';
  assert.equal((await page.request.post('/passkeys/login/finish',{form:wrongHandle})).status(),422);
  const wrongOrigin=await assertion();
  const client=JSON.parse(Buffer.from(wrongOrigin.clientData,'base64url').toString());
  client.origin='http://other.localhost:8083';wrongOrigin.clientData=Buffer.from(JSON.stringify(client)).toString('base64url');
  assert.equal((await page.request.post('/passkeys/login/finish',{form:wrongOrigin})).status(),422);
  // A failed assertion consumes its challenge.
  assert.equal((await page.request.post('/passkeys/login/finish',{form:wrongOrigin})).status(),422);
  await page.getByRole('button',{name:'Sign in with a passkey',exact:true}).click();
  await page.waitForURL('**/account');
  assert(await page.getByRole('heading',{name:'Hello, existing_member',exact:true}).isVisible());
  // Grandfathered member still works after confirmation is enabled.
  assert.equal((await page.request.get('/upload',{maxRedirects:0})).status(),200);
  await page.getByRole('button',{name:'Sign out',exact:true}).click();
  await register('new_member','new@example.invalid');
  assert(await page.getByRole('heading',{name:'Confirm your email',exact:true}).isVisible());
  assert.equal((await page.request.get('/upload',{maxRedirects:0})).status(),303);
  assert((await page.request.post('/favorite',{form:{csrf:await csrf(),artwork:'1'}})).status() >= 400);
  assert.equal((await page.request.post('/passkeys/register/options',{form:{csrf:await csrf(),current_password:password}})).status(),422);
  let job=sql("SELECT * FROM notification_queue WHERE kind='signup-confirmation' AND state='pending'")[0];
  let link=`/verify-email?account=${job.identity}&token=${job.payload}`;
  await page.goto(link);
  assert.equal(sql('SELECT verified FROM account_emails WHERE user_id=?',[Number(job.identity)])[0].verified,0);
  assert.equal((await page.request.post('/verify-email',{form:{account:job.identity,token:job.payload}})).status(),403);
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Confirm email address',exact:true}).click()]);
  assert((await page.locator('body').innerText()).includes('Email confirmed.'),await page.locator('body').innerText());
  assert.equal((await page.request.get('/upload',{maxRedirects:0})).status(),200);
  await page.goto(link);await page.getByRole('button',{name:'Confirm email address',exact:true}).click();
  await page.getByRole('status').filter({hasText:'expired or was already used'}).waitFor();
  await page.getByRole('link',{name:'Your account',exact:false}).click();
  await page.getByRole('button',{name:'Sign out',exact:true}).click();
  await page.goto('/login');
  sql("UPDATE users SET disabled=1 WHERE username_key='existing_member'");
  const disabled=await assertion();
  assert.equal((await page.request.post('/passkeys/login/finish',{form:disabled})).status(),422);
  sql("UPDATE users SET disabled=0 WHERE username_key='existing_member'");
  await page.getByRole('button',{name:'Sign in with a passkey',exact:true}).click();
  await page.waitForURL('**/account');
  const keyId=sql('SELECT id FROM passkeys')[0].id;
  assert.equal((await page.request.post('/passkeys/delete',{form:{csrf:await csrf(),id:String(keyId),current_password:'wrong'}})).status(),422);
  assert.equal((await page.request.post('/passkeys/delete',{form:{csrf:await csrf(),id:String(keyId),current_password:password}})).status(),200);
  assert.equal(sql('SELECT count(*) AS total FROM passkeys')[0].total,0);
  console.log('Passed real passkey enrollment/sign-in, replay, signature, UV/UP, origin, account ownership, disabled account, password reauthentication and signup email confirmation browser checks.');
}catch(error){
  console.error(logs.slice(-4000));
  const log=path.join(storage,'error_log');
  try{console.error(readFileSync(log,'utf8').slice(-4000));}catch{}
  throw error;
}finally{
  if(browser)await browser.close();
  if(server.exitCode===null){const stopped=once(server,'exit');server.kill();await stopped;}
}
