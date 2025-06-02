document.addEventListener('DOMContentLoaded', function () {
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
            if (!confirm('Delete this delivery?')) {
                e.preventDefault();
            }
        });
    });
});
