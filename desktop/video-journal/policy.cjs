'use strict';
function isJournalURL(value) {
    try {
        const url = new URL(value);
        return url.origin === 'https://blog' && !url.username && !url.password &&
            (url.pathname === '/video-journal' || url.pathname.startsWith('/video-journal/'));
    } catch { return false; }
}
module.exports = { isJournalURL };
