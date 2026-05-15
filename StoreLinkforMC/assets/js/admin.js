(function () {
    function initStoreLinkAdmin() {
        const i18n = (window.storelinkformcAdmin && window.storelinkformcAdmin.i18n) ? window.storelinkformcAdmin.i18n : {};

        // Copiar token
        const tokenField = document.getElementById('api-token-field');
        if (tokenField && !tokenField.dataset.slmcBound) {
            tokenField.dataset.slmcBound = '1';
            tokenField.addEventListener('click', function () {
                navigator.clipboard.writeText(tokenField.value).then(() => {
                    alert(i18n.token_copied || 'Token copied to clipboard!');
                }).catch(() => {
                    alert(i18n.token_copy_failed || 'Failed to copy token.');
                });
            });
        }

        // Confirmaciones por ACTION (no dependemos de clases)
        const confirmByAction = {
            storelinkformc_regen_token: i18n.confirm_regen_token || 'Are you sure you want to regenerate the API token?',
            storelinkformc_rebuild_pending: i18n.confirm_rebuild_table || 'Are you sure you want to rebuild the tables?',
            storelinkformc_force_checkout_shortcode: i18n.confirm_force_checkout || 'Are you sure you want to force the classic checkout?'
        };

        document.querySelectorAll('form').forEach((form) => {
            if (form.dataset.slmcConfirmBound) return;

            const actionInput = form.querySelector('input[name="action"]');
            if (!actionInput) return;

            const actionVal = actionInput.value;
            if (!confirmByAction[actionVal]) return;

            form.dataset.slmcConfirmBound = '1';
            form.addEventListener('submit', function (e) {
                if (!window.confirm(confirmByAction[actionVal])) {
                    e.preventDefault();
                }
            });
        });
    }

    // Defer-safe: si el DOM ya está cargado, ejecuta ya. Si no, espera.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initStoreLinkAdmin);
    } else {
        initStoreLinkAdmin();
    }
})();
