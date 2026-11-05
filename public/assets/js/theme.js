(function () {
    try {
        var saved = window.localStorage.getItem('ft-theme');
        if (saved === 'light' || saved === 'dark') {
            document.documentElement.setAttribute('data-theme', saved);
        }
    } catch (e) {
        /* Storage blocked (private mode): just follow the OS theme. */
    }
})();
