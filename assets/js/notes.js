// Small niceties for Notes pages
document.addEventListener('click', (e) => {
  // placeholder for future
});

// Auto-submit upload forms when a file is selected
document.querySelectorAll('form.photo-upload-inline input[type=file]').forEach(inp => {
  inp.addEventListener('change', () => {
    const form = inp.closest('form');
    if (form) form.submit();
  });
});
