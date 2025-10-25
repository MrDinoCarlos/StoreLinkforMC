(function () {
  function $(sel) { return document.querySelector(sel); }
  function any(sel) { return document.querySelector(sel) || document.querySelector(sel.replace('#billing_', '#')); }

  function setLabel(text) {
    var label = $('#billing_minecraft_username_field label') || $('#minecraft_username_field label');
    if (label) label.textContent = text;
  }

  function addRequiredUI() {
    var field = $('#billing_minecraft_username_field') || $('#minecraft_username_field');
    if (field) field.classList.add('validate-required');
  }

  function removeRequiredUI() {
    var field = $('#billing_minecraft_username_field') || $('#minecraft_username_field');
    if (field) field.classList.remove('validate-required');
  }

  function updateState() {
    var gift   = any('#billing_minecraft_gift');
    var input  = any('#billing_minecraft_username');
    if (!input) return;

    var linked = (window.storelinkformc_checkout_vars && window.storelinkformc_checkout_vars.linked_player) || '';
    var isGift = !!(gift && gift.checked);

    function enableEditableBlankRequired() {
      input.removeAttribute('disabled');
      input.removeAttribute('readonly');
      input.disabled = false;
      input.readOnly = false;
      input.required = true;
      input.setAttribute('aria-required', 'true');
      input.value = '';
      setLabel('Minecraft Username *');
      addRequiredUI();
    }

    function makeReadonlyLinked() {
      input.removeAttribute('disabled');
      input.disabled = false;
      input.readOnly = true;
      input.required = false;
      input.removeAttribute('aria-required');
      input.setAttribute('readonly', 'readonly');
      input.value = linked;
      setLabel('Minecraft Username (auto-linked)');
      removeRequiredUI();
    }

    function disableNoLinked() {
      input.value = '';
      input.required = false;
      input.removeAttribute('aria-required');
      input.readOnly = true;
      input.disabled = true;
      input.setAttribute('readonly', 'readonly');
      input.setAttribute('disabled', 'disabled');
      setLabel('Minecraft Username (disabled until gift)');
      removeRequiredUI();
    }

    if (isGift) {
      enableEditableBlankRequired();
    } else {
      if (linked) makeReadonlyLinked();
      else disableNoLinked();
    }
  }

  function init() {
    var gift  = any('#billing_minecraft_gift');
    var input = any('#billing_minecraft_username');

    // --- NEW: honor plugin setting force_link ---
    var forceLink = (window.storelinkformc_checkout_vars && window.storelinkformc_checkout_vars.force_link === 'yes');

    if (!forceLink) {
      // Modo libre: editable + obligatorio siempre y ocultar gift
      if (gift && gift.closest('.form-row')) gift.closest('.form-row').style.display = 'none';
      if (input) {
        input.disabled = false;
        input.readOnly = false;
        input.required = true;
        input.removeAttribute('readonly');
        input.removeAttribute('disabled');
        var field = $('#billing_minecraft_username_field') || $('#minecraft_username_field');
        if (field) field.classList.add('validate-required');
      }
      return; // no aplicar lógica de gift/linked
    }

    // Modo clásico
    updateState();
    if (gift) gift.addEventListener('change', updateState);

    if (window.jQuery) {
      window.jQuery(document.body).on('updated_checkout', updateState);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
