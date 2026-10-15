import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import { randomBytes, randomUUID } from 'node:crypto';
import { execFileSync, spawn } from 'node:child_process';

// Isolated SQLite fixture and a private PHP server; no live customers or credentials.
const root=path.resolve('.');
const fixtureDir=path.join(root,'storage/framework/testing');fs.mkdirSync(fixtureDir,{recursive:true});
const fixture=path.join(fixtureDir,'customer-ui-'+randomUUID()+'.sqlite');
fs.writeFileSync(fixture,'',{flag:'wx'});
const password=randomBytes(24).toString('base64url');
const env={...process.env,APP_ENV:'testing',DB_CONNECTION:'sqlite',DB_DATABASE:fixture.replaceAll('\\','/'),DB_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'database',QUEUE_CONNECTION:'sync',DEMO_PASSWORD:password,APP_CONFIG_CACHE:fixture+'-config.php',APP_ROUTES_CACHE:fixture+'-routes.php'};
let browser,server;
try {
    const options={cwd:root,env,stdio:'pipe',windowsHide:true};
    execFileSync('php',['artisan','migrate','--seed','--force'],options);
    execFileSync('php',['artisan','db:seed','--class=DemoSeeder','--force'],options);
    const socket=net.createServer();await new Promise(resolve=>socket.listen(0,'127.0.0.1',resolve));
    const port=socket.address().port;await new Promise(resolve=>socket.close(resolve));
    const base='http://127.0.0.1:'+port;
    server=spawn('php',['-S','127.0.0.1:'+port,path.join(root,'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],{cwd:path.join(root,'public'),env:{...env,APP_ENV:'local'},stdio:'ignore',windowsHide:true});
    let ready=false;
    for(let attempt=0;attempt<40;attempt++){
        try {const response=await fetch(base+'/login',{signal:AbortSignal.timeout(1000)});if(response.ok){ready=true;break}}catch{}
        await new Promise(resolve=>setTimeout(resolve,100));
    }
    if(!ready)throw new Error('Server uji tidak siap.');
    browser=await chromium.launch({headless:true,executablePath:process.env.PAM_CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
    const page=await browser.newPage({viewport:{width:1440,height:1000}});
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto(base+'/login');
    await page.locator('[name=email]').fill('admin@pam10.test');await page.locator('[name=password]').fill(password);
    await Promise.all([page.waitForURL(base+'/'),page.locator('form button').first().click()]);
    const customerPage=await page.goto(base+'/customers');
    if(customerPage.status()!==200)throw new Error('Halaman pelanggan status '+customerPage.status());
    const add=page.locator('a[href="'+base+'/customers/create"]');await add.waitFor();
    await add.click();await page.locator('[name=initial_reading]').waitFor();
    const folder=path.join(root,'storage/app/qa');fs.mkdirSync(folder,{recursive:true});
    for(const [width,height,label] of [[1440,1000,'desktop'],[390,844,'mobile']]){
        await page.setViewportSize({width,height});await page.goto(base+'/customers');
        const opener=page.locator('button[data-bs-target^="#delete-customer-"]').first();
        const target=await opener.getAttribute('data-bs-target');
        const row=opener.locator('xpath=ancestor::tr');
        const number=(await row.locator('td').nth(0).innerText()).trim();
        const name=(await row.locator('td').nth(1).locator('strong').innerText()).trim();
        await opener.click();const dialog=page.locator(target);await dialog.waitFor({state:'visible'});
        await page.waitForFunction(selector=>{const modal=document.querySelector(selector);return getComputedStyle(modal).opacity==='1'&&getComputedStyle(modal.querySelector('.modal-dialog')).transform==='none'},target);
        const text=await dialog.innerText();if(!text.includes(number)||!text.includes(name))throw new Error('Identitas konfirmasi tidak lengkap.');
        const form=dialog.locator('form');if(await form.locator('[name=_method]').inputValue()!=='DELETE')throw new Error('Metode penghapusan salah.');
        if(await form.locator('[name=reason]').getAttribute('required')===null)throw new Error('Alasan tidak diwajibkan.');
        const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);if(overflow)throw new Error('Dialog melebihi layar '+label);
        await page.screenshot({path:path.join(folder,'customer-delete-'+label+'.png'),fullPage:false});
        await dialog.getByRole('button',{name:'Batal',exact:true}).click();await dialog.waitFor({state:'hidden'});
    }
    await page.goto(base+'/roles');
    if(await page.locator('input[value="pelanggan.create"]').count()!==4||await page.locator('input[value="pelanggan.delete"]').count()!==4)throw new Error('Permission baru tidak tersedia pada seluruh role.');
    if(await page.locator('input[value="customers.create"]').count())throw new Error('Permission lama masih ditawarkan.');
    if(errors.length)throw new Error(errors.join('; '));
    console.log(JSON.stringify({createForm:true,confirmationIdentity:true,viewports:['desktop','mobile'],rolePermissions:true,pageErrors:errors.length,isolatedDatabase:true}));
} finally {
    await browser?.close();
    if(server && server.exitCode===null){server.kill();await new Promise(resolve=>server.once('exit',resolve))}
    for(const suffix of ['','-journal','-wal','-shm']){const file=fixture+suffix;if(fs.existsSync(file))fs.unlinkSync(file)}
}
