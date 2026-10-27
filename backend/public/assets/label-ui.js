document.querySelectorAll('[data-label-profile]').forEach(select => {
  const apply = () => {
    const option = select.selectedOptions[0];
    if (!option?.value) return;
    const form = select.closest('form');
    ['width_mm','height_mm','dpi'].forEach((name,i) => { form.elements[name].value = option.dataset[['width','height','dpi'][i]]; });
  };
  select.addEventListener('change', apply); apply();
});
document.querySelectorAll('[data-select-cartons]').forEach(button => button.addEventListener('click', () => {
  const inputs = [...button.closest('form').querySelectorAll('input[name="carton_ids[]"]')];
  const check = !inputs.every(input => input.checked); inputs.forEach(input => { input.checked = check; });
}));
