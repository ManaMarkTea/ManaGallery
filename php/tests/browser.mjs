// New-owner journey: no migration tools, source database or imported accounts.
import { createRequire } from 'node:module';
import { spawn, execFileSync } from 'node:child_process';
import { once } from 'node:events';
import { mkdirSync, mkdtempSync, readFileSync } from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const require=createRequire(path.join(root,'package.json'));
const { chromium }=require('@playwright/test');
const php=process.env.PHP_BINARY || (process.platform==='win32' ? path.join(root,'.tools/php/php.exe') : 'php');
const output=path.join(root,'test-results');mkdirSync(output,{recursive:true});
const storage=mkdtempSync(path.join(output,'fresh-'));
const env={...process.env,RENDERGALLERY_PHP_DATA_DIR:storage,RENDERGALLERY_PHP_CATALOGUE:path.join(storage,'gallery.json')};
execFileSync(php,['scripts/initialize.php'],{cwd:root,env});
const initial=readFileSync(env.RENDERGALLERY_PHP_CATALOGUE,'utf8');
assert.equal(JSON.parse(initial).items.length,0);
assert.throws(()=>execFileSync(php,['scripts/initialize.php'],{cwd:root,env,stdio:'pipe'}));
assert.equal(readFileSync(env.RENDERGALLERY_PHP_CATALOGUE,'utf8'),initial);
let logs='';
const server=spawn(php,['-S','127.0.0.1:8082','-t','public','router.php'],{cwd:root,env,stdio:['ignore','pipe','pipe']});
server.stderr.on('data',chunk=>{logs+=chunk;});
let browser;
try{
  for(let i=0;i<80;i++){
    try{const response=await fetch('http://127.0.0.1:8082/');if(response.ok){await response.text();break;}}catch{}
    await new Promise(resolve=>setTimeout(resolve,100));
  }
  browser=await chromium.launch({headless:true});
  const context=await browser.newContext({baseURL:'http://127.0.0.1:8082'});
  const page=await context.newPage();
  await page.goto('/');assert.equal(await page.locator('.art-card').count(),0);
  assert(await page.getByRole('link',{name:'ManaGallery home',exact:true}).isVisible());
  await page.goto('/about');
  assert.equal(await page.getByRole('heading',{name:'About ManaGallery',exact:true}).count(),1);
  assert(!(await page.locator('body').innerText()).includes('RenderGallery'));
  for(const route of ['/storage/gallery.json','/config.local.php','/scripts/initialize.php'])assert.equal((await page.request.get(route)).status(),404);
  const register=async(target,name)=>{
    await target.goto('/register');
    await target.getByLabel('Username',{exact:true}).fill(name);
    await target.getByLabel('Password',{exact:true}).fill('fictional fresh gallery password');
    await target.getByRole('button',{name:'Create account',exact:true}).click();
    await target.waitForURL('**/account');
  };
  await register(page,'gallery_owner');
  assert.equal((await page.request.get('/admin')).status(),403);
  execFileSync(php,['scripts/promote-admin.php','gallery_owner'],{cwd:root,env});
  await page.goto('/admin');assert.equal((await page.request.get('/admin')).status(),200);
  await page.goto('/admin/settings');
  assert.equal(await page.getByLabel('Site name',{exact:true}).inputValue(),'ManaGallery');
  assert.equal(await page.getByLabel('Site description',{exact:true}).inputValue(),'A community art gallery.');
  await page.getByLabel('Site name',{exact:true}).fill('My new gallery');
  await page.getByRole('button',{name:'Save settings',exact:true}).click();
  await page.reload();
  assert.equal(await page.getByLabel('Site name',{exact:true}).inputValue(),'My new gallery');
  assert(await page.getByRole('link',{name:'My new gallery home',exact:true}).isVisible());
  const memberContext=await browser.newContext({baseURL:'http://127.0.0.1:8082'});
  const member=await memberContext.newPage();await register(member,'gallery_member');
  assert.equal((await member.request.post('/upload',{form:{title:'forged'}})).status(),403);
  const png=execFileSync(php,['-r',"$i=imagecreatetruecolor(24,24);imagepng($i);"]);
  await member.goto('/upload');
  await member.getByLabel('Title',{exact:true}).fill('My first artwork');
  await member.getByLabel('Category',{exact:true}).selectOption('1');
  await member.getByLabel('Artwork image',{exact:true}).setInputFiles({name:'art.png',mimeType:'image/png',buffer:png});
  await member.getByRole('button',{name:'Submit for review',exact:true}).click();
  await member.waitForURL(/\/art\/[0-9]+$/);const artwork=member.url();
  const guest=await browser.newContext({baseURL:'http://127.0.0.1:8082'});
  assert.equal((await guest.request.get(artwork)).status(),404);
  await page.goto('/admin');
  await page.getByRole('button',{name:'Approve artwork',exact:true}).first().click();
  assert.equal((await guest.request.get(artwork)).status(),200);
  await member.goto(artwork);
  await member.getByRole('button',{name:'Save to favorites',exact:false}).click();
  await member.waitForURL(artwork);
  assert(await member.getByRole('button',{name:'Remove favorite',exact:false}).isVisible());
  await page.goto('/');assert.equal(await page.locator('.art-card').count(),1);
  for(const width of [1440,390]){
    await page.setViewportSize({width,height:900});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    await page.screenshot({path:path.join(output,`fresh-gallery-${width}.png`)});
  }
  await guest.close();await memberContext.close();await context.close();
  console.log('Passed fresh initialization, no-overwrite, registration, admin setup, settings, upload approval/privacy, CSRF, favorites and responsive gallery checks.');
}catch(error){console.error(logs.slice(-6000));throw error;}
finally{
  if(browser)await browser.close();
  if(server.exitCode===null){const stopped=once(server,'exit');server.kill();await stopped;}
}
