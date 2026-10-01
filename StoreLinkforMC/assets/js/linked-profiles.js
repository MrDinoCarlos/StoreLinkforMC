document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.storelinkformc-unlink-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var player = form.dataset.player || 'this player';
            if (!window.confirm('Unlink ' + player + ' from this website account? The player can link again later.')) {
                event.preventDefault();
            }
        });
    });
});
