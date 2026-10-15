import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const base=process.env.PAM_TEST_URL||'http://127.0.0.1:8000';
const credentials=fs.readFileSync('storage/app/demo-credentials.txt','utf8');
const email=credentials.match(/^admin: (.+)$/m)?.[1]?.trim();
const password=credentials.match(/^Password: (.+)$/m)?.[1]?.trim();
if(!email||!password)throw new Error('Jalankan DemoSeeder pada database demonstrasi dahulu.');
const folder=path.resolve('storage/app/qa');fs.mkdirSync(folder,{recursive:true});
const browser=await chromium.launch({headless:true,executablePath:process.env.PAM_CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
const context=await browser.newContext({viewport:{width:1440,height:1100},deviceScaleFactor:1,acceptDownloads:true});
const page=await context.newPage();const errors=[];
page.on('pageerror',e=>errors.push(e.message));
await page.goto(base+'/login');await page.screenshot({path:path.join(folder,'login-desktop.png'),fullPage:true});
await page.locator('[name=email]').fill(email);await page.locator('[name=password]').fill(password);
await Promise.all([page.waitForURL(base+'/'),page.locator('button[type=submit],form button').first().click()]);
await page.locator('#usage-chart').waitFor();await page.waitForTimeout(500);
await page.screenshot({path:path.join(folder,'dashboard-desktop.png'),fullPage:true});
const visited=[];
for(const route of ['/customers','/customers/create','/customers/1','/readings?customer=PAM10-000001','/readings?verify=1','/meters','/tariffs','/simulator?scheme_id=1&usage=41','/invoices','/payments?customer_id=1','/finance','/periods','/reports','/barcodes','/imports','/regions','/users','/roles','/settings','/audit']){
 const response=await page.goto(base+route);if(response.status()!==200)throw new Error(route+' status '+response.status());
 const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);if(overflow)throw new Error('Desktop overflow: '+route);
 visited.push(route);
}
await page.goto(base+'/readings');await page.locator('#scan-customer').fill('PAM10-000001');await Promise.all([page.waitForURL(/customer=PAM10-000001/),page.locator('#scan-form button').click()]);await page.locator('h2').filter({hasText:'Budi Santoso'}).first().waitFor();
await page.screenshot({path:path.join(folder,'meter-desktop.png'),fullPage:true});
for(const [url,file] of [['/reports/export?type=customers&format=xlsx','pelanggan.xlsx'],['/reports/export?type=invoices&format=pdf','tagihan-laporan.pdf'],['/barcodes/pdf?ids[]=1','label.pdf'],['/payments/1/receipt?pdf=1','kuitansi.pdf']]){
 const response=await context.request.get(base+url);if(!response.ok())throw new Error(file+' download '+response.status());const bytes=await response.body();if(bytes.length<500)throw new Error(file+' empty');fs.writeFileSync(path.join(folder,file),bytes);
}
const queued=await context.request.get(base+'/reports/export?type=customers&format=xlsx&async=1');
if(!queued.ok()||!queued.url().includes('/reports/exports/'))throw new Error('Queued export not created');
const token=new URL(queued.url()).pathname.split('/').pop();
execFileSync('php',['artisan','queue:work','--once','--stop-when-empty','--tries=1','--timeout=300'],{stdio:'pipe'});
const queuedDownload=await context.request.get(base+'/reports/exports/'+token+'/download');
if(!queuedDownload.ok())throw new Error('Real worker download status '+queuedDownload.status());
fs.writeFileSync(path.join(folder,'laporan-worker.xlsx'),await queuedDownload.body());
await page.setViewportSize({width:390,height:844});
for(const route of ['/','/readings?customer=PAM10-000001','/customers','/payments?customer_id=1','/finance','/reports','/settings']){
 const response=await page.goto(base+route);if(response.status()!==200)throw new Error('Mobile '+route+' status '+response.status());
 const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);if(overflow)throw new Error('Mobile overflow: '+route);
 if(route==='/')await page.screenshot({path:path.join(folder,'dashboard-mobile.png'),fullPage:true});
 if(route.startsWith('/readings'))await page.screenshot({path:path.join(folder,'meter-mobile.png'),fullPage:true});
}
await page.locator('#menu-toggle').click();if(!await page.locator('#sidebar').evaluate(e=>e.classList.contains('open')))throw new Error('Mobile menu not open');
if(errors.length)throw new Error('Browser errors: '+errors.join('; '));
console.log(JSON.stringify({desktopPages:visited.length,mobilePages:7,downloads:5,realQueueWorker:true,browserErrors:errors.length,screenshots:folder}));
await browser.close();
