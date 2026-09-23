const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const {webcrypto} = require('node:crypto');

async function dropScenario(desktop, missing = false) {
    const elements = new Map();
    let batches = 0, submitted, redirected;
    class Element {
        constructor() { this.events = {}; this.value = ''; this.hidden = true; this.dataset = {}; this.children = []; this.classList = {add(){},remove(){},toggle(){}}; }
        addEventListener(name, fn) { this.events[name] = fn; }
        replaceChildren() { this.children = []; }
        append(...children) { this.children.push(...children); }
        setAttribute() {}
        focus() {}
        querySelector() { return new Element(); }
        showModal() { this.open = true; }
        close() { this.open = false; }
        requestSubmit() { element('#create-dialog form').events.submit({preventDefault(){}}); }
    }
    function element(selector) {
        if (selector === '#story') return null;
        if (!elements.has(selector)) elements.set(selector, new Element());
        return elements.get(selector);
    }
    element('#video-dropzone').dataset = {resolveUrl: '/resolve',batchUrl:'/batch'};
    const document = {querySelector: element, querySelectorAll: () => [], createElement: () => new Element(), addEventListener(){}};
    const window = desktop ? {videoJournalDesktop:{pathForFile:file => 'C:/fixtures/'+file.name}} : {};
    const fetch = async (url, options) => {
        const data = JSON.parse(options.body);
        if (url === '/resolve') {
            assert.equal(data.path, desktop ? 'C:/fixtures/'+data.name : undefined);
            return {ok:true,json:async()=>({matches:missing?[]:[{path:'C:/fixtures/'+data.name}]})};
        }
        assert.equal(url,'/batch'); batches++; submitted = data.items;
        return {ok:true,json:async()=>({redirect:'/done'})};
    };
    vm.runInNewContext(fs.readFileSync(path.resolve(__dirname,'../../..','public/js/video-journal.js'),'utf8'), {
        document,window,fetch,crypto:webcrypto,URL,Uint8Array,Blob,
        setTimeout,clearTimeout,location:{assign:value=>{redirected=value;}}
    });
    const files = ['one.mp4','two.mp4'].map(name=>new File(['synthetic'],name));
    element('#dropped-files').events.change({target:{files,value:''}});
    for (let i=0;i<100 && (element('#create-dialog button[type="submit"]').disabled || (desktop && !missing && !redirected));i++) await new Promise(resolve=>setTimeout(resolve,5));
    return {batches,submitted,redirected};
}
test('desktop drop auto-creates one batch of two original titles without confirmation', async()=>{
    const result=await dropScenario(true);
    assert.equal(result.batches,1);
    assert.deepEqual(Array.from(result.submitted,item=>item.title),['one.mp4','two.mp4']);
    assert.equal(result.redirected,'/done');
});
test('web browser retains manual submit and missing native sources never auto-create',async()=>{
    assert.equal((await dropScenario(false)).batches,0);
    assert.equal((await dropScenario(true,true)).batches,0);
});
