// Handles showing/hiding spouse input based on marital status
// Also clears spouse input when not married.

(function () {
  document.addEventListener('DOMContentLoaded', function () {
    const maritalSelect = document.querySelector('select[name="marital_status"]');
    const spouseInput = document.querySelector('input[name="spouse_name"]');


    if (!maritalSelect || !spouseInput) return;

    function sync() {
      const v = maritalSelect.value;
      const normalized = String(v).toLowerCase();
      const spouseAllowed = normalized === 'married' || normalized === 'widowed';
      spouseInput.disabled = !spouseAllowed;

      // If turning off, clear to prevent submission of stale value
      if (!spouseAllowed) {
        spouseInput.value = '';
      }
    }

    maritalSelect.addEventListener('change', sync);
    sync();
  });
})();

