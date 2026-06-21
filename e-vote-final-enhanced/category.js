import { bootstrapVoterSession } from './voter_bootstrap.js';

function renderEmptyState(container, { title, text, ctaHref, ctaLabel, secondaryHref, secondaryLabel }) {
  container.innerHTML = `
    <div class="voter-empty-state" role="status">
      <div class="voter-empty-icon" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></div>
      <p class="voter-empty-title">${title}</p>
      <p class="voter-empty-text">${text}</p>
      <a href="${ctaHref}" class="btn-voter-cta">${ctaLabel}</a>
      ${secondaryHref ? `<a href="${secondaryHref}" class="voter-link-secondary">${secondaryLabel}</a>` : ''}
    </div>`;
}

function renderErrorState(container, message) {
  container.innerHTML = `
    <div class="voter-empty-state" role="alert">
      <div class="voter-empty-icon" style="background:linear-gradient(135deg,#ffe3e3,#ffc9c9);color:#c92a2a;" aria-hidden="true">
        <i class="fa-solid fa-triangle-exclamation"></i>
      </div>
      <p class="voter-empty-title">Unable to load categories</p>
      <p class="voter-empty-text">${message}</p>
      <button type="button" class="btn btn-outline-primary" onclick="location.reload()">Try again</button>
    </div>`;
}

document.addEventListener('DOMContentLoaded', async () => {
  const voterId = await bootstrapVoterSession();
  if (!voterId) return;

  const categoryList = document.getElementById('categoryList');
  if (!categoryList) return;

  categoryList.innerHTML = '';
  for (let i = 0; i < 6; i++) {
    const sk = document.createElement('div');
    sk.className = 'voter-skeleton';
    sk.setAttribute('aria-hidden', 'true');
    categoryList.appendChild(sk);
  }

  fetch('get_all_categories.php')
    .then(response => {
      if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
      return response.json();
    })
    .then(data => {
      if (data.status === 'success' && Array.isArray(data.categories)) {
        if (data.event_id) {
          localStorage.setItem('current_event_id', data.event_id);
        }

        categoryList.innerHTML = '';

        if (data.categories.length === 0) {
          renderEmptyState(categoryList, {
            title: 'No categories available',
            text: 'There are no active award categories at the moment. Please check back later or contact the organizers.',
            ctaHref: 'index.php',
            ctaLabel: 'Return to home',
          });
          return;
        }

        const voterType = localStorage.getItem('voter_type') || 'new';
        const allowedCategoryIds = JSON.parse(localStorage.getItem('unanswered_categories') || '[]');

        let visibleCategories = data.categories;
        if (voterType === 'existing' && allowedCategoryIds.length > 0) {
          visibleCategories = data.categories.filter(cat =>
            allowedCategoryIds.includes(String(cat.id))
          );
        }

        if (visibleCategories.length === 0) {
          renderEmptyState(categoryList, {
            title: 'All categories completed',
            text: 'You have no remaining categories to answer here. Head to the summary page to review and cast your votes.',
            ctaHref: 'summarypoll.php',
            ctaLabel: 'Go to Vote Summary',
            secondaryHref: 'summarypoll.php',
            secondaryLabel: '',
          });
          const secondary = categoryList.querySelector('.voter-link-secondary');
          if (secondary) secondary.remove();
          return;
        }

        if (visibleCategories.length > 4) {
          categoryList.classList.add('category-grid--many');
        } else {
          categoryList.classList.remove('category-grid--many');
        }

        visibleCategories.forEach(category => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'category-card category-item';
          btn.setAttribute('role', 'listitem');
          const icon = getCategoryIcon(category.name);
          btn.innerHTML = `
            <span class="category-card-icon" aria-hidden="true"><i class="fa-solid ${icon}"></i></span>
            <span class="category-card-label">${escapeHtml(category.name)}</span>
            <i class="fa-solid fa-chevron-right category-card-arrow" aria-hidden="true"></i>`;
          btn.addEventListener('click', () => {
            localStorage.setItem('selected_category_id', category.id);
            localStorage.setItem('selected_category_name', category.name);
            const eventId = localStorage.getItem('current_event_id') || '1';
            window.location.href = `selected-category.php?category_id=${category.id}&event_id=${eventId}`;
          });
          categoryList.appendChild(btn);
        });

        if (localStorage.getItem('from_summary') === 'true') {
          const wrap = document.createElement('div');
          wrap.className = 'mt-3 text-center';
          wrap.style.gridColumn = '1 / -1';
          wrap.innerHTML = `<a href="summarypoll.php" class="voter-link-secondary"><i class="fa-solid fa-list-check me-1"></i> Back to Vote Summary</a>`;
          categoryList.appendChild(wrap);
        }
      } else {
        throw new Error(data.message || 'Failed to load categories.');
      }
    })
    .catch(error => {
      console.error('Error loading categories:', error);
      renderErrorState(categoryList, 'Please check your connection and try again.');
    });
});

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function getCategoryIcon(name) {
  const n = String(name || '').toLowerCase();
  if (n.includes('food') || n.includes('restaurant') || n.includes('dining')) return 'fa-utensils';
  if (n.includes('retail') || n.includes('shop') || n.includes('store')) return 'fa-store';
  if (n.includes('service') || n.includes('business')) return 'fa-briefcase';
  if (n.includes('feel') || n.includes('wellness') || n.includes('health')) return 'fa-heart';
  if (n.includes('tech') || n.includes('digital')) return 'fa-microchip';
  if (n.includes('travel') || n.includes('tour')) return 'fa-plane';
  return 'fa-award';
}
