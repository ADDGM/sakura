const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');

const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const messages = Object.fromEntries([...read('functions.php').matchAll(/'([A-Za-z]+)' => __\('((?:\\.|[^'])*)', 'sakura'\)/g)]
    .map(match => [match[1], match[2].replace(/\\(['\\])/g, '$1')]));
const playerSource = read('js/sakura-app.js').match(/if \(mashiro_option.float_player_on\) \{[\s\S]+?    aplayerF\(\);\r?\n\}/)[0];
const widget = read('footer.php').match(/<div id="landlord"[\s\S]+?\r?\n\t<\/div>/)[0]
    .replace(/<\?php[\s\S]*?\?>/g, 'Live2D');
const themeStyle = read('style.css');
const hoverStyle = themeStyle.match(/\.ap-hover \{[^}]*\}/)[0] + themeStyle.match(/\.ap-hover:hover \{[^}]*\}/)[0];
const remoteTexture = Object.values(JSON.parse(read('live2d/model/pio/textures.json')).pioUrl[0])[0];
const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end('<!doctype html><html><head><link rel="icon" href="data:,"></head><body>' + widget +
            '<div id="secondary"></div><div class="post-entry"><h2 class="entry-title"><a href="#">Hello</a></h2></div>' +
            '<div id="aplayer-float" class="aplayer" data-fixed="true" data-url="/silent.wav" data-name="Test" data-artist="Test" data-lrc="/lyrics.lrc"></div></body></html>');
        return;
    }
    if (url.pathname === '/silent.wav') {
        response.setHeader('Content-Type', 'audio/wav');
        response.end(Buffer.from('UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=', 'base64'));
        return;
    }
    if (url.pathname === '/lyrics.lrc') {
        response.end('[00:00.00]Test lyrics\n[00:10.00]Another line');
        return;
    }
    const file = path.resolve(root, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
        response.writeHead(404).end();
        return;
    }
    const types = {'.js': 'text/javascript', '.json': 'application/json', '.png': 'image/png', '.css': 'text/css'};
    response.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
    fs.createReadStream(file).pipe(response);
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'http://127.0.0.1:' + server.address().port;
    const browser = await chromium.launch({
        headless: true,
        ...(process.env.BROWSER_EXECUTABLE ? {executablePath: process.env.BROWSER_EXECUTABLE} : {}),
        args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader']
    });
    const context = await browser.newContext({viewport: {width: 1280, height: 720}});
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const reports = [];
    const check = async (name, callback) => {
        try {
            await callback();
        } catch (error) {
            console.error(await page.evaluate(() => [...document.querySelectorAll('#landlord, .aplayer, .aplayer-body, .aplayer-list, .aplayer-lrc')].map(element => ({
                class: element.id || element.className, rect: element.getBoundingClientRect().toJSON(),
                display: getComputedStyle(element).display, position: getComputedStyle(element).position,
                opacity: getComputedStyle(element).opacity
            }))));
            console.error('Page errors:', errors);
            throw error;
        }
        reports.push(name);
        console.log('PASS ' + name);
    };
    async function setup({model, remote = false, blockedStorage = false} = {}) {
        await page.goto(origin);
        await page.addStyleTag({url: origin + '/cdn/css/lib.css'});
        // The isolated fixture needs the base stylesheet for the bundled APlayer 1.10.1.
        await page.addStyleTag({path: process.env.APLAYER_CSS_PATH || require.resolve('aplayer/dist/APlayer.min.css')});
        await page.addStyleTag({content: 'body{margin:0;background:#fff} #aplayer-float{z-index:100}' + hoverStyle});
        await page.addStyleTag({path: path.join(root, 'live2d/css/live2d.css')});
        await page.addScriptTag({path: path.join(root, 'cdn/js/src/01.jquery.min.js')});
        await page.evaluate(({origin, messages, model, remoteTexture}) => {
            window.SakuraLive2D = {
                enabled: true, messageUrl: origin + '/live2d/message.json?test=1', messages,
                modelPath: origin + '/live2d/model/' + (model || 'pio') + '/',
                dressUrl: origin + '/live2d/model/pio/' + remoteTexture
            };
            window.mashiro_option = {float_player_on: true, meting_api_url: '/unused'};
            window.Poi = {nonce: 'test'};
        }, {origin, messages, model, remoteTexture});
        await page.addScriptTag({path: path.join(root, 'cdn/js/src/07.APlayer.min.js')});
        await page.evaluate(() => {
            const Player = window.APlayer;
            window.testPlayers = [];
            window.APlayer = function (options) {
                const player = new Player(options);
                window.testPlayers.push(player);
                return player;
            };
        });
        await page.addScriptTag({content: playerSource});
        if (blockedStorage) {
            await page.evaluate(() => Object.defineProperty(window, 'localStorage', {get() {throw new Error('Storage blocked');}}));
        }
        await page.addScriptTag({path: path.join(root, 'live2d/js/message.js')});
        if (model) {
            await page.addScriptTag({path: path.join(root, 'live2d/js/live2d.js')});
            await page.addScriptTag({path: path.join(root, 'live2d/js/' + (remote ? 'run_field.js' : 'run_local.js'))});
        }
        await page.waitForTimeout(450);
    }
    const bottom = () => page.locator('#landlord').evaluate(element => parseFloat(element.style.bottom));
    async function notOverlapping(selector) {
        const overlapping = await page.evaluate(selector => {
            const widget = document.querySelector('#landlord').getBoundingClientRect();
            const rect = document.querySelector(selector).getBoundingClientRect();
            return Math.min(widget.right, rect.right) - Math.max(widget.left, rect.left) > 0.5 &&
                Math.min(widget.bottom, rect.bottom) - Math.max(widget.top, rect.top) > 0.5;
        }, selector);
        assert.equal(overlapping, false, 'Widget overlaps ' + selector);
    }
    try {
        await setup();
        await check('compact player keeps the default position', async () => assert.equal(await bottom(), 0));
        await check('expanded player is avoided without transition drift', async () => {
            await page.locator('.aplayer-miniswitcher').click();
            await page.waitForTimeout(750);
            await notOverlapping('.aplayer-body');
            const expected = await bottom();
            assert.ok(expected > 0);
            await page.evaluate(() => {for (let i = 0; i < 10; i++) window.sakuraLive2D.updatePlayerOffset();});
            assert.equal(await bottom(), expected);
        });
        await check('expanded playlist is avoided', async () => {
            await page.evaluate(() => window.testPlayers[0].list.show());
            await page.waitForTimeout(350);
            await page.evaluate(() => window.sakuraLive2D.updatePlayerOffset());
            await page.waitForTimeout(350);
            await notOverlapping('.aplayer-list');
        });
        await check('collapsed player restores the saved position', async () => {
            await page.locator('.aplayer-miniswitcher').click();
            await page.mouse.move(900, 50);
            await page.evaluate(() => {window.testPlayers[0].lrc.hide(); window.testPlayers[0].list.hide();});
            await page.waitForTimeout(400);
            await page.evaluate(() => window.sakuraLive2D.updatePlayerOffset());
            assert.equal(await bottom(), 0);
        });
        await check('lyrics contained inside the widget are fully avoided', async () => {
            await page.evaluate(() => {
                const lyric = document.querySelector('.aplayer-lrc');
                Object.assign(lyric.style, {display: 'block', opacity: '1', position: 'fixed', top: '600px', bottom: 'auto', left: '0', width: '100%', height: '30px', transform: 'none'});
                window.sakuraLive2D.updatePlayerOffset();
            });
            await page.waitForTimeout(350);
            await notOverlapping('.aplayer-lrc');
            assert.equal(await bottom(), 120);
        });
        await check('transparent lyrics do not reserve space', async () => {
            await page.locator('.aplayer-lrc').evaluate(element => {element.style.opacity = '0';});
            await page.evaluate(() => window.sakuraLive2D.updatePlayerOffset());
            assert.equal(await bottom(), 0);
        });
        await check('drag stays in the viewport and survives reload', async () => {
            await page.waitForTimeout(350);
            const handle = await page.locator('.drag-handle').boundingBox();
            await page.mouse.move(handle.x + 10, handle.y + 10);
            await page.mouse.down();
            await page.mouse.move(1600, -100, {steps: 5});
            await page.mouse.up();
            const position = await page.evaluate(() => JSON.parse(localStorage.getItem('sakura-live2d-position')));
            assert.deepEqual(position, {left: 1000, bottom: 470});
            await setup();
            assert.equal(await bottom(), 470);
            assert.equal(await page.locator('#landlord').evaluate(element => parseFloat(element.style.left)), 1000);
        });
        await check('keyboard movement and reset work', async () => {
            await page.locator('.drag-handle').focus();
            await page.keyboard.press('ArrowLeft');
            assert.equal(await page.locator('#landlord').evaluate(element => parseFloat(element.style.left)), 990);
            await page.locator('.reset-position').click();
            await page.mouse.move(900, 50);
            assert.deepEqual(await page.evaluate(() => JSON.parse(localStorage.getItem('sakura-live2d-position'))), {left: 30, bottom: 0});
        });
        await check('touch drag preserves finite coordinates', async () => {
            await page.waitForTimeout(350);
            await page.evaluate(() => {
                const handle = document.querySelector('.drag-handle');
                const rect = handle.getBoundingClientRect();
                const start = new Touch({identifier: 1, target: handle, clientX: rect.x + 10, clientY: rect.y + 10});
                const end = new Touch({identifier: 1, target: handle, clientX: rect.x + 110, clientY: rect.y - 90});
                handle.dispatchEvent(new TouchEvent('touchstart', {bubbles: true, cancelable: true, touches: [start]}));
                document.dispatchEvent(new TouchEvent('touchmove', {bubbles: true, cancelable: true, touches: [end]}));
                document.dispatchEvent(new TouchEvent('touchend', {bubbles: true, changedTouches: [end], touches: []}));
            });
            const position = await page.evaluate(() => JSON.parse(localStorage.getItem('sakura-live2d-position')));
            assert.equal(position.left, 130);
            assert.equal(position.bottom, 100);
        });
        await check('resize clamps the widget without losing the saved position', async () => {
            await page.evaluate(() => localStorage.setItem('sakura-live2d-position', JSON.stringify({left: 1000, bottom: 470})));
            await setup();
            await page.setViewportSize({width: 900, height: 400});
            await page.waitForTimeout(350);
            const rect = await page.locator('#landlord').boundingBox();
            assert.equal(rect.x, 620);
            assert.equal(rect.y, 0);
            await page.setViewportSize({width: 1280, height: 720});
            await page.waitForTimeout(350);
            assert.equal(await bottom(), 470);
            await page.locator('.reset-position').click();
        });
        await check('player reinitialization keeps one layout click handler', async () => {
            await page.evaluate(() => window.aplayerF());
            const count = await page.evaluate(() => jQuery._data(document.querySelector('.aplayer.aplayer-fixed'), 'events').click
                .filter(handler => handler.namespace === 'sakuraLive2DLayout').length);
            assert.equal(count, 1);
        });
        await check('PJAX-style tip rebinding escapes dynamic titles', async () => {
            await page.evaluate(() => {
                document.querySelector('.entry-title').innerHTML = '<a href="#"></a>';
                document.querySelector('.entry-title a').textContent = '<img src=x onerror="window.tipInjected=true">';
                window.live2d_Tips();
                window.live2d_Tips();
            });
            await page.waitForTimeout(150);
            await page.locator('.entry-title a').hover();
            assert.ok((await page.locator('#landlord .message').textContent()).includes('<img'));
            assert.equal(await page.locator('#landlord .message img').count(), 0);
            assert.equal(await page.evaluate(() => Boolean(window.tipInjected)), false);
        });
        await check('storage failure does not break layout or controls', async () => {
            await setup({blockedStorage: true});
            await page.locator('.reset-position').click();
            assert.equal(await bottom(), 0);
        });
        for (const width of [860, 861]) {
            await check(width + 'px loading boundary', async () => {
                await page.setViewportSize({width, height: 720});
                const requests = [];
                const listener = request => {if (request.url().includes('/live2d/model/')) requests.push(request.url());};
                page.on('request', listener);
                await setup({model: 'pio'});
                await page.waitForTimeout(800);
                page.off('request', listener);
                assert.equal(await page.locator('#landlord').isVisible(), width > 860);
                assert.equal(requests.length > 0, width > 860);
            });
        }
        await page.setViewportSize({width: 1280, height: 720});
        for (const [model, remote] of [['tia', false], ['pio', false], ['pio', true]]) {
            await check(model + (remote ? ' remote PNG' : '') + ' WebGL model renders', async () => {
                await setup({model, remote});
                await page.waitForTimeout(1800);
                const pixels = await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => {
                    const canvas = document.querySelector('#live2d');
                    const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
                    const pixels = new Uint8Array(canvas.width * canvas.height * 4);
                    gl.readPixels(0, 0, canvas.width, canvas.height, gl.RGBA, gl.UNSIGNED_BYTE, pixels);
                    resolve(pixels.filter((value, index) => index % 4 === 3 && value > 0).length);
                })));
                assert.ok(pixels > 500, 'Canvas has no rendered model pixels: ' + pixels);
                if (process.env.LIVE2D_SCREENSHOT_DIR) {
                    fs.mkdirSync(process.env.LIVE2D_SCREENSHOT_DIR, {recursive: true});
                    await page.screenshot({path: path.join(process.env.LIVE2D_SCREENSHOT_DIR, 'live2d-' + model + (remote ? '-remote' : '') + '.png')});
                }
            });
        }
        await check('model clicks still show a translated tip', async () => {
            await page.locator('#live2d').click({position: {x: 140, y: 160}});
            const tip = await page.locator('#landlord .message').textContent();
            assert.ok(['modelClick', 'modelClickShy', 'modelClickTease', 'modelClickWarning', 'modelClickHelp'].some(key => messages[key] === tip));
        });
        assert.deepEqual(errors, [], 'Browser JavaScript errors');
        console.log('All ' + reports.length + ' browser checks passed.');
    } finally {
        await browser.close();
        server.close();
    }
})().catch(error => {console.error(error); server.close(); process.exitCode = 1;});
