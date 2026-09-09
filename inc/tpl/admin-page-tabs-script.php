<?php
defined('ABSPATH') || die;

/* Tabs behaviour for the admin page. Runs in WPURedirectionExtended::page_content__main() scope.
   ponytail: inline script, no enqueue/assets file, this is the only page using it. */
?>
<script>
(function() {
    var nav = document.getElementById('wre-tabs-nav');
    if (!nav) {
        return;
    }
    var links = nav.querySelectorAll('.nav-tab');

    function activate(id, store) {
        var target = document.getElementById(id);
        if (!target) {
            return false;
        }
        Array.prototype.forEach.call(document.querySelectorAll('.wre-tab'), function(section) {
            section.hidden = (section !== target);
        });
        Array.prototype.forEach.call(links, function(link) {
            var active = link.getAttribute('href') === '#' + id;
            link.classList.toggle('nav-tab-active', active);
            if (active) {
                link.setAttribute('aria-current', 'true');
            } else {
                link.removeAttribute('aria-current');
            }
        });
        if (store) {
            try {
                sessionStorage.setItem('wre_tab', id);
            } catch (e) {}
        }
        return true;
    }

    Array.prototype.forEach.call(links, function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            activate(link.getAttribute('href').substring(1), true);
        });
    });

    try {
        var stored = sessionStorage.getItem('wre_tab');
        if (stored) {
            activate(stored, false);
        }
    } catch (e) {}
})();
</script>
