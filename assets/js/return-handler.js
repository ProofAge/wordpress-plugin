(() => {
    const config = window.ProofAgeReturnPage;

    if (!config) {
        return;
    }

    const payload = {
        source: 'proofage-wordpress',
        status: config.status || 'pending',
        redirectUrl: config.redirectUrl || window.location.origin,
    };

    if (window.parent && window.parent !== window) {
        window.parent.postMessage(payload, window.location.origin);
        return;
    }

    if (window.opener) {
        window.opener.postMessage(payload, window.location.origin);

        if (payload.status === 'approved') {
            window.close();
            return;
        }
    }

    window.location.replace(payload.redirectUrl);
})();
