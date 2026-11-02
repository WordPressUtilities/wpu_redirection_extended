/* 404 tab URL search: no <form> since the admin page is already wrapped in the POST upload form, so navigate to the current URL (range kept) with wre_404_search */
(function() {
    var input = document.getElementById('wre-404-search');
    var button = document.getElementById('wre-404-search-submit');
    if (!input || !button) {
        return;
    }

    function search() {
        var url = new URL(window.location.href);
        var value = input.value.trim();
        if (value) {
            url.searchParams.set('wre_404_search', value);
        } else {
            url.searchParams.delete('wre_404_search');
        }
        url.hash = '';
        window.location.href = url.toString();
    }

    button.addEventListener('click', search);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            search();
        }
    });
})();
