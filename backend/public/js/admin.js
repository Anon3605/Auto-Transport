/* Deliberately dependency-free: no framework, no bundler, no polyfills. Every
   screen works with this file absent — these are enhancements only. */
(function () {
    'use strict';

    var root = document.documentElement;
    var STORE_KEY = 'autotransport.admin.theme';

    /* ---------------------------------------------------------------------
       Theme toggle. The <head> bootstrap has already applied the stored
       choice; this only wires the control and keeps localStorage in sync.
       --------------------------------------------------------------------- */
    function readStored() {
        try {
            return window.localStorage.getItem(STORE_KEY);
        } catch (e) {
            return null; // private mode / storage disabled
        }
    }

    function store(value) {
        try {
            window.localStorage.setItem(STORE_KEY, value);
        } catch (e) {
            /* Non-fatal: the toggle still works for this page view. */
        }
    }

    function systemTheme() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    }

    function currentTheme() {
        return root.getAttribute('data-theme') || readStored() || systemTheme();
    }

    function applyTheme(theme) {
        root.setAttribute('data-theme', theme);
        var next = theme === 'dark' ? 'light' : 'dark';
        var toggles = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < toggles.length; i++) {
            toggles[i].setAttribute('aria-label', 'Switch to ' + next + ' theme');
            toggles[i].setAttribute('title', 'Switch to ' + next + ' theme');
            var icon = toggles[i].querySelector('[data-theme-icon]');
            if (icon) {
                icon.textContent = theme === 'dark' ? '☀' : '☽';
            }
        }
    }

    var themeToggles = document.querySelectorAll('[data-theme-toggle]');
    for (var t = 0; t < themeToggles.length; t++) {
        // Hidden in the markup so a JS-less visitor never sees a dead control.
        themeToggles[t].removeAttribute('hidden');
        themeToggles[t].addEventListener('click', function () {
            var next = currentTheme() === 'dark' ? 'light' : 'dark';
            store(next);
            applyTheme(next);
        });
    }
    if (themeToggles.length) {
        applyTheme(currentTheme());
    }

    /* ---------------------------------------------------------------------
       Sidebar. The checkbox + label scrim already open and close it with CSS
       alone; this only adds Escape-to-close and closes it after navigating on
       a phone, which CSS cannot express.
       --------------------------------------------------------------------- */
    var navToggle = document.getElementById('nav-toggle');
    if (navToggle) {
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && navToggle.checked) {
                navToggle.checked = false;
            }
        });

        var sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            sidebar.addEventListener('click', function (event) {
                var link = event.target.closest ? event.target.closest('a') : null;
                if (link && navToggle.checked) {
                    navToggle.checked = false;
                }
            });
        }
    }

    /* ---------------------------------------------------------------------
       data-confirm on destructive forms. Native confirm() keeps this honest:
       if the dialog is suppressed the submit still goes through the server's
       own authorization, and no custom modal can be left half-wired.
       --------------------------------------------------------------------- */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.getAttribute) {
            return;
        }
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });
})();
