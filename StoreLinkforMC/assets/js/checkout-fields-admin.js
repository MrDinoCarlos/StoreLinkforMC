document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('.storelinkformc-admin form');
    if (!form) return;

    var checkboxes = Array.from(form.querySelectorAll('input[name="checkout_fields[]"]'));
    var counter = form.querySelector('[data-slmc-selected-count]');
    var presets = {
        digital: ['minecraft_username', 'minecraft_gift', 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_country'],
        minimal: ['minecraft_username', 'minecraft_gift', 'billing_email'],
        all: checkboxes.map(function (checkbox) { return checkbox.value; }),
        default: []
    };

    function refresh() {
        var selected = 0;
        checkboxes.forEach(function (checkbox) {
            var card = checkbox.closest('.storelinkformc-field-card');
            if (card) card.classList.toggle('is-selected', checkbox.checked);
            if (checkbox.checked) selected++;
        });
        if (counter) counter.textContent = String(selected);
    }

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', refresh);
    });

    form.querySelectorAll('[data-slmc-preset]').forEach(function (button) {
        button.addEventListener('click', function () {
            var values = presets[button.dataset.slmcPreset] || [];
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = values.indexOf(checkbox.value) !== -1;
            });
            refresh();
        });
    });

    form.querySelectorAll('[data-slmc-group-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var group = button.closest('.storelinkformc-field-group');
            var groupCheckboxes = Array.from(group.querySelectorAll('input[name="checkout_fields[]"]'));
            var shouldSelect = groupCheckboxes.some(function (checkbox) { return !checkbox.checked; });
            groupCheckboxes.forEach(function (checkbox) { checkbox.checked = shouldSelect; });
            refresh();
        });
    });

    refresh();
});
