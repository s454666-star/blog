'use strict';
function isJournalURL(value) {
    try {
        const url = new URL(value);
        return url.origin === 'https://blog' && !url.username && !url.password &&
            (url.pathname === '/video-journal' || url.pathname.startsWith('/video-journal/'));
    } catch { return false; }
}
// Desktop session permissions stay denied except HTML5 fullscreen on the journal itself.
function allowsSessionPermission(permission, url, requestingUrl, isMainFrame) {
    if (permission !== 'fullscreen' || !isJournalURL(url) || isMainFrame === false) return false;
    if (!requestingUrl) return true;
    try { return new URL(requestingUrl).origin === 'https://blog'; }
    catch { return false; }
}
module.exports = { isJournalURL, allowsSessionPermission };
