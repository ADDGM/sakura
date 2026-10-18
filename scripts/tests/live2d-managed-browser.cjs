// Optional real browser checks; only loopback HTTP fixtures are used.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const catalog = JSON.parse(read('live2d/costumes/pio/catalog.json'));
const messages = Object.fromEntries([...read('inc/live2d-costumes.php').matchAll(/'([A-Za-z]+)' => __\('([^']+)', 'sakura'\)/g)].map(m => [m[1], m[2]]));
const widget = read('footer.php').match(/<div id="landlord"[\s\S]+?\r?\n\t<\/div>/)[0].replace(/<\?php[\s\S]*?\?>/g, 'Live2D');
const field = execFileSync(process.env.PHP_EXECUTABLE || 'php', ['-r',
    'function __($s,$d){return $s;} function esc_html($s){return htmlspecialchars($s, ENT_QUOTES);} function add_action(){} require "inc/live2d-costumes.php"; echo sakura_live2d_costume_field();'
], {cwd:root, encoding:'utf8'});
const screenshotDir = process.env.LIVE2D_SCREENSHOT_DIR;
if (screenshotDir) fs.mkdirSync(screenshotDir, {recursive:true});
let calls = [], installed = new Set(), partial = new Map(), refreshing = false, fail = false, failFinish = false, delay = 0;
const oldVersion = 'a'.repeat(64), keptVersion = 'b'.repeat(64);
let historyDeleted = false, failHistory = false;
const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/ajax') {
        let body = ''; for await (const chunk of req) body += chunk;
        const data = Object.fromEntries(new URLSearchParams(body)); calls.push(data);
        assert.equal(data.action, 'sakura_live2d_resources'); assert.equal(data.model, 'pio'); assert.equal(data.nonce, 'fixture');
        if (delay) await new Promise(resolve => setTimeout(resolve, delay));
        if (data.mode.startsWith('history')) {
            res.setHeader('Content-Type', 'application/json');
            if (failHistory && data.mode === 'history-delete') {
                res.writeHead(400).end(JSON.stringify({success:false,data:{message:'Version changed. Preview again.'}})); return;
            }
            if (data.mode === 'history-delete') {
                assert.equal(data.version, oldVersion); assert.equal(data.ticket, 'preview-ticket'); historyDeleted = true;
            }
            res.end(JSON.stringify({success:true,data:data.mode === 'history' ? {versions:[
                {version:catalog.packVersion,state:'current',files:106,bytes:50456424},
                ...(!historyDeleted ? [{version:oldVersion,state:'available',files:100,bytes:45000000}] : []),
                {version:keptVersion,state:'blocked',files:null,bytes:null}
            ],skipped:0} : {version:data.version,files:100,bytes:45000000,ticket:'preview-ticket'}})); return;
        }
        if ((fail && data.mode === 'download') || (failFinish && data.mode === 'finish')) {res.writeHead(400, {'Content-Type': 'application/json'}).end(JSON.stringify({success:false,data:{message:'Fixture failure'}})); return;}
        if (data.mode === 'restart' && !refreshing) {installed.clear();partial.clear();refreshing=true;}
        if (data.mode === 'finish') refreshing=false;
        if (data.mode === 'clear') {partial.clear();refreshing=false;}
        let part;
        if (data.mode === 'download') {
            const index=Number(data.index),bytes=(partial.get(index)||0)+262144;
            part={complete:bytes===524288,bytes,size:524288};partial.set(index,bytes);
            if (part.complete) {installed.add(index);partial.delete(index);}
        }
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({success: true, data: data.mode === 'download' ? part : {
            total: 3, missing: [0,1,2].filter(i => !installed.has(i)), parts:Object.fromEntries(partial),
            bytes:installed.size*524288+[...partial.values()].reduce((a,b)=>a+b,0), size:3*524288,
            refresh:refreshing, ready: data.mode === 'finish'}})); return;
    }
    if (url.pathname === '/admin') {
        res.setHeader('Content-Type','text/html; charset=utf-8');
        res.end('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" href="data:,">' +
            '<link rel="stylesheet" href="/inc/css/optionsframework.css"><link rel="stylesheet" href="/inc/css/dash-scheme.css"><link rel="stylesheet" href="/inc/css/live2d-costumes.css">' +
            '<style>body{margin:0;padding:24px;background:#f1f1f1;font:14px Arial,sans-serif}.button{display:inline-block;border:1px solid #8c8f94;background:white;color:#2271b1}.heading{font-weight:600}input[type=radio]{margin:0 6px 0 16px}label[for=pio]{margin-right:20px}@media(max-width:480px){body{padding:12px}}</style>' +
            '<div id="optionsframework-wrap"><div id="optionsframework"><form><div class="section"><h4>Live2D model</h4><input type="radio" name="options[live2d_m]" value="pio" id="pio" checked><label for="pio">Pio</label><input type="radio" name="options[live2d_m]" value="tia" id="tia"><label for="tia">Tia</label></div>' +
            '<div class="section"><h4>Costume source</h4><select id="live2d_costume_source">' + ['cdn','local','bundled','custom','remote'].map(v => '<option>'+v+'</option>').join('') + '</select></div>' +
            '<div id="section-live2d_resources" class="section"><h4>Model library</h4><div class="controls">'+field+'</div></div>' +
            '<div id="section-live2d_catalog"><input value="https://example.test/catalog.json"></div><div id="section-live2d_i"><input value="https://example.test/api/"></div><input name="unrelated" value="keep me"><button id="save">Save</button></form></div></div></html>'); return;
    }
    if (url.pathname === '/') {res.setHeader('Content-Type','text/html');res.end('<!doctype html><link rel="icon" href="data:,">'+widget);return;}
    const file = path.resolve(root, '.' + url.pathname);
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {res.writeHead(404).end(); return;}
    res.setHeader('Access-Control-Allow-Origin','*');
    res.setHeader('Content-Type', {'.js':'text/javascript','.json':'application/json','.png':'image/png','.css':'text/css'}[path.extname(file)] || 'application/octet-stream');
    fs.createReadStream(file).pipe(res);
});
(async () => {
    await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
    const origin = 'http://127.0.0.1:'+server.address().port;
    const browser = await chromium.launch({headless:true, ...(process.env.BROWSER_EXECUTABLE ? {executablePath:process.env.BROWSER_EXECUTABLE} : {}), args:['--use-angle=swiftshader','--enable-unsafe-swiftshader']});
    const page = await browser.newPage({viewport:{width:1280,height:720}});
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    const reports = [];
    async function check(name, fn) {await fn();reports.push(name);console.log('PASS '+name);}
    async function shot(name) {if (screenshotDir) await page.locator('#optionsframework').screenshot({path:path.join(screenshotDir,name+'.png')});}
    async function admin() {
        await page.goto(origin+'/admin');
        await page.addScriptTag({path:path.join(root,'cdn/js/src/01.jquery.min.js')});
        await page.evaluate(({origin,messages}) => {window.SakuraCostumeAdmin={ajaxUrl:origin+'/ajax',nonce:'fixture',messages,models:{pio:{count:104,bytes:50456340,downloadable:true,ready:false},tia:{count:0,bytes:0,downloadable:false,ready:false}}};}, {origin,messages});
        await page.addScriptTag({path:path.join(root,'inc/js/live2d-costumes.js')});
        await page.waitForFunction(() => document.querySelector('#sakura-costume-summary').textContent.includes('104'));
    }
    try {
        await admin();
        await check('real PHP markup and host CSS keep idle pause hidden, label the progress and fit narrow screens', async () => {
            assert.equal(await page.locator('#sakura-costume-stop').isVisible(),false);
            assert.equal(await page.locator('#sakura-costume-meter').isVisible(),false);
            assert.equal(await page.locator('#sakura-costume-restart').isVisible(),false);
            assert.equal(await page.locator('label[for=sakura-costume-bar]').textContent(),'Download progress');
            await shot('idle');
            await page.setViewportSize({width:375,height:812});
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth<=window.innerWidth),true);
            await page.locator('.sakura-costume-maintenance summary').press('Enter');
            assert.equal(await page.locator('#sakura-costume-restart').isVisible(),true);
            await shot('mobile');
            await page.locator('.sakura-costume-maintenance summary').press('Enter');
            await page.setViewportSize({width:1280,height:900});
        });
        await check('source fields are exclusive and model selection updates the library without network requests', async () => {
            for (const mode of ['custom','remote','bundled','cdn','local']) {
                await page.selectOption('#live2d_costume_source',mode);
                assert.equal(await page.locator('#section-live2d_catalog').isVisible(),mode==='custom');
                assert.equal(await page.locator('#section-live2d_i').isVisible(),mode==='remote');
                assert.equal(await page.locator('#section-live2d_resources').isVisible(),mode==='cdn'||mode==='local');
            }
            await page.check('#tia'); assert.equal(await page.locator('#sakura-costume-download').isDisabled(),true);
            assert.match(await page.locator('#sakura-costume-summary').textContent(),/No compatible/);
            assert.equal(calls.length,0); await page.check('#pio');
        });
        await check('download failure preserves unsaved fields and resumes to a local selection without submitting', async () => {
            fail=true; await page.click('#sakura-costume-download');
            await page.waitForFunction(() => document.querySelector('#sakura-costume-progress').textContent==='Fixture failure');
            assert.equal(await page.locator('#live2d_costume_source').isDisabled(),false);
            fail=false; await page.click('#sakura-costume-download');
            await page.waitForFunction(() => document.querySelector('#sakura-costume-progress').textContent.includes('Download complete'));
            assert.equal(await page.inputValue('#live2d_costume_source'),'local');
            assert.equal(await page.inputValue('[name=unrelated]'),'keep me');
            assert.equal(page.url(),origin+'/admin'); assert.equal(installed.size,3);
            assert.equal(calls.at(-1).mode,'finish');
            assert.equal(calls.filter(c=>c.mode==='download').length,7, 'one failed request plus two chunks per file');
            await page.selectOption('#live2d_costume_source','cdn');
            assert.equal(await page.locator('#sakura-costume-progress').textContent(),messages.ready);
            await page.selectOption('#live2d_costume_source','local');
        });
        await check('overwrite downloads every file in chunks and clear remains a separate action', async () => {
            calls=[];await page.locator('.sakura-costume-maintenance summary').click();await page.click('#sakura-costume-restart');
            await page.waitForFunction(() => document.querySelector('#sakura-costume-progress').textContent.includes('Download complete'));
            assert.equal(calls[0].mode,'restart');assert.equal(calls.filter(c=>c.mode==='download').length,6);
            await page.click('#sakura-costume-clear');
            await page.waitForFunction(() => document.querySelector('#sakura-costume-progress').textContent.includes('cleared'));
            assert.equal(calls.at(-1).mode,'clear');assert.equal(await page.inputValue('#live2d_costume_source'),'local');
        });
        await check('pause waits for the current request, blocks conflicting saves and resumes', async () => {
            installed=new Set(); calls=[]; delay=180;
            await page.click('#sakura-costume-download');
            await page.waitForFunction(() => document.querySelector('#live2d_costume_source').disabled);
            assert.equal(await page.evaluate(() => {const event=new Event('submit',{cancelable:true});document.querySelector('form').dispatchEvent(event);return event.defaultPrevented;}),true);
            await page.click('#sakura-costume-stop');
            await page.waitForFunction(() => document.querySelector('#sakura-costume-progress').textContent.includes('paused'));
            assert.ok(!calls.some(c=>c.mode==='finish')); delay=0;
            await page.click('#sakura-costume-download');
            await page.waitForFunction(() => document.querySelector('#sakura-costume-progress').textContent.includes('Download complete'));
        });
        await check('multiple saved parts contribute once to progress; pause and model switching retain confirmed bytes', async () => {
            installed=new Set();partial=new Map([[0,262144],[1,262144]]);calls=[];delay=250;
            await page.click('#sakura-costume-download');
            await page.waitForFunction(() => document.querySelector('#sakura-costume-count').textContent==='0 / 3 verified');
            const bar=page.locator('#sakura-costume-bar');
            assert.ok(Math.abs(Number(await bar.getAttribute('value'))-100/3)<0.01);
            await page.click('#sakura-costume-stop');
            await page.waitForFunction(() => document.querySelector('#sakura-costume-panel').dataset.state==='paused');
            assert.equal(await page.locator('#sakura-costume-stop').isVisible(),false);
            const before=Number(await bar.getAttribute('value'));
            assert.ok(before>=100/3 && before<=50);
            await shot('paused');
            await page.check('#tia');assert.equal(await page.locator('#sakura-costume-meter').isVisible(),false);
            await page.check('#pio');assert.equal(Number(await bar.getAttribute('value')),before);
            delay=0;failFinish=true;
            await page.click('#sakura-costume-download');
            await page.waitForFunction(() => document.querySelector('#sakura-costume-panel').dataset.state==='failed');
            assert.equal(Number(await bar.getAttribute('value')),100);
            assert.ok(!(await page.locator('#sakura-costume-progress').textContent()).includes('Download complete'));
            await shot('failed');
            failFinish=false;await page.click('#sakura-costume-download');
            await page.waitForFunction(() => document.querySelector('#sakura-costume-panel').dataset.state==='complete');
            assert.equal(await page.locator('#sakura-costume-count').textContent(),'3 / 3 verified');
            assert.equal(Number(await bar.getAttribute('value')),100);
            assert.equal(await page.locator('#sakura-costume-stop').isVisible(),false);
            await shot('complete');
        });
        await check('history shows protected versions, previews explicitly and cancellation never deletes', async () => {
            calls=[]; await page.click('#sakura-costume-history-load');
            await page.waitForSelector('.sakura-costume-version');
            assert.equal(await page.locator('.sakura-costume-version').count(),3);
            assert.equal(await page.locator('.sakura-costume-version button').count(),1);
            await page.locator('.sakura-costume-version button').click();
            await page.waitForFunction(() => !document.querySelector('#sakura-costume-history-confirm').hidden);
            assert.match(await page.locator('#sakura-costume-history-preview').textContent(),/100 files/);
            assert.equal(await page.locator('#sakura-costume-history-cancel').evaluate(el=>el===document.activeElement),true);
            await page.setViewportSize({width:375,height:812});
            await page.locator('.sakura-costume-version-id').first().locator('summary').click();
            assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
            await shot('history-mobile');
            await page.click('#sakura-costume-history-cancel');
            assert.equal(calls.filter(c=>c.mode==='history-delete').length,0);
            assert.equal(await page.locator('#sakura-costume-history-confirm').isVisible(),false);
            await page.setViewportSize({width:1280,height:900});
        });
        await check('model changes discard a pending history confirmation without requesting another model', async () => {
            await page.locator('.sakura-costume-version button').click();
            await page.waitForFunction(()=>!document.querySelector('#sakura-costume-history-confirm').hidden);
            const before=calls.length;
            await page.check('#tia'); await page.check('#pio');
            assert.equal(await page.locator('.sakura-costume-version').count(),0);
            assert.equal(await page.locator('#sakura-costume-history-confirm').isVisible(),false);
            assert.equal(calls.length,before);
        });
        await check('cleanup errors require a fresh preview; confirmed deletion disables competing operations', async () => {
            async function preview() {
                await page.click('#sakura-costume-history-load');
                await page.waitForSelector('.sakura-costume-version button');
                await page.locator('.sakura-costume-version button').click();
                await page.waitForFunction(()=>!document.querySelector('#sakura-costume-history-confirm').hidden);
            }
            await preview();failHistory=true;await page.click('#sakura-costume-history-delete');
            await page.waitForFunction(()=>document.querySelector('#sakura-costume-history-status').textContent.includes('Version changed'));
            assert.equal(historyDeleted,false);
            assert.equal(await page.locator('#sakura-costume-history-confirm').isVisible(),false);
            assert.equal(await page.locator('#sakura-costume-download').isDisabled(),false);
            failHistory=false;await preview();delay=180;await page.click('#sakura-costume-history-delete');
            assert.equal(await page.locator('#sakura-costume-download').isDisabled(),true);
            assert.equal(await page.locator('#sakura-costume-history-delete').isDisabled(),true);
            assert.equal(await page.locator('#pio').isDisabled(),true);
            assert.equal(await page.evaluate(()=>{const e=new Event('submit',{cancelable:true});document.querySelector('form').dispatchEvent(e);return e.defaultPrevented;}),true);
            await page.waitForFunction(()=>document.querySelector('#sakura-costume-history-status').textContent.includes('Selected version cleaned'));
            delay=0;assert.equal(historyDeleted,true);
            assert.equal(await page.locator('.sakura-costume-version').count(),2);
            assert.equal(await page.inputValue('[name=unrelated]'),'keep me');
            assert.equal(await page.inputValue('#live2d_costume_source'),'local');
            await shot('history-cleaned');
        });
        const pngRequests=[];
        page.on('request', req => {if (new URL(req.url()).pathname.endsWith('.png')) pngRequests.push(req.url());});
        async function frontend(width,model) {
            await page.setViewportSize({width,height:720}); await page.goto(origin);
            await page.addStyleTag({path:path.join(root,'live2d/css/live2d.css')});
            await page.addScriptTag({path:path.join(root,'cdn/js/src/01.jquery.min.js')});
            await page.evaluate(({origin,model,urls}) => {window.SakuraLive2D={enabled:true,modelPath:origin+'/live2d/model/'+model+'/',messageUrl:origin+'/live2d/message.json',messages:{},costumeUrls:urls};},
                {origin,model,urls:model==='pio'?catalog.costumes.map(i=>origin+'/live2d/costumes/pio/'+i.texture):[]});
            for (const file of ['live2d.js','message.js','costume.js']) await page.addScriptTag({path:path.join(root,'live2d/js',file)});
            await page.evaluate(() => {const api=window.sakuraLive2D,merge=api.mergeCostumes;api.mergeCostumes=(urls,rev)=>{window.mergedCount=urls.length;return merge(urls,rev);};});
            await page.addScriptTag({path:path.join(root,'live2d/js/run_local.js')});
        }
        await check('860px makes no texture request', async () => {await frontend(860,'pio'); await page.waitForTimeout(150);assert.equal(pngRequests.length,0);});
        await check('104 managed candidates do not prefetch; six changes keep model/canvas and release old textures', async () => {
            await frontend(861,'pio');
            await page.waitForFunction(() => window.sakuraLive2D.getTextureTarget() && !document.querySelector('.change-costume').hidden);
            assert.equal(await page.evaluate(()=>window.mergedCount),104);
            assert.equal(pngRequests.length,1);assert.ok(pngRequests[0].includes('/live2d/model/pio/'));
            for (let i=1;i<=6;i++) assert.equal(await page.evaluate(async i=>{
                Math.random=()=>i/7; const api=window.sakuraLive2D, before=api.getTextureTarget(),canvas=document.querySelector('#live2d'),old=before.model.getDrawParam().textures[0];
                return await api.changeCostume() && before.model===api.getTextureTarget().model && canvas===document.querySelector('#live2d') && !before.gl.isTexture(old);
            },i),true);
            assert.equal(pngRequests.length,7);assert.ok(pngRequests.slice(1).every(url=>url.includes('/live2d/costumes/pio/')));
        });
        await check('switching to Tia uses only Tia bundled textures', async () => {
            const before=pngRequests.length;await frontend(1280,'tia');
            await page.waitForFunction(()=>window.sakuraLive2D.getTextureTarget()&&!document.querySelector('.change-costume').hidden);
            assert.equal(pngRequests.length,before+1);assert.ok(pngRequests.at(-1).includes('/live2d/model/tia/'));
            assert.equal(await page.evaluate(()=>window.mergedCount),0);
        });
        assert.deepEqual(errors,[]); console.log('All '+reports.length+' managed browser checks passed.');
    } finally {await browser.close();server.close();}
})().catch(error=>{console.error(error);server.close();process.exitCode=1;});
