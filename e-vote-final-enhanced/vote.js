// Auto-save function
function saveDraft(userSelections) {
    fetch('save_draft.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ draft: userSelections })
    })
    .then(res => res.json())
    .then(data => console.log(data.message));
  }
  
  // Load on page load
  function loadDraftOnStart() {
    fetch('load_draft.php')
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success' && data.draft) {
          restoreSelections(data.draft);
        }
      });
  }
  
  // Restore saved answers into the page
  function restoreSelections(draft) {
    // Example: if using select or input fields with data-question-id
    for (const questionId in draft) {
      const value = draft[questionId];
      const field = document.querySelector(`[data-question-id="${questionId}"]`);
      if (field) field.value = value;
    }
  }
  
  // OPTIONAL: start saving every 30 seconds
  setInterval(() => {
    const selections = collectCurrentSelections();
    saveDraft(selections);
  }, 30000);
  
  // Custom: gather all user input (adapt this)
  function collectCurrentSelections() {
    const result = {};
    const inputs = document.querySelectorAll('[data-question-id]');
    inputs.forEach(input => {
      const qid = input.getAttribute('data-question-id');
      result[qid] = input.value;
    });
    return result;
  }
  
  // Run once on load
  document.addEventListener('DOMContentLoaded', loadDraftOnStart);
  