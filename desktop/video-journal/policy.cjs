'use strict';
function isJournalURL(value) {
    try {
        const url = new URL(value);
        return url.origin === 'https://blog' && !url.username && !url.password &&
            (url.pathname === '/video-journal' || url.pathname.startsWith('/video-journal/'));
    } catch { return false; }
}
// Desktop session permissions stay denied except HTML5 fullscreen and sanitized clipboard write on the journal itself.
function allowsSessionPermission(permission, url, requestingUrl, isMainFrame) {
    if (isMainFrame === false || !isJournalURL(url)) return false;
    if (permission === 'fullscreen' || permission === 'clipboard-sanitized-write') {
        if (!requestingUrl) return true;
        try { return new URL(requestingUrl).origin === 'https://blog'; }
        catch { return false; }
    }
    return false;
}
module.exports = { isJournalURL, allowsSessionPermission };
