(function () {
    function applyIcon(toggle, theme) {
        toggle.textContent = theme === 'dark' ? '☀️' : '🌙';
    }

    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.getElementById('theme-toggle');
        if (!toggle) {
            return;
        }

        applyIcon(toggle, document.documentElement.getAttribute('data-theme'));

        toggle.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('um-theme', next);
            applyIcon(toggle, next);
        });
    });
})();
