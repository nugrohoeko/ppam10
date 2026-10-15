import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import { randomBytes, randomUUID } from 'node:crypto';
import { execFileSync, spawn } from 'node:child_process';

// All data, credentials, and queued jobs belong to an isolated SQLite fixture.
// No worker runs: confirming a message exercises the durable database queue only.
const root=path.resolve('.');
const fixtureDir=path.join(root,'storage/framework/testing');fs.mkdirSync(fixtureDir,{recursive:true});
const fixture=path.join(fixtureDir,'whatsapp-ui-'+randomUUID()+'.sqlite');
fs.writeFileSync(fixture,'',{flag:'wx'});
const password=randomBytes(24).toString('base64url');
const env={...process.env,APP_ENV:'testing',DB_CONNECTION:'sqlite',DB_DATABASE:fixture.replaceAll('\\','/'),DB_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'database',QUEUE_CONNECTION:'database',DEMO_PASSWORD:password,APP_CONFIG_CACHE:fixture+'-config.php',APP_ROUTES_CACHE:fixture+'-routes.php'};
const options={cwd:root,env,stdio:'pipe',windowsHide:true};
const fixturePhp=body=>execFileSync('php',['-r',String.raw`
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing') || config('database.default') !== 'sqlite' || !str_contains(config('database.connections.sqlite.database'), 'whatsapp-ui-') || config('queue.default') !== 'database') {throw new RuntimeException('Unsafe UI fixture environment');}
`+body],options).toString();
let browser,server;
try {
    execFileSync('php',['artisan','migrate','--seed','--force'],options);
    execFileSync('php',['artisan','db:seed','--class=DemoSeeder','--force'],options);
    const selected=JSON.parse(fixturePhp(String.raw`
$customer = App\Models\Customer::whereHas('invoices', fn($q) => $q->whereIn('status', ['issued', 'partial'])->whereColumn('total','>','paid_amount'))->firstOrFail();
$body = 'Yth. {nama_pelanggan}, pelanggan {nama_pam} dengan ID {id_pelanggan}, RT {rt}.'."\n\n".'Rincian: {rincian_tunggakan}'."\n\n".'Total: {total_tunggakan}. Pembayaran: {rekening_pembayaran}. Kontak: {kontak_bendahara}.';
$template = App\Models\WhatsAppTemplate::create(['name'=>'Pengingat UI fixture','provider_name'=>'ui_fixture_reminder','language'=>'id','body'=>$body,'variables'=>app(App\Services\WhatsApp\TemplateService::class)->variables($body),'active'=>true,'status'=>'DRAFT']);
echo json_encode(['customer_id'=>$customer->id,'customer_name'=>$customer->name,'template_id'=>$template->id]);
`));
    const socket=net.createServer();await new Promise(resolve=>socket.listen(0,'127.0.0.1',resolve));
    const port=socket.address().port;await new Promise(resolve=>socket.close(resolve));
    const base='http://127.0.0.1:'+port;
    server=spawn('php',['-S','127.0.0.1:'+port,path.join(root,'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],{cwd:path.join(root,'public'),env:{...env,APP_ENV:'local'},stdio:'ignore',windowsHide:true});
    let ready=false;
    for(let attempt=0;attempt<40;attempt++){
        try {if((await fetch(base+'/login',{signal:AbortSignal.timeout(1000)})).ok){ready=true;break}}catch{}
        await new Promise(resolve=>setTimeout(resolve,100));
    }
    if(!ready)throw new Error('Server uji tidak siap.');
    browser=await chromium.launch({headless:true,executablePath:process.env.PAM_CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
    const page=await browser.newPage({viewport:{width:1440,height:1000}});
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto(base+'/login');await page.locator('[name=email]').fill('admin@pam10.test');await page.locator('[name=password]').fill(password);
    await Promise.all([page.waitForURL(base+'/'),page.locator('form button').first().click()]);
    const openSelection=async()=>{
        const response=await page.goto(base+'/whatsapp?customer_id='+selected.customer_id);
        if(response.status()!==200)throw new Error('Pemilihan penerima status '+response.status());
        await page.locator('[name=template_id]').selectOption(String(selected.template_id));
        if(!await page.locator('.whatsapp-recipient').isChecked())throw new Error('Penerima individual belum terpilih.');
    };
    await openSelection();
    if(!await page.getByText('Belum Terhubung.',{exact:true}).count())throw new Error('Status belum terhubung tidak tampil.');
    await page.getByRole('button',{name:'Pratinjau pelanggan terpilih'}).click();
    await page.getByRole('heading',{name:'Pratinjau pengingat WhatsApp'}).waitFor();
    if(!await page.getByRole('button',{name:'Konfirmasi 0 pengingat ke antrean'}).isDisabled())throw new Error('Pengiriman belum terhubung tidak diblokir.');
    fixturePhp(String.raw`
$customer = App\Models\Customer::findOrFail(${selected.customer_id});
$customer->update(['whatsapp_consent_at'=>now(),'whatsapp_consent_source'=>'Persetujuan fixture UI','whatsapp_consent_phone'=>App\Support\PhoneNumber::normalize($customer->phone),'whatsapp_consent_by'=>App\Models\User::where('email','admin@pam10.test')->value('id')]);
App\Models\WhatsAppSetting::current()->update(['phone_number_id'=>'123456','waba_id'=>'654321','access_token'=>'fixture-only-no-worker','app_secret'=>'fixture-only','verify_token'=>'fixture-only','connected_at'=>now(),'payment_account'=>'Bank PAM 1234','treasurer_contact'=>'081234567890']);
$template=App\Models\WhatsAppTemplate::findOrFail(${selected.template_id});
$template->update(['status'=>'APPROVED','provider_template_id'=>'777777','synced_body_hash'=>app(App\Services\WhatsApp\TemplateService::class)->bodyHash($template),'last_synced_at'=>now()]);
`);
    const folder=path.join(root,'storage/app/qa');fs.mkdirSync(folder,{recursive:true});
    const screenshot=async(label)=>{
        for(const [width,height,size] of [[1440,1000,'desktop'],[390,844,'mobile']]){
            await page.setViewportSize({width,height});
            await page.waitForFunction(()=>{
                const sidebar=document.querySelector('#sidebar');
                return innerWidth<=760 ? sidebar.getBoundingClientRect().right<=0.5 : sidebar.getBoundingClientRect().left===0;
            });
            if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))throw new Error('Halaman '+label+' melebihi layar '+size);
            await page.screenshot({path:path.join(folder,'whatsapp-'+label+'-'+size+'.png'),fullPage:false});
        }
    };
    await openSelection();await screenshot('selection');
    await page.getByRole('button',{name:'Pratinjau pelanggan terpilih'}).click();
    await page.getByRole('heading',{name:'Pratinjau pengingat WhatsApp'}).waitFor();
    const confirm=page.getByRole('button',{name:'Konfirmasi 1 pengingat ke antrean'});
    if(await confirm.isDisabled())throw new Error('Penerima valid ditolak.');
    await screenshot('preview');
    const batchUuid=await page.locator('[name=batch_uuid]').inputValue();
    await page.locator('[name=confirm]').check();await confirm.click();await page.waitForURL(base+'/whatsapp/history');
    await page.getByText('Dalam antrean',{exact:true}).last().waitFor();
    await screenshot('history');
    const token=await page.locator('meta[name=csrf-token]').getAttribute('content');
    const duplicate=await page.request.post(base+'/whatsapp/send',{form:{_token:token,batch_uuid:batchUuid,confirm:'1'}});
    if(duplicate.status()!==200)throw new Error('Konfirmasi ulang tidak idempoten.');
    const counts=JSON.parse(fixturePhp('echo json_encode(["messages"=>App\\Models\\WhatsAppReminder::count(),"jobs"=>Illuminate\\Support\\Facades\\DB::table("jobs")->count()]);'));
    if(counts.messages!==1||counts.jobs!==1)throw new Error('Antrean ganda: '+JSON.stringify(counts));
    for(const [url,label] of [['/whatsapp/settings','settings'],['/whatsapp/templates','templates']]){
        const response=await page.goto(base+url);if(response.status()!==200)throw new Error(label+' status '+response.status());await screenshot(label);
    }
    if(errors.length)throw new Error(errors.join('; '));
    console.log(JSON.stringify({disconnectedBlocked:true,individualSelected:true,preview:true,confirmedQueued:true,duplicateBlocked:true,viewports:['desktop','mobile'],settings:true,templates:true,pageErrors:0,isolatedDatabase:true,providerRequests:0}));
} finally {
    await browser?.close();
    if(server && server.exitCode===null){server.kill();await new Promise(resolve=>server.once('exit',resolve))}
    for(const suffix of ['','-journal','-wal','-shm']){const file=fixture+suffix;if(fs.existsSync(file))fs.unlinkSync(file)}
}
