document.addEventListener('click', function (e) {
    const btn = e.target.closest('.js-copy-code');
    if (!btn) return;

    const code = btn.dataset.code || '';
    if (!code) return;

    navigator.clipboard.writeText(code).then(function () {
        btn.classList.add('copied');
        const hint = btn.querySelector('.copy-hint');
        if (hint) hint.textContent = 'Copied!';
        setTimeout(function () {
            btn.classList.remove('copied');
            if (hint) hint.textContent = 'Copy';
        }, 2000);
    });
});

document.querySelectorAll('.filter-tabs').forEach(function (tabs) {
    tabs.addEventListener('click', function (e) {
        const tab = e.target.closest('.tab');
        if (!tab) return;

        const filter = tab.dataset.filter;
        tabs.querySelectorAll('.tab').forEach(function (t) {
            t.classList.toggle('active', t === tab);
        });

        const container = tabs.closest('.coupon-list-wrap');
        if (!container) return;

        container.querySelectorAll('.coupon-card').forEach(function (card) {
            const type = card.dataset.type || '';
            const verified = card.dataset.verified === '1';
            let show = filter === 'all';
            if (filter === 'verified') show = verified;
            if (filter === 'codes') show = type === 'code';
            if (filter === 'deals') show = type === 'deal';
            card.style.display = show ? '' : 'none';
        });
    });
});
