// Load style and business info
fetch('../get_voter_style.php')
  .then((res) => res.json())
  .then((data) => window.applyVoterAppearance?.(data));

document.addEventListener('DOMContentLoaded', () => {
  const businessName = localStorage.getItem('qr_business');
  const businessLogo = localStorage.getItem('qr_logo');
  const categories = (localStorage.getItem('qr_categories') || '').split(',').map(s => s.trim()).filter(s => s);

  if (businessName) {
    const bn = document.getElementById('businessName');
    if (bn) { bn.textContent = businessName; bn.style.display = 'block'; }
  }
  if (businessLogo) {
    const bl = document.getElementById('businessLogo');
    if (bl) { bl.src = businessLogo; bl.style.display = 'block'; }
  }

  const list = document.getElementById('categoryList');
  if (categories.length === 0) {
    list.innerHTML = '<p class="text-muted">No categories provided.</p>';
    return;
  }
  categories.forEach(cat => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-tocca-blue w-100 mb-2 category-item';
    btn.textContent = cat;
    btn.addEventListener('click', () => {
      localStorage.setItem('qr_selected_category', cat);
      window.location.href = 'qr-selected-category.html';
    });
    list.appendChild(btn);
  });
});