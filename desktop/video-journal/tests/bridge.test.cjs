const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const {isJournalURL} = require('../policy.cjs');
test('only local journal navigation is allowed', () => {
    for (const url of ['https://blog/video-journal','https://blog/video-journal/1?q=x']) assert.equal(isJournalURL(url), true);
    for (const url of ['http://blog/video-journal','https://blog.evil/video-journal','file:///C:/test','https://blog/admin','https://blog/video-journalevil','https://user@blog/video-journal','https://blog/video-journal/../../admin']) assert.equal(isJournalURL(url), false);
});
function preload(origin, pathname, iframe = false) {
    let bridge, calls = 0;
    class File { constructor(name, diskPath) { this.name = name; this.diskPath = diskPath; } }
    const window = {}; window.top = iframe ? {} : window;
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../preload.cjs'), 'utf8'), {
        window, File, location: {origin, pathname},
        require(name) {
            assert.equal(name, 'electron');
            return {
                contextBridge: {exposeInMainWorld(name, value) { assert.equal(name, 'videoJournalDesktop'); bridge = value; }},
                webUtils: {getPathForFile(file) { calls++; return file.diskPath || ''; }}
            };
        }
    });
    return {File, bridge, calls: () => calls};
}
test('selected video paths cross the bridge unchanged', () => {
    const env = preload('https://blog','/video-journal');
    const diskPath = 'M:\\測試影片\\clip.mp4';
    assert.equal(env.bridge.pathForFile(new env.File('clip.mp4', diskPath)), diskPath);
    assert.equal(env.bridge.pathForFile(new env.File('clip.mp4')), '');
    assert.equal(env.bridge.pathForFile(new env.File('private.txt','C:\\private.txt')), '');
    assert.equal(env.bridge.pathForFile({name:'clip.mp4',diskPath}), '');
    assert.equal(env.calls(), 2);
});
test('other origins, routes and iframes have no native bridge', () => {
    assert.equal(preload('https://evil.test','/video-journal').bridge, undefined);
    assert.equal(preload('https://blog','/admin').bridge, undefined);
    assert.equal(preload('https://blog','/video-journal',true).bridge, undefined);
});
