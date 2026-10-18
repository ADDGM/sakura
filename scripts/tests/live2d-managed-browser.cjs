// Optional real browser checks; only loopback HTTP fixtures are used.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const catalog = JSON.parse(read('live2d/costumes/pio/catalog.json'));
const messages = Object.fromEntries([...read('inc/live2d-costumes.php').matchAll(/'([A-Za-z]+)' => __\('([^']+)', 'sakura'\)/g)].map(m => [m[1], m[2]]));
const widget = read('footer.php').match(/<div id="landlord"[\s\S]+?\r?\n\t<\/div>/)[0].replace(/<\?php[\s\S]*?\?>/g, 'Live2D');
let calls = [], installed = new Set(), partial = new Map(), refreshing = false, fail = false, delay = 0;
const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/ajax') {
        let body = ''; for await (const chunk of req) body += chunk;
        const data = Object.fromEntries(new URLSearchParams(body)); calls.push(data);
        assert.equal(data.action, 'sakura_live2d_resources'); assert.equal(data.model, 'pio'); assert.equal(data.nonce, 'fixture');
        if (delay) await new Promise(resolve => setTimeout(resolve, delay));
        if (fail && data.mode === 'download') {res.writeHead(400, {'Content-Type': 'application/json'}).end(JSON.stringify({success:false,data:{message:'Fixture failure'}})); return;}
        if (data.mode === 'restart') {installed.clear();partial.clear();refreshing=true;}
        if (data.mode === 'clear') {partial.clear();refreshing=false;}
        let part;
        if (data.mode === 'download') {
            const index=Number(data.index),bytes=(partial.get(index)||0)+262144;
            part={complete:bytes===524288,bytes,size:524288};partial.set(index,bytes);
            if (part.complete) {installed.add(index);partial.delete(index);}
        }
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({success: true, data: data.mode === 'download' ? part : {total: 3, missing: [0,1,2].filter(i => !installed.has(i)), refresh:refreshing, ready: data.mode === 'finish'}})); return;
    }
    if (url.pathname === '/admin') {
        res.setHeader('Content-Type','text/html; charset=utf-8');
        res.end('<!doctype html><form><input type="radio" name="options[live2d_m]" value="pio" id="pio" checked><label for="pio">Pio</label><input type="radio" name="options[live2d_m]" value="tia" id="tia"><label for="tia">Tia</label>' +
            '<select id="live2d_costume_source">' + ['cdn','local','bundled','custom','remote'].map(v => '<option>'+v+'</option>').join('') + '</select>' +
            '<div id="section-live2d_resources"><p id="sakura-costume-summary"></p><button type="button" id="sakura-costume-download">Download</button><button type="button" id="sakura-costume-stop" hidden>Pause</button><button type="button" id="sakura-costume-restart">Overwrite</button><button type="button" id="sakura-costume-clear">Clear</button><p role="status" id="sakura-costume-progress"></p></div>' +
            '<div id="section-live2d_catalog"><input value="https://example.test/catalog.json"></div><div id="section-live2d_i"><input value="https://example.test/api/"></div><input name="unrelated" value="keep me"><button id="save">Save</button></form>'); return;
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
    async function admin() {
        await page.goto(origin+'/admin');
        await page.addScriptTag({path:path.join(root,'cdn/js/src/01.jquery.min.js')});
        await page.evaluate(({origin,messages}) => {window.SakuraCostumeAdmin={ajaxUrl:origin+'/ajax',nonce:'fixture',messages,models:{pio:{count:104,bytes:50456340,downloadable:true,ready:false},tia:{count:0,bytes:0,downloadable:false,ready:false}}};}, {origin,messages});
        await page.addScriptTag({path:path.join(root,'inc/js/live2d-costumes.js')});
        await page.waitForFunction(() => document.querySelector('#sakura-costume-summary').textContent.includes('104'));
    }
    try {
        await admin();
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
        });
        await check('overwrite downloads every file in chunks and clear remains a separate action', async () => {
            calls=[];await page.click('#sakura-costume-restart');
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
