document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.querySelector('#storelinkformc-select-all-deliveries');
    const rowCheckboxes = Array.from(document.querySelectorAll('.storelinkformc-delivery-checkbox'));

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rowCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        });
    }

    // Confirmación al eliminar todo
    const deleteAllButton = document.querySelector('input[name="clear_all_deliveries"]');
    if (deleteAllButton) {
        deleteAllButton.addEventListener('click', function (e) {
            if (!confirm('Are you sure you want to delete all pending deliveries?')) {
                e.preventDefault();
            }
        });
    }

    // Confirmación al resetear base de datos
    const resetButton = document.querySelector('input[name="reset_database"]');
    if (resetButton) {
        resetButton.addEventListener('click', function (e) {
            if (!confirm('⚠ This will delete ALL deliveries (pending and delivered). Continue?')) {
                e.preventDefault();
            }
        });
    }

    // Confirmación al borrar individual
    document.querySelectorAll('button[name="delete_delivery"]').forEach(button => {
        button.addEventListener('click', function (e) {
            if (!confirm('Delete this delivery record? The WooCommerce order will be kept.')) {
                e.preventDefault();
            }
        });
    });

    document.querySelectorAll('button[name="apply_bulk_action"]').forEach(button => {
        button.addEventListener('click', function (e) {
            const selected = rowCheckboxes.filter(checkbox => checkbox.checked);
            const action = document.querySelector('select[name="bulk_action"]');

            if (!action || !action.value) {
                alert('Choose a bulk action first.');
                e.preventDefault();
                return;
            }

            if (!selected.length) {
                alert('Select at least one delivery first.');
                e.preventDefault();
                return;
            }

            if (action.value === 'delete' && !confirm('Delete the selected delivery records? WooCommerce orders will be kept.')) {
                e.preventDefault();
            }
        });
    });
});
