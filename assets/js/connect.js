(() => {
    const config = window.ProofAgeConnect;
    const root = document.querySelector('[data-proofage-connect]');

    if (!config || !root) {
        return;
    }

    root.querySelectorAll('form[data-proofage-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(config.messages.confirmDisconnect)) {
                event.preventDefault();
            }
        });
    });

    const startButton = root.querySelector('[data-proofage-connect-start]');

    if (!startButton) {
        return;
    }

    const actions = root.querySelector('[data-proofage-connect-actions]');
    const messageBox = root.querySelector('[data-proofage-connect-message]');
    const waiting = root.querySelector('[data-proofage-connect-waiting]');
    const codeNode = root.querySelector('[data-proofage-code]');
    const linkNode = root.querySelector('[data-proofage-link]');
    const countdownNode = root.querySelector('[data-proofage-countdown]');
    const copyButton = root.querySelector('[data-proofage-copy]');
    const cancelButton = root.querySelector('[data-proofage-cancel]');

    let active = false;
    let pollTimer = 0;
    let countdownTimer = 0;
    let expiresAt = 0;
    let interval = 5;

    const post = async (action, nonce) => {
        const response = await fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action, nonce }),
        });
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload?.data?.message || config.messages.failed);
        }

        return payload.data;
    };

    const showMessage = (text, isError) => {
        messageBox.textContent = text;
        messageBox.className = 'notice inline ' + (isError ? 'notice-error' : 'notice-info');
        messageBox.hidden = text === '';
    };

    const stop = () => {
        active = false;
        window.clearTimeout(pollTimer);
        window.clearInterval(countdownTimer);
        waiting.hidden = true;
        actions.hidden = false;
        startButton.disabled = false;
    };

    const formatRemaining = (seconds) => {
        const minutes = Math.floor(seconds / 60);
        const rest = String(seconds % 60).padStart(2, '0');

        return minutes + ':' + rest;
    };

    const tick = () => {
        const remaining = Math.max(0, Math.round((expiresAt - Date.now()) / 1000));

        countdownNode.textContent = config.messages.countdown.replace('%s', formatRemaining(remaining));

        if (remaining === 0) {
            stop();
            showMessage(config.messages.expired, true);
        }
    };

    const schedulePoll = () => {
        window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(poll, Math.max(1, interval) * 1000);
    };

    const finish = (data) => {
        stop();

        if (data.status === 'connected') {
            window.location.href = config.settingsUrl;

            return;
        }

        showMessage(data.message || config.messages.failed, true);
    };

    const poll = async () => {
        if (!active) {
            return;
        }

        let data;

        try {
            data = await post('proofage_connect_poll', config.nonces.poll);
        } catch (_error) {
            // A failed request is not an answer from ProofAge: keep going until the code expires.
            schedulePoll();

            return;
        }

        if (!active) {
            return;
        }

        if (data.status === 'pending') {
            interval = Number(data.interval) || interval;
            schedulePoll();

            return;
        }

        finish(data);
    };

    const showWaiting = (data) => {
        codeNode.textContent = data.user_code;
        linkNode.href = data.verification_uri;
        expiresAt = Date.now() + Number(data.expires_in) * 1000;
        interval = Number(data.interval) || interval;
        showMessage('', false);
        actions.hidden = true;
        waiting.hidden = false;
        active = true;
        tick();
        window.clearInterval(countdownTimer);
        countdownTimer = window.setInterval(tick, 1000);
        schedulePoll();
    };

    startButton.addEventListener('click', async () => {
        startButton.disabled = true;
        showMessage('', false);

        try {
            const data = await post('proofage_connect_start', config.nonces.start);

            if (data.status === 'waiting') {
                showWaiting(data);

                return;
            }

            finish(data);
        } catch (error) {
            stop();
            showMessage(error.message, true);
        }
    });

    cancelButton.addEventListener('click', async () => {
        stop();

        try {
            await post('proofage_connect_cancel', config.nonces.cancel);
        } catch (_error) {
            // The code expires on its own.
        }
    });

    copyButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(codeNode.textContent || '');
            copyButton.textContent = config.messages.copied;
            window.setTimeout(() => {
                copyButton.textContent = config.messages.copy;
            }, 1500);
        } catch (_error) {
            const range = document.createRange();

            range.selectNodeContents(codeNode);
            window.getSelection()?.removeAllRanges();
            window.getSelection()?.addRange(range);
        }
    });

    if (!waiting.hidden) {
        showWaiting({
            user_code: codeNode.textContent || '',
            verification_uri: linkNode.getAttribute('href') || '',
            expires_in: waiting.dataset.expiresIn || 0,
            interval: waiting.dataset.interval || 5,
        });
    }
})();
