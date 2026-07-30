function tradingViewAuthToken() {
    for (const script of document.scripts) {
        const text = script.textContent || '';
        if (!text.includes('auth_token')) {
            continue;
        }

        const escapedMatch = text.match(/\\"auth_token\\":\\"([^"\\]+)\\"/);
        if (escapedMatch?.[1]) {
            return escapedMatch[1];
        }

        const plainMatch = text.match(/"auth_token"\s*:\s*"([^"]+)"/);
        if (plainMatch?.[1]) {
            return plainMatch[1];
        }
    }

    return null;
}

let lastToken = null;

function publishToken() {
    const token = tradingViewAuthToken();
    if (!token || token === lastToken) {
        return;
    }

    chrome.runtime.sendMessage({
        type: 'tradingview-auth-token',
        token,
    }, (result) => {
        if (chrome.runtime.lastError || !result?.ok) {
            return;
        }
        lastToken = token;
    });
}

publishToken();
setInterval(publishToken, 60_000);
