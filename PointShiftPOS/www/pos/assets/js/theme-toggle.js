/**
 * TechShift POS — Theme toggle (light / dark)
 * Persists choice in localStorage.
 */
(function () {
    const KEY = 'pos_theme';

    function applyTheme(dark) {
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        const icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = dark ? 'fas fa-sun' : 'fas fa-circle-half-stroke';
        }
    }

    // Apply on page load immediately (before paint)
    const saved = localStorage.getItem(KEY);
    applyTheme(saved === 'dark');

    // Wire up toggle button after DOM ready
    document.addEventListener('DOMContentLoaded', function () {
        applyTheme(localStorage.getItem(KEY) === 'dark');

        const btn = document.getElementById('themeToggle');
        if (!btn) return;

        btn.addEventListener('click', function () {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const next = !isDark;
            localStorage.setItem(KEY, next ? 'dark' : 'light');
            applyTheme(next);
        });
    });
})();
