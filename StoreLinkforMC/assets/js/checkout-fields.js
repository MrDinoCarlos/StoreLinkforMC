document.addEventListener('DOMContentLoaded', () => {
    // Aquí validaciones o acciones JS futuras
    console.log('Checkout Fields admin JS loaded');
});
document.addEventListener('DOMContentLoaded', function () {
    const allowed = window.storelinkformc_allowed_fields || [];

    document.querySelectorAll('.woocommerce-billing-fields .form-row, .woocommerce-shipping-fields .form-row').forEach(el => {
        const input = el.querySelector('input, select, textarea');
        if (input && !allowed.includes(input.name.replace(/.*\[(.*)\]/, '$1'))) {
            el.style.display = 'none';
        }
    });
});