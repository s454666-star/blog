const test = require('node:test');
const assert = require('node:assert/strict');
const {isJournalURL, allowsSessionPermission} = require('../policy.cjs');

test('only local journal navigation is allowed', () => {
    for (const url of ['https://blog/video-journal','https://blog/video-journal/1?q=x']) assert.equal(isJournalURL(url), true);
    for (const url of ['http://blog/video-journal','https://blog.evil/video-journal','file:///C:/test','https://blog/admin','https://blog/video-journalevil','https://user@blog/video-journal','https://blog/video-journal/../../admin']) assert.equal(isJournalURL(url), false);
});

test('session permissions stay denied except journal fullscreen and clipboard write', () => {
    assert.equal(allowsSessionPermission('fullscreen', 'https://blog/video-journal'), true);
    assert.equal(allowsSessionPermission('fullscreen', 'https://blog/video-journal/12'), true);
    assert.equal(allowsSessionPermission('fullscreen', 'https://blog/admin'), false);
    assert.equal(allowsSessionPermission('fullscreen', 'https://evil/video-journal'), false);
    assert.equal(allowsSessionPermission('media', 'https://blog/video-journal'), false);
    assert.equal(allowsSessionPermission('openExternal', 'https://blog/video-journal'), false);
    assert.equal(allowsSessionPermission('clipboard-read', 'https://blog/video-journal'), false);
    assert.equal(allowsSessionPermission('clipboard-sanitized-write', 'https://blog/video-journal'), true);
    assert.equal(allowsSessionPermission('clipboard-sanitized-write', 'https://blog/admin'), false);
    assert.equal(allowsSessionPermission('clipboard-sanitized-write', 'https://evil/video-journal'), false);
});

test('fullscreen denies foreign frames and missing web contents', () => {
    assert.equal(allowsSessionPermission('fullscreen', undefined), false);
    assert.equal(allowsSessionPermission('fullscreen', 'https://blog/video-journal', 'https://evil.test', true), false);
    assert.equal(allowsSessionPermission('fullscreen', 'https://blog/video-journal', 'https://blog/video-journal', false), false);
    assert.equal(allowsSessionPermission('fullscreen', 'https://blog/video-journal', 'https://blog', true), true);
    assert.equal(allowsSessionPermission('automatic-fullscreen', 'https://blog/video-journal'), false);
    assert.equal(allowsSessionPermission('clipboard-sanitized-write', 'https://blog/video-journal', 'https://evil.test', true), false);
    assert.equal(allowsSessionPermission('clipboard-sanitized-write', 'https://blog/video-journal', 'https://blog', true), true);
    assert.equal(allowsSessionPermission('clipboard-sanitized-write', 'https://blog/video-journal', 'https://blog/video-journal', false), false);
});
