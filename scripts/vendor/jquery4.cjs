// Apply Sakura's jQuery 4 migration to the exact upstream plugin releases.
// Inputs and minification commands are documented in the frontend migration note.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

function replace(source, before, after, expected = 1) {
    const parts = source.split(before);
    assert.equal(parts.length - 1, expected, 'Unexpected upstream occurrences: ' + before);
    return parts.join(after);
}

function readRelease(file, sha256) {
    const bytes = fs.readFileSync(file);
    assert.equal(crypto.createHash('sha256').update(bytes).digest('hex'), sha256, 'Unexpected upstream source: ' + file);
    return bytes.toString('utf8').replace(/\r\n/g, '\n');
}

function migratePjax(source) {
    source = replace(source, '$.isFunction(options.url)', '(typeof options.url === "function")', 2);
    source = replace(source, '$.type(options.container)', 'typeof options.container');
    source = replace(source, '$.isArray(', 'Array.isArray(', 2);
    source = replace(source, '$.trim(obj.title)', 'obj.title.trim()');
    return '/*! jquery-pjax 2.0.1 | MIT | github.com/defunkt/jquery-pjax\n' +
        ' * Sakura: use native type checks and trim with jQuery 4.\n */\n' + source;
}

function migrateFancybox(source) {
    source = replace(source, '$.isArray(', 'Array.isArray(', 2);
    source = replace(source, '$.type(item) === "object"', 'item && (item.nodeType || item.jquery)');
    source = replace(source, '$.type(params) === "object"', '$.isPlainObject(params)');
    for (const value of ['obj.opts.caption', 'self.opts.caption', 'content', 'providerOpts.url', 'providerOpts.thumb', 'current.opts.share.url']) {
        source = replace(source, '$.type(' + value + ')', '(typeof ' + value + ')');
    }
    source = replace(source, '$.type(command)', '(typeof command)', 2);
    for (const value of ['current.opts.clickContent', 'obj.opts[name]', 'duration', 'callback', '$el.get(0).onclick', 'action']) {
        source = replace(source, '$.isFunction(' + value + ')', '(typeof ' + value + ' === "function")');
    }
    source = replace(source, '$.trim(content)', 'content.trim()');
    source = replace(source, '$.isNumeric(', 'isNumericOption(', 4);
    const numericOption = '\n\n  // Durations accept finite numbers and numeric strings from data attributes.\n' +
        '  function isNumericOption(value) {\n' +
        '    return (typeof value === "number" || typeof value === "string") &&\n' +
        '      isFinite(value - parseFloat(value));\n' +
        '  }';
    const strict = '  "use strict";';
    const at = source.indexOf(strict);
    assert.ok(at >= 0, 'Missing main Fancybox scope');
    source = source.slice(0, at) + strict + numericOption + source.slice(at + strict.length);
    assert.doesNotMatch(source, /\$\.(?:isArray|isFunction|isNumeric|type|trim)\(/);
    return '/*! fancyBox 3.5.7 | GPL-3.0 | fancyapps.com/fancybox/\n' +
        ' * Sakura: use native value checks and trim with jQuery 4.\n */\n' + source;
}

if (require.main === module) {
    const [pjaxFile, fancyboxFile, outputDirectory, extra] = process.argv.slice(2);
    if (!pjaxFile || !fancyboxFile || !outputDirectory || extra) {
        console.error('Usage: node scripts/vendor/jquery4.cjs <upstream jquery.pjax.js> <upstream jquery.fancybox.js> <output directory>');
        process.exitCode = 1;
    } else {
        const pjax = migratePjax(readRelease(pjaxFile, '2e82459381db588f242c24defc00aab89d22211f6f8512ee1b9cd57bf3df8cce'));
        const fancybox = migrateFancybox(readRelease(fancyboxFile, 'd1c11df54787c676de783bcca52618b14e2812a587da3f8e0f79a8a0e4d2597b'));
        fs.mkdirSync(outputDirectory, {recursive: true});
        fs.writeFileSync(path.join(outputDirectory, 'jquery.pjax.js'), pjax);
        fs.writeFileSync(path.join(outputDirectory, 'jquery.fancybox.js'), fancybox);
        console.log('Migrated jquery-pjax 2.0.1 and Fancybox 3.5.7 for jQuery 4.');
    }
}

module.exports = {migratePjax, migrateFancybox};
